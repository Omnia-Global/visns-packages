<?php

namespace Visnsstudio\VisnsPackages\Services\ReportSemantics;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compiles a report definition (schema version 2) into a query.
 *
 * The compiler never trusts the definition for anything but shape. Every
 * entity, field and relation is resolved through the {@see SemanticModel},
 * which is the allowlist: a path that is not published cannot be reached, and
 * no table or column name from the request payload ever reaches SQL. Values
 * are always bound.
 *
 * Target platform is MySQL 8 (JSON_EXTRACT/JSON_UNQUOTE, CAST(... AS
 * DECIMAL)). The non-JSON parts of the generated SQL are portable and are
 * exercised against SQLite in the test suite.
 *
 * @see docs/report-semantics.md
 */
class QueryCompiler
{
    /**
     * Rows returned when the definition does not ask for a limit.
     */
    const DEFAULT_LIMIT = 100;

    /**
     * Hard ceiling for an interactive execution.
     */
    const MAX_LIMIT = 10000;

    /**
     * Aggregate functions the definition may use.
     */
    const AGGREGATES = ['sum', 'count', 'avg', 'min', 'max'];

    /**
     * Parameter types a definition may declare.
     */
    const PARAMETER_TYPES = ['text', 'number', 'date', 'date_range', 'enum'];

    /**
     * Precision used when a JSON-extracted value is compared or sorted as a
     * number. 14 integer-ish digits with 2 decimals covers money comfortably.
     */
    const DECIMAL_CAST = 'DECIMAL(14,2)';

    /**
     * LIKE escape character for contains / not_contains.
     *
     * Deliberately not a backslash: MySQL's own backslash handling in string
     * literals depends on the NO_BACKSLASH_ESCAPES mode, and SQLite gives a
     * backslash no special meaning at all unless an ESCAPE clause says so.
     * A `!` behaves identically on both once it is declared.
     */
    const LIKE_ESCAPE = '!';

    /** @var SemanticModel */
    protected $model;

    /** @var SchemaInspector */
    protected $inspector;

    /**
     * Connection name, or null for the application default.
     *
     * @var string|null
     */
    protected $connectionName;

    /**
     * Joins collected while resolving paths: relation path => descriptor.
     *
     * Insertion order is join order, and because a path is always resolved
     * left to right a parent is always registered before its child.
     *
     * @var array<string, array>
     */
    protected $joins = [];

    /**
     * Declared parameters of the definition being compiled, keyed by id.
     *
     * @var array<string, array>
     */
    protected $parameterDefs = [];

    /**
     * Runtime parameter values supplied with the request.
     *
     * @var array<string, mixed>
     */
    protected $parameterValues = [];

    /**
     * The entity the current definition reports on.
     *
     * @var array|null
     */
    protected $baseEntity = null;

    /**
     * Counter behind the generated `c0`, `c1`, ... select aliases.
     *
     * @var int
     */
    protected $aliasSeq = 0;

    /**
     * @param SemanticModel|null $model
     * @param SchemaInspector|null $inspector
     * @param string|null $connection
     */
    public function __construct(
        SemanticModel $model = null,
        SchemaInspector $inspector = null,
        $connection = null
    ) {
        $this->model = $model ?: SemanticModel::fromConfig();
        $this->inspector = $inspector ?: new SchemaInspector();
        $this->connectionName =
            $connection ??
            (function_exists('config')
                ? config('visns-packages.report_semantics.connection')
                : null);
    }

    /**
     * Whether a payload is a schema-version-2 (semantic) definition.
     *
     * Used by the controller to route a request: anything this returns false
     * for keeps running down the untouched v1 path.
     *
     * @param mixed $definition
     * @return bool
     */
    public static function isSemanticDefinition($definition): bool
    {
        if (!is_array($definition)) {
            return false;
        }

        $version = $definition['schema_version'] ?? null;

        if (is_numeric($version) && (int) $version >= 2) {
            return true;
        }

        // A definition carrying `entity` is semantic even without the
        // version: v1 payloads describe themselves with `mainTable`.
        return isset($definition['entity']) &&
            is_string($definition['entity']) &&
            $definition['entity'] !== '' &&
            !isset($definition['mainTable']);
    }

    /**
     * The model this compiler resolves against.
     *
     * @return SemanticModel
     */
    public function model(): SemanticModel
    {
        return $this->model;
    }

    /**
     * Compile a definition into a query plus the metadata needed to shape
     * the rows.
     *
     * @param array $definition
     * @param array $parameters Runtime parameter values, id => value.
     * @param int|null $limit
     * @param int $offset
     * @param int|null $maxLimit Ceiling override (export uses a larger one).
     * @return array{query: \Illuminate\Database\Query\Builder, outputs: array, grouped: bool, labels: array}
     *
     * @throws SemanticException on an invalid definition.
     */
    public function compile(
        array $definition,
        array $parameters = [],
        $limit = null,
        int $offset = 0,
        $maxLimit = null
    ): array {
        $this->joins = [];
        $this->aliasSeq = 0;
        $this->parameterValues = is_array($parameters) ? $parameters : [];
        $this->parameterDefs = $this->readParameterDeclarations($definition);

        $entityName = $definition['entity'] ?? null;

        if (!is_string($entityName) || $entityName === '') {
            throw SemanticException::forPath(
                'entity',
                'The report definition must name an entity.'
            );
        }

        $this->baseEntity = $this->model->entity($entityName, 'entity');

        $outputs = $this->resolveOutputs($definition['fields'] ?? []);
        $groupBy = $this->resolveGroupBy(
            $definition['groupBy'] ?? [],
            $outputs
        );

        $query = $this->connection()->table($this->baseEntity['table']);

        // Filters are resolved before the joins are attached so that a
        // filter on a related field registers its join too.
        $filters = $definition['filters'] ?? null;

        $selects = [];

        foreach ($outputs as $output) {
            $selects[] = $output['sql'] . ' as ' . $this->wrap($output['alias']);
        }

        $groupExpressions = [];

        foreach ($groupBy as $path) {
            $resolved = $this->resolvePath($path, "groupBy.{$path}");
            $groupExpressions[$path] = $this->expression($resolved);
        }

        // Base table only; joined tables are constrained in their ON clause.
        $this->applySoftDeleteConstraints($query);

        if (is_array($filters) && !empty($filters)) {
            // Wrapped in a nested group so an OR at the root cannot escape
            // the soft-delete constraint already on the query.
            //
            // This runs BEFORE attachJoins(): resolving a filter path is
            // what registers the join it needs, and a filter on a relation
            // that nothing else selects would otherwise reference an alias
            // that was never joined. Clause order in the builder does not
            // affect the compiled SQL - joins and wheres are assembled from
            // separate lists, with separate binding buckets.
            $query->where(function ($q) use ($filters) {
                $this->applyGroup($q, $filters, 'filters');
            });
        }

        $sorts = $this->resolveSorts(
            $definition['sort'] ?? [],
            $outputs,
            $groupBy
        );

        // Every path has now been resolved, so the join list is complete.
        $this->attachJoins($query);

        $query->selectRaw(implode(', ', $selects), $this->selectBindings($outputs));

        foreach ($groupExpressions as $expression) {
            $query->groupByRaw($expression);
        }

        foreach ($sorts as $sort) {
            $query->orderByRaw($sort['sql'] . ' ' . $sort['dir']);
        }

        $limit = $this->normaliseLimit($limit, $maxLimit);
        $offset = max(0, (int) $offset);

        $query->limit($limit)->offset($offset);

        $labels = [];

        foreach ($outputs as $output) {
            $labels[$output['key']] = $output['label'];
        }

        return [
            'query' => $query,
            'outputs' => $outputs,
            'grouped' => !empty($groupExpressions),
            'labels' => $labels,
        ];
    }

    /**
     * Compile, run, and shape the result.
     *
     * @param array $definition
     * @param array $parameters
     * @param int|null $limit
     * @param int $offset
     * @param int|null $maxLimit
     * @return array{data: array, total: int, sql: string, labels: array, columns: array}
     *
     * @throws SemanticException on an invalid definition.
     */
    public function execute(
        array $definition,
        array $parameters = [],
        $limit = null,
        int $offset = 0,
        $maxLimit = null
    ): array {
        $compiled = $this->compile(
            $definition,
            $parameters,
            $limit,
            $offset,
            $maxLimit
        );

        $query = $compiled['query'];
        $rows = $query->get();

        return [
            'data' => $this->shapeRows($rows, $compiled['outputs']),
            'total' => $this->countTotal($query, $compiled['grouped']),
            'sql' => $query->toSql(),
            'labels' => $compiled['labels'],
            'columns' => array_keys($compiled['labels']),
        ];
    }

    /* ------------------------------------------------------------------
     | Output (select list) resolution
     | ------------------------------------------------------------------ */

    /**
     * Turn the definition's `fields` list into select descriptors.
     *
     * Each descriptor carries the row key exactly as the client asked for it
     * (`firstname`, `adviser.name`, `Total fees`), the generated SQL alias it
     * is fetched under, and the type used to shape the value afterwards.
     *
     * @param mixed $fields
     * @return array<int, array>
     */
    protected function resolveOutputs($fields): array
    {
        if (!is_array($fields) || empty($fields)) {
            throw SemanticException::forPath(
                'fields',
                'The report definition must select at least one field.'
            );
        }

        $outputs = [];
        $seen = [];

        foreach (array_values($fields) as $index => $entry) {
            $path = "fields[{$index}]";

            if (is_string($entry)) {
                // Convenience: a bare string is a plain field.
                $entry = ['field' => $entry];
            }

            if (!is_array($entry)) {
                throw SemanticException::forPath(
                    $path,
                    'Each selected field must be an object.'
                );
            }

            $agg = isset($entry['agg'])
                ? strtolower((string) $entry['agg'])
                : null;

            if ($agg !== null && !in_array($agg, self::AGGREGATES, true)) {
                throw SemanticException::forPath(
                    $path,
                    "Unknown aggregate [{$agg}]. Use one of: " .
                        implode(', ', self::AGGREGATES) .
                        '.'
                );
            }

            $fieldPath = isset($entry['field'])
                ? (string) $entry['field']
                : null;

            if ($fieldPath === null || $fieldPath === '') {
                // COUNT is the only aggregate that works without a field.
                if ($agg === 'count') {
                    $outputs[] = $this->countStarOutput($entry, $path, $seen);
                    continue;
                }

                throw SemanticException::forPath(
                    $path,
                    'A selected field must name a field.'
                );
            }

            $resolved = $this->resolvePath($fieldPath, $path);
            $field = $resolved['field'];
            $expression = $this->expression($resolved);

            if ($agg === null) {
                $key = $this->outputKey($entry, $fieldPath, $path, $seen);

                $outputs[] = [
                    'key' => $key,
                    'label' => $this->stringOr($entry['label'] ?? null, $key),
                    'alias' => $this->nextAlias(),
                    'sql' => $expression,
                    'type' => $field['type'],
                    'sentinels' => $this->sentinels($field),
                    'agg' => null,
                    'path' => $fieldPath,
                ];

                continue;
            }

            $this->assertAggregatable($agg, $field, $fieldPath, $path);

            $key = $this->outputKey(
                $entry,
                $agg . '(' . $fieldPath . ')',
                $path,
                $seen
            );

            $outputs[] = [
                'key' => $key,
                'label' => $this->stringOr($entry['label'] ?? null, $key),
                'alias' => $this->nextAlias(),
                'sql' => strtoupper($agg) . '(' . $expression . ')',
                'type' => $this->aggregateType($agg, $field['type']),
                // An aggregate of sentinel values is meaningless to unmap,
                // so sentinels are not applied to aggregate results.
                'sentinels' => [],
                'agg' => $agg,
                'path' => $fieldPath,
            ];
        }

        return $outputs;
    }

    /**
     * `{"agg": "count"}` with no field: a plain row count.
     *
     * @param array $entry
     * @param string $path
     * @param array $seen
     * @return array
     */
    protected function countStarOutput(array $entry, string $path, array &$seen): array
    {
        $key = $this->outputKey($entry, 'count(*)', $path, $seen);

        return [
            'key' => $key,
            'label' => $this->stringOr($entry['label'] ?? null, $key),
            'alias' => $this->nextAlias(),
            'sql' => 'COUNT(*)',
            'type' => 'number',
            'sentinels' => [],
            'agg' => 'count',
            'path' => null,
        ];
    }

    /**
     * The key this output appears under in every returned row.
     *
     * An explicit `label` wins - that is what the contract promises for
     * aggregates, and it is honoured for plain fields too so a report can
     * rename a column. Otherwise the field path is used verbatim.
     *
     * @param array $entry
     * @param string $fallback
     * @param string $path
     * @param array $seen
     * @return string
     */
    protected function outputKey(
        array $entry,
        string $fallback,
        string $path,
        array &$seen
    ): string {
        $key = $this->stringOr($entry['label'] ?? null, $fallback);

        if (isset($seen[$key])) {
            throw SemanticException::forPath(
                $path,
                "Duplicate column [{$key}]. Give one of them a distinct label."
            );
        }

        $seen[$key] = true;

        return $key;
    }

    /**
     * @param string $agg
     * @param array $field
     * @param string $fieldPath
     * @param string $path
     * @return void
     */
    protected function assertAggregatable(
        string $agg,
        array $field,
        string $fieldPath,
        string $path
    ): void {
        if (!in_array($agg, ['sum', 'avg'], true)) {
            // count/min/max are meaningful for every type.
            return;
        }

        $numeric = in_array(
            $field['type'],
            SemanticModel::NUMERIC_TYPES,
            true
        );

        if ($numeric || $field['summable']) {
            return;
        }

        throw SemanticException::forPath(
            $path,
            "[{$fieldPath}] cannot be aggregated with {$agg}: it is not a numeric field."
        );
    }

    /**
     * The value type an aggregate produces.
     *
     * count is always a whole number; sum/avg are numeric regardless of the
     * source type; min/max keep the field's own type so a min over a date
     * field still serialises as a date.
     *
     * @param string $agg
     * @param string $fieldType
     * @return string
     */
    protected function aggregateType(string $agg, string $fieldType): string
    {
        if ($agg === 'count') {
            return 'number';
        }

        if ($agg === 'sum' || $agg === 'avg') {
            return in_array($fieldType, SemanticModel::NUMERIC_TYPES, true)
                ? $fieldType
                : 'number';
        }

        return $fieldType;
    }

    /* ------------------------------------------------------------------
     | Path resolution and SQL expressions
     | ------------------------------------------------------------------ */

    /**
     * Resolve a dot-path (`fee_amount`, `adviser.name`, `adviser.team.name`)
     * against the registry, registering a join for every hop.
     *
     * @param string $path
     * @param string $reportedPath Path quoted back in any error.
     * @return array{field: array, alias: string, entity: string, path: string}
     *
     * @throws SemanticException for an unknown entity, relation or field.
     */
    protected function resolvePath(string $path, string $reportedPath): array
    {
        $segments = array_filter(explode('.', trim($path)), function ($s) {
            return $s !== '';
        });
        $segments = array_values($segments);

        if (empty($segments)) {
            throw SemanticException::forPath(
                $reportedPath,
                'Empty field path.'
            );
        }

        $fieldId = array_pop($segments);

        $entityName = $this->baseEntity['name'];
        $alias = $this->baseEntity['table'];
        $relationPath = '';

        foreach ($segments as $segment) {
            $relation = $this->model->relation(
                $entityName,
                $segment,
                $path
            );

            $relationPath =
                $relationPath === ''
                    ? $segment
                    : $relationPath . '.' . $segment;

            $target = $this->model->entity($relation['entity'], $path);
            $childAlias = $this->joinAlias($relationPath);

            if (!isset($this->joins[$relationPath])) {
                $this->joins[$relationPath] = [
                    'relation' => $relation,
                    'parent_alias' => $alias,
                    'alias' => $childAlias,
                    'entity' => $target,
                ];
            }

            $entityName = $relation['entity'];
            $alias = $childAlias;
        }

        return [
            'field' => $this->model->field($entityName, $fieldId, $path),
            'alias' => $alias,
            'entity' => $entityName,
            'path' => $path,
        ];
    }

    /**
     * The SQL alias a joined table gets.
     *
     * Aliasing per relation *path* (not per table) is what allows the same
     * entity to appear twice through different relations - `adviser.name`
     * and `referrer.name` both land on `users` but never collide.
     *
     * @param string $relationPath
     * @return string
     */
    protected function joinAlias(string $relationPath): string
    {
        $alias = 'rel_' . str_replace('.', '__', $relationPath);

        // MySQL identifiers cap at 64 characters.
        if (strlen($alias) > 60) {
            $alias = 'rel_' . substr(md5($relationPath), 0, 16);
        }

        return $alias;
    }

    /**
     * The SQL expression for a resolved field, cast for its type.
     *
     * Plain columns are used as they stand - the database already types
     * them. A JSON-extracted value is always a string, so numeric and date
     * types are cast, which is what makes comparison and sorting behave.
     *
     * @param array $resolved
     * @return string
     */
    protected function expression(array $resolved): string
    {
        $field = $resolved['field'];
        $raw = $this->rawExpression($resolved);

        if ($field['json'] === null) {
            return $raw;
        }

        if (in_array($field['type'], SemanticModel::NUMERIC_TYPES, true)) {
            return 'CAST(' . $raw . ' AS ' . self::DECIMAL_CAST . ')';
        }

        if ($field['type'] === 'date') {
            return 'CAST(' . $raw . ' AS DATE)';
        }

        if ($field['type'] === 'datetime') {
            return 'CAST(' . $raw . ' AS DATETIME)';
        }

        return $raw;
    }

    /**
     * The uncast expression: the column, or the raw JSON extraction.
     *
     * Emptiness tests use this rather than {@see expression()} - casting an
     * empty string to DECIMAL yields 0, which would make `is_empty`
     * indistinguishable from "equals zero".
     *
     * @param array $resolved
     * @return string
     */
    protected function rawExpression(array $resolved): string
    {
        $field = $resolved['field'];

        if ($field['json'] === null) {
            return $this->wrap($resolved['alias'] . '.' . $field['column']);
        }

        // The path is validated at registry load against a strict pattern,
        // so it cannot break out of the string literal.
        return "JSON_UNQUOTE(JSON_EXTRACT(" .
            $this->wrap($resolved['alias'] . '.' . $field['json']['column']) .
            ", '" .
            $field['json']['path'] .
            "'))";
    }

    /**
     * Values of a field that mean "no value".
     *
     * A JSON null extracts as the four-character string `null`, so it is
     * always treated as a sentinel for JSON-backed fields on top of anything
     * the registry declares.
     *
     * @param array $field
     * @return array<int, string>
     */
    protected function sentinels(array $field): array
    {
        $sentinels = array_map('strval', $field['null_sentinels']);

        if ($field['json'] !== null && !in_array('null', $sentinels, true)) {
            $sentinels[] = 'null';
        }

        return array_values(array_unique($sentinels));
    }

    /* ------------------------------------------------------------------
     | Joins and soft deletes
     | ------------------------------------------------------------------ */

    /**
     * Attach every collected relation as a LEFT JOIN.
     *
     * LEFT, always: a report on clients must not lose the clients that have
     * no adviser just because a column from `adviser` was selected. That is
     * also why the joined table's soft-delete and zero-FK conditions live in
     * the ON clause - moved to WHERE they would silently make it an inner
     * join.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return void
     */
    protected function attachJoins($query): void
    {
        foreach ($this->joins as $join) {
            $relation = $join['relation'];
            $alias = $join['alias'];
            $parentAlias = $join['parent_alias'];
            $target = $join['entity'];

            if ($relation['type'] === 'belongs_to') {
                $left = $parentAlias . '.' . $relation['foreign_key'];
                $right = $alias . '.' . $relation['owner_key'];
                $owningKey = $left;
            } else {
                // has_one / has_many
                $left = $parentAlias . '.' . $relation['local_key'];
                $right = $alias . '.' . $relation['foreign_key'];
                $owningKey = $left;
            }

            $zeroIsNull = $relation['zero_is_null'];
            $softDelete = $this->entityHasSoftDeletes($target);

            $query->leftJoin(
                $target['table'] . ' as ' . $alias,
                function ($join) use (
                    $left,
                    $right,
                    $zeroIsNull,
                    $owningKey,
                    $softDelete,
                    $alias
                ) {
                    $join->on($left, '=', $right);

                    if ($zeroIsNull) {
                        // A 0 foreign key is this application's "no
                        // relation" marker; without this it would join to
                        // whatever row happens to have id 0.
                        $join->whereRaw($this->wrap($owningKey) . ' <> 0');
                    }

                    if ($softDelete) {
                        $join->whereNull($alias . '.deleted_at');
                    }
                }
            );
        }
    }

    /**
     * Exclude soft-deleted rows of the base table.
     *
     * Joined tables are handled in their ON clause instead - see
     * {@see attachJoins()}. This mirrors the Wave-1
     * `applySoftDeleteConstraints()` helper on the controller: the report
     * runs on the query builder, so Eloquent's SoftDeletes scope never fires
     * and trashed rows would otherwise show up in every report.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return void
     */
    protected function applySoftDeleteConstraints($query): void
    {
        if ($this->entityHasSoftDeletes($this->baseEntity)) {
            $query->whereNull($this->baseEntity['table'] . '.deleted_at');
        }
    }

    /**
     * Whether an entity's table carries `deleted_at`.
     *
     * An explicit `soft_deletes` in the registry is authoritative and skips
     * the schema round trip; otherwise the column listing decides.
     *
     * @param array $entity
     * @return bool
     */
    protected function entityHasSoftDeletes(array $entity): bool
    {
        if ($entity['soft_deletes'] !== null) {
            return (bool) $entity['soft_deletes'];
        }

        return $this->inspector->hasDeletedAt($entity['table']);
    }

    /* ------------------------------------------------------------------
     | Filters
     | ------------------------------------------------------------------ */

    /**
     * Apply a filter group - `{"op": "and"|"or", "items": [...]}`.
     *
     * A bare list of leaves is accepted as an implicit AND group, which
     * keeps hand-written definitions readable.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $group
     * @param string $path
     * @return void
     */
    protected function applyGroup($query, array $group, string $path): void
    {
        $op = strtolower((string) ($group['op'] ?? 'and'));

        if (!in_array($op, ['and', 'or'], true)) {
            throw SemanticException::forPath(
                $path,
                "Unknown filter group operator [{$op}]. Use 'and' or 'or'."
            );
        }

        $items = $group['items'] ?? null;

        if ($items === null && $this->isList($group)) {
            // The whole group was given as a plain list of leaves.
            $items = $group;
        }

        if (!is_array($items)) {
            throw SemanticException::forPath(
                $path,
                'A filter group must contain an items array.'
            );
        }

        foreach (array_values($items) as $index => $item) {
            $itemPath = "{$path}.items[{$index}]";

            if (!is_array($item)) {
                throw SemanticException::forPath(
                    $itemPath,
                    'A filter must be an object.'
                );
            }

            if (isset($item['items']) || isset($item['op'])) {
                $query->where(
                    function ($nested) use ($item, $itemPath) {
                        $this->applyGroup($nested, $item, $itemPath);
                    },
                    null,
                    null,
                    $op
                );

                continue;
            }

            $this->applyLeaf($query, $item, $op, $itemPath);
        }
    }

    /**
     * Apply a single `{field, operator, value|param}` condition.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $leaf
     * @param string $boolean 'and' | 'or'
     * @param string $path
     * @return void
     */
    protected function applyLeaf(
        $query,
        array $leaf,
        string $boolean,
        string $path
    ): void {
        $fieldPath = isset($leaf['field']) ? (string) $leaf['field'] : '';

        if ($fieldPath === '') {
            throw SemanticException::forPath(
                $path,
                'A filter must name a field.'
            );
        }

        $resolved = $this->resolvePath($fieldPath, $path);
        $field = $resolved['field'];
        $type = $field['type'];

        $operator = isset($leaf['operator'])
            ? strtolower((string) $leaf['operator'])
            : '';

        $allowed = SemanticModel::operatorsFor($type);

        if (!in_array($operator, $allowed, true)) {
            throw SemanticException::forPath(
                $path,
                "Operator [{$operator}] cannot be used on [{$fieldPath}] (type {$type}). Allowed: " .
                    implode(', ', $allowed) .
                    '.'
            );
        }

        $needsValue = !in_array(
            $operator,
            ['is_empty', 'not_empty', 'is_true', 'is_false'],
            true
        );

        $value = null;

        if ($needsValue) {
            $value = $this->leafValue($leaf, $field, $operator, $path);

            if ($value === self::skip()) {
                // An optional parameter with no value supplied: the whole
                // condition drops out rather than matching nothing.
                return;
            }
        }

        $expr = $this->expression($resolved);
        $rawExpr = $this->rawExpression($resolved);
        $sentinels = $this->sentinels($field);

        switch ($operator) {
            case 'equals':
                if ($this->isDateType($type)) {
                    [$from, $to] = $this->dayBounds($value, $type, $path);
                    $query->whereRaw(
                        "{$expr} between ? and ?",
                        [$from, $to],
                        $boolean
                    );
                    break;
                }

                $query->whereRaw("{$expr} = ?", [$value], $boolean);
                break;

            case 'not_equals':
                // NULL <> 'x' is NULL in SQL, so a row with no value would
                // otherwise disappear from a "not equal to" filter.
                $query->whereRaw(
                    "({$expr} <> ? or {$expr} is null)",
                    [$value],
                    $boolean
                );
                break;

            case 'contains':
                $query->whereRaw(
                    "{$expr} like ? escape '" . self::LIKE_ESCAPE . "'",
                    ['%' . $this->escapeLike((string) $value) . '%'],
                    $boolean
                );
                break;

            case 'not_contains':
                $query->whereRaw(
                    "({$expr} not like ? escape '" .
                        self::LIKE_ESCAPE .
                        "' or {$expr} is null)",
                    ['%' . $this->escapeLike((string) $value) . '%'],
                    $boolean
                );
                break;

            case 'gt':
                $query->whereRaw("{$expr} > ?", [$value], $boolean);
                break;

            case 'gte':
                $query->whereRaw("{$expr} >= ?", [$value], $boolean);
                break;

            case 'lt':
                $query->whereRaw("{$expr} < ?", [$value], $boolean);
                break;

            case 'lte':
                $query->whereRaw("{$expr} <= ?", [$value], $boolean);
                break;

            case 'before':
                $query->whereRaw(
                    "{$expr} < ?",
                    [$this->dayBounds($value, $type, $path)[0]],
                    $boolean
                );
                break;

            case 'after':
                $query->whereRaw(
                    "{$expr} > ?",
                    [$this->dayBounds($value, $type, $path)[1]],
                    $boolean
                );
                break;

            case 'between':
                $bounds = $this->betweenBounds($value, $type, $path);
                $query->whereRaw(
                    "{$expr} between ? and ?",
                    $bounds,
                    $boolean
                );
                break;

            case 'in':
            case 'not_in':
                $values = $this->listValue($value, $field, $path);
                $placeholders = implode(
                    ', ',
                    array_fill(0, count($values), '?')
                );

                $sql =
                    $operator === 'in'
                        ? "{$expr} in ({$placeholders})"
                        : "({$expr} not in ({$placeholders}) or {$expr} is null)";

                $query->whereRaw($sql, $values, $boolean);
                break;

            case 'is_true':
                $query->whereRaw("{$expr} = 1", [], $boolean);
                break;

            case 'is_false':
                // A null boolean reads as "not true" to a business user.
                $query->whereRaw(
                    "({$expr} = 0 or {$expr} is null)",
                    [],
                    $boolean
                );
                break;

            case 'is_empty':
                $query->whereRaw(
                    $this->emptinessSql($rawExpr, $type, $sentinels, true),
                    $sentinels,
                    $boolean
                );
                break;

            case 'not_empty':
                $query->whereRaw(
                    $this->emptinessSql($rawExpr, $type, $sentinels, false),
                    $sentinels,
                    $boolean
                );
                break;

            default:
                // Unreachable: the operator was checked against the type's
                // allowlist above. Kept so a future operator cannot slip
                // through as a silent no-op.
                throw SemanticException::forPath(
                    $path,
                    "Unsupported operator [{$operator}]."
                );
        }
    }

    /**
     * SQL for is_empty / not_empty.
     *
     * "Empty" is NULL, plus the empty string for text-ish types, plus every
     * declared null sentinel. The empty string is deliberately not tested
     * for numeric and date types: MySQL would coerce it and match zero.
     *
     * @param string $expr Uncast expression.
     * @param string $type
     * @param array $sentinels
     * @param bool $empty true for is_empty, false for not_empty
     * @return string
     */
    protected function emptinessSql(
        string $expr,
        string $type,
        array $sentinels,
        bool $empty
    ): string {
        $textish = in_array($type, ['text', 'enum'], true);
        $parts = [];

        if ($empty) {
            $parts[] = "{$expr} is null";

            if ($textish) {
                $parts[] = "{$expr} = ''";
            }

            foreach ($sentinels as $ignored) {
                $parts[] = "{$expr} = ?";
            }

            return '(' . implode(' or ', $parts) . ')';
        }

        $parts[] = "{$expr} is not null";

        if ($textish) {
            $parts[] = "{$expr} <> ''";
        }

        foreach ($sentinels as $ignored) {
            $parts[] = "{$expr} <> ?";
        }

        return '(' . implode(' and ', $parts) . ')';
    }

    /* ------------------------------------------------------------------
     | Values and parameters
     | ------------------------------------------------------------------ */

    /**
     * Sentinel returned by {@see leafValue()} for a skipped condition.
     *
     * @return object
     */
    protected static function skip()
    {
        static $skip = null;

        if ($skip === null) {
            $skip = new \stdClass();
        }

        return $skip;
    }

    /**
     * The value a condition compares against - literal or parameter.
     *
     * @param array $leaf
     * @param array $field
     * @param string $operator
     * @param string $path
     * @return mixed The value, or {@see skip()} when an optional parameter
     *               was not supplied.
     */
    protected function leafValue(
        array $leaf,
        array $field,
        string $operator,
        string $path
    ) {
        if (isset($leaf['param'])) {
            $id = (string) $leaf['param'];

            if (!isset($this->parameterDefs[$id])) {
                throw SemanticException::forPath(
                    $path,
                    "Filter refers to parameter [{$id}], which the report does not declare."
                );
            }

            $declaration = $this->parameterDefs[$id];
            $supplied = $this->parameterValues[$id] ?? null;

            if ($this->isBlank($supplied)) {
                if ($declaration['required']) {
                    throw SemanticException::forPath(
                        "parameters.{$id}",
                        "The [{$declaration['label']}] parameter is required."
                    );
                }

                return self::skip();
            }

            $supplied = $this->validateParameterValue(
                $supplied,
                $declaration,
                $id
            );

            return $this->coerceValue($supplied, $field, $operator, $path);
        }

        if (!array_key_exists('value', $leaf)) {
            throw SemanticException::forPath(
                $path,
                "Operator [{$operator}] needs a value."
            );
        }

        return $this->coerceValue($leaf['value'], $field, $operator, $path);
    }

    /**
     * Read and validate the definition's `parameters` block.
     *
     * @param array $definition
     * @return array<string, array>
     */
    protected function readParameterDeclarations(array $definition): array
    {
        $declared = $definition['parameters'] ?? [];

        if (!is_array($declared)) {
            throw SemanticException::forPath(
                'parameters',
                'The parameters block must be a list.'
            );
        }

        $result = [];

        foreach (array_values($declared) as $index => $parameter) {
            $path = "parameters[{$index}]";

            if (!is_array($parameter) || !isset($parameter['id'])) {
                throw SemanticException::forPath(
                    $path,
                    'Each parameter must declare an id.'
                );
            }

            $id = (string) $parameter['id'];
            $type = strtolower(
                (string) ($parameter['type'] ?? 'text')
            );

            if (!in_array($type, self::PARAMETER_TYPES, true)) {
                throw SemanticException::forPath(
                    $path,
                    "Unknown parameter type [{$type}]. Use one of: " .
                        implode(', ', self::PARAMETER_TYPES) .
                        '.'
                );
            }

            $result[$id] = [
                'id' => $id,
                'label' => $this->stringOr($parameter['label'] ?? null, $id),
                'type' => $type,
                'required' => (bool) ($parameter['required'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Check a supplied parameter value against its declared type.
     *
     * @param mixed $value
     * @param array $declaration
     * @param string $id
     * @return mixed
     */
    protected function validateParameterValue($value, array $declaration, string $id)
    {
        $path = "parameters.{$id}";
        $label = $declaration['label'];

        switch ($declaration['type']) {
            case 'number':
                if (!is_numeric($value)) {
                    throw SemanticException::forPath(
                        $path,
                        "The [{$label}] parameter must be a number."
                    );
                }

                return $value + 0;

            case 'date':
                if (!$this->looksLikeDate($value)) {
                    throw SemanticException::forPath(
                        $path,
                        "The [{$label}] parameter must be a date."
                    );
                }

                return $value;

            case 'date_range':
                if (!is_array($value) || count($value) !== 2) {
                    throw SemanticException::forPath(
                        $path,
                        "The [{$label}] parameter must be a two-element [from, to] range."
                    );
                }

                $value = array_values($value);

                foreach ($value as $bound) {
                    if (!$this->looksLikeDate($bound)) {
                        throw SemanticException::forPath(
                            $path,
                            "The [{$label}] parameter must contain two dates."
                        );
                    }
                }

                return $value;

            case 'enum':
            case 'text':
            default:
                // Enum membership is checked against the field the
                // parameter is actually used on, in coerceValue().
                return $value;
        }
    }

    /**
     * Coerce and validate a comparison value for a field's type.
     *
     * @param mixed $value
     * @param array $field
     * @param string $operator
     * @param string $path
     * @return mixed
     */
    protected function coerceValue($value, array $field, string $operator, string $path)
    {
        // Multi-value operators validate their members individually.
        if (in_array($operator, ['in', 'not_in', 'between'], true)) {
            return $value;
        }

        return $this->coerceScalar($value, $field, $path);
    }

    /**
     * @param mixed $value
     * @param array $field
     * @param string $path
     * @return mixed
     */
    protected function coerceScalar($value, array $field, string $path)
    {
        if (is_array($value) || is_object($value)) {
            throw SemanticException::forPath(
                $path,
                "The value for [{$field['id']}] must be a single value."
            );
        }

        switch ($field['type']) {
            case 'number':
            case 'money':
            case 'percent':
                if (!is_numeric($value)) {
                    throw SemanticException::forPath(
                        $path,
                        "The value for [{$field['label']}] must be a number."
                    );
                }

                return $value + 0;

            case 'date':
            case 'datetime':
                if (!$this->looksLikeDate($value)) {
                    throw SemanticException::forPath(
                        $path,
                        "The value for [{$field['label']}] must be a date."
                    );
                }

                return (string) $value;

            case 'enum':
                $key = (string) $value;

                if (
                    is_array($field['values']) &&
                    !array_key_exists($key, $field['values'])
                ) {
                    throw SemanticException::forPath(
                        $path,
                        "[{$key}] is not a valid value for [{$field['label']}]."
                    );
                }

                return $key;

            default:
                return (string) $value;
        }
    }

    /**
     * Validate and coerce the member list of an `in` / `not_in` filter.
     *
     * @param mixed $value
     * @param array $field
     * @param string $path
     * @return array<int, mixed>
     */
    protected function listValue($value, array $field, string $path): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $value = array_values($value);

        if (empty($value)) {
            throw SemanticException::forPath(
                $path,
                "The list for [{$field['label']}] must contain at least one value."
            );
        }

        return array_map(function ($member) use ($field, $path) {
            return $this->coerceScalar($member, $field, $path);
        }, $value);
    }

    /**
     * Validate a `between` value and return its two bounds.
     *
     * @param mixed $value
     * @param string $type
     * @param string $path
     * @return array{0: mixed, 1: mixed}
     */
    protected function betweenBounds($value, string $type, string $path): array
    {
        if (!is_array($value) || count($value) !== 2) {
            throw SemanticException::forPath(
                $path,
                'A between filter needs exactly two values: [from, to].'
            );
        }

        $value = array_values($value);

        if ($this->isDateType($type)) {
            foreach ($value as $bound) {
                if (!$this->looksLikeDate($bound)) {
                    throw SemanticException::forPath(
                        $path,
                        'A between filter on a date needs two dates.'
                    );
                }
            }

            return [
                $this->dayBounds($value[0], $type, $path)[0],
                $this->dayBounds($value[1], $type, $path)[1],
            ];
        }

        foreach ($value as $bound) {
            if (!is_numeric($bound) && !is_string($bound)) {
                throw SemanticException::forPath(
                    $path,
                    'A between filter needs two scalar values.'
                );
            }
        }

        return [
            is_numeric($value[0]) ? $value[0] + 0 : $value[0],
            is_numeric($value[1]) ? $value[1] + 0 : $value[1],
        ];
    }

    /**
     * The inclusive start and end of the day a date value names.
     *
     * A `date` field compares against bare dates; a `datetime` field is
     * widened to the whole day, so "due date equals 1 March" matches a
     * timestamp at 09:14 on 1 March - which is what the user meant.
     *
     * @param mixed $value
     * @param string $type
     * @param string $path
     * @return array{0: string, 1: string}
     */
    protected function dayBounds($value, string $type, string $path): array
    {
        $value = trim((string) $value);

        if (!$this->looksLikeDate($value)) {
            throw SemanticException::forPath(
                $path,
                "[{$value}] is not a date."
            );
        }

        $date = substr($value, 0, 10);
        $hasTime = strlen($value) > 10;

        if ($type === 'date') {
            return [$date, $date];
        }

        if ($hasTime) {
            // An explicit time is honoured as given for both bounds.
            return [$value, $value];
        }

        return [$date . ' 00:00:00', $date . ' 23:59:59'];
    }

    /* ------------------------------------------------------------------
     | Grouping and sorting
     | ------------------------------------------------------------------ */

    /**
     * Work out the effective GROUP BY, and validate it against the selection.
     *
     * Two rules, both from the contract:
     *  - when an aggregate is selected, every non-aggregate field must be
     *    grouped;
     *  - an omitted `groupBy` is inferred as "every plain field groups".
     *
     * @param mixed $groupBy
     * @param array $outputs
     * @return array<int, string>
     */
    protected function resolveGroupBy($groupBy, array $outputs): array
    {
        if ($groupBy === null) {
            $groupBy = [];
        }

        if (is_string($groupBy)) {
            $groupBy = [$groupBy];
        }

        if (!is_array($groupBy)) {
            throw SemanticException::forPath(
                'groupBy',
                'groupBy must be a list of field paths.'
            );
        }

        $groupBy = array_values(
            array_unique(
                array_map(function ($path) {
                    return (string) $path;
                }, $groupBy)
            )
        );

        $plain = [];
        $hasAggregate = false;

        foreach ($outputs as $output) {
            if ($output['agg'] === null) {
                $plain[] = $output['path'];
            } else {
                $hasAggregate = true;
            }
        }

        if (!$hasAggregate && empty($groupBy)) {
            return [];
        }

        if ($hasAggregate && empty($groupBy)) {
            // Inferred: every plain field groups.
            return array_values(array_unique($plain));
        }

        $missing = array_values(array_diff($plain, $groupBy));

        if (!empty($missing)) {
            throw new SemanticException(
                'Every non-aggregated field must be grouped: ' .
                    implode(', ', $missing) .
                    '.',
                array_map(function ($path) {
                    return [
                        'path' => $path,
                        'message' =>
                            "[{$path}] is selected alongside an aggregate but is not in groupBy.",
                    ];
                }, $missing)
            );
        }

        return $groupBy;
    }

    /**
     * Resolve the sort list.
     *
     * A sort entry may name an output - a field path or an aggregate's label
     * - in which case it sorts by the generated select alias, or any other
     * field path. When the report is grouped, only grouped paths and
     * aggregate outputs can be sorted on; anything else has no defined value
     * per group.
     *
     * @param mixed $sort
     * @param array $outputs
     * @param array $groupBy
     * @return array<int, array{sql: string, dir: string}>
     */
    protected function resolveSorts($sort, array $outputs, array $groupBy): array
    {
        if ($sort === null || $sort === '') {
            return [];
        }

        if (!is_array($sort)) {
            throw SemanticException::forPath(
                'sort',
                'sort must be a list of {field, dir} objects.'
            );
        }

        $byKey = [];

        foreach ($outputs as $output) {
            $byKey[$output['key']] = $output;
        }

        $sorts = [];

        foreach (array_values($sort) as $index => $entry) {
            $path = "sort[{$index}]";

            if (is_string($entry)) {
                $entry = ['field' => $entry];
            }

            if (!is_array($entry) || !isset($entry['field'])) {
                throw SemanticException::forPath(
                    $path,
                    'Each sort entry must name a field.'
                );
            }

            $target = (string) $entry['field'];
            $dir = strtolower((string) ($entry['dir'] ?? 'asc'));

            if (!in_array($dir, ['asc', 'desc'], true)) {
                throw SemanticException::forPath(
                    $path,
                    "Unknown sort direction [{$dir}]. Use asc or desc."
                );
            }

            if (isset($byKey[$target])) {
                // Sorting by the select alias also covers aggregate labels,
                // which have no expression of their own to repeat.
                $sorts[] = [
                    'sql' => $this->wrap($byKey[$target]['alias']),
                    'dir' => $dir,
                ];

                continue;
            }

            if (!empty($groupBy) && !in_array($target, $groupBy, true)) {
                throw SemanticException::forPath(
                    $path,
                    "[{$target}] cannot be sorted on: it is neither grouped nor a selected column."
                );
            }

            $resolved = $this->resolvePath($target, $path);

            $sorts[] = [
                'sql' => $this->expression($resolved),
                'dir' => $dir,
            ];
        }

        return $sorts;
    }

    /* ------------------------------------------------------------------
     | Execution and row shaping
     | ------------------------------------------------------------------ */

    /**
     * Total row count for the compiled query, ignoring limit/offset.
     *
     * A grouped query counts *groups*, which only a wrapping subquery can
     * answer; an ungrouped one is counted directly, which is much cheaper.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param bool $grouped
     * @return int
     */
    protected function countTotal($query, bool $grouped): int
    {
        $counter = clone $query;
        $counter->orders = null;
        $counter->limit = null;
        $counter->offset = null;

        if (!$grouped) {
            return (int) $counter->count();
        }

        return (int) $this->connection()
            ->table(
                DB::raw('(' . $counter->toSql() . ') as visns_report_groups')
            )
            ->mergeBindings($counter)
            ->count();
    }

    /**
     * Turn raw rows into the response shape.
     *
     * Every row is keyed by exactly what the definition asked for - the
     * field path, or the aggregate's label - never by the generated alias.
     *
     * @param iterable $rows
     * @param array $outputs
     * @return array<int, array<string, mixed>>
     */
    protected function shapeRows($rows, array $outputs): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $mapped = [];

            foreach ($outputs as $output) {
                $value = $row[$output['alias']] ?? null;

                $mapped[$output['key']] = $this->shapeValue(
                    $value,
                    $output['type'],
                    $output['sentinels'],
                    $output['agg']
                );
            }

            $shaped[] = $mapped;
        }

        return $shaped;
    }

    /**
     * Shape a single value for the response.
     *
     * Formatting is the frontend's job - money, percentages, booleans and
     * enum keys come back raw. What happens here is only what the frontend
     * cannot do: sentinel dates become null, numbers become JSON numbers
     * instead of driver strings, and dates get a predictable serialisation.
     *
     * @param mixed $value
     * @param string $type
     * @param array $sentinels
     * @param string|null $agg
     * @return mixed
     */
    protected function shapeValue($value, string $type, array $sentinels, $agg)
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value) && in_array((string) $value, $sentinels, true)) {
            return null;
        }

        if ($agg === 'count') {
            return (int) $value;
        }

        if (in_array($type, SemanticModel::NUMERIC_TYPES, true)) {
            if (!is_numeric($value)) {
                return $value;
            }

            $number = $value + 0;

            return is_float($number) && floor($number) == $number &&
                strpos((string) $value, '.') === false
                ? (int) $number
                : $number;
        }

        if (in_array($type, SemanticModel::DATE_TYPES, true)) {
            return $this->formatDate($value, $type);
        }

        return $value;
    }

    /**
     * Serialise a date: `Y-m-d`, or `Y-m-d H:i:s` for a datetime.
     *
     * An unparseable value is handed back untouched rather than nulled - it
     * is data the report owner should see, not something to hide.
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    protected function formatDate($value, string $type)
    {
        $format = $type === 'datetime' ? 'Y-m-d H:i:s' : 'Y-m-d';

        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        try {
            return (new DateTimeImmutable($value))->format($format);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /* ------------------------------------------------------------------
     | Small helpers
     | ------------------------------------------------------------------ */

    /**
     * @return \Illuminate\Database\Connection
     */
    protected function connection()
    {
        return DB::connection($this->connectionName);
    }

    /**
     * Quote an identifier with the connection's grammar.
     *
     * @param string $identifier `table.column` or a bare name.
     * @return string
     */
    protected function wrap(string $identifier): string
    {
        return $this->connection()->getQueryGrammar()->wrap($identifier);
    }

    /**
     * The next generated select alias.
     *
     * Rows are fetched under `c0`, `c1`, ... and re-keyed afterwards. Using
     * the user's own label as a SQL alias would mean quoting arbitrary text
     * (`Total fees ($)`) inside the statement; this side-steps it entirely
     * and keeps ORDER BY on an aggregate label trivial.
     *
     * @return string
     */
    protected function nextAlias(): string
    {
        return 'c' . $this->aliasSeq++;
    }

    /**
     * Bindings carried by the select list. Always empty today - select
     * expressions are built from the registry, never from request values -
     * but kept explicit so a future literal cannot be interpolated.
     *
     * @param array $outputs
     * @return array
     */
    protected function selectBindings(array $outputs): array
    {
        return [];
    }

    /**
     * @param int|null $limit
     * @param int|null $maxLimit
     * @return int
     */
    protected function normaliseLimit($limit, $maxLimit): int
    {
        $ceiling = $maxLimit === null ? self::MAX_LIMIT : (int) $maxLimit;

        if ($limit === null || $limit === '' || !is_numeric($limit)) {
            $limit = self::DEFAULT_LIMIT;
        }

        $limit = (int) $limit;

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min($limit, $ceiling);
    }

    /**
     * Escape the LIKE wildcards in a user-supplied search term.
     *
     * Without this a filter value of `100%` matches everything starting
     * with 100.
     *
     * @param string $value
     * @return string
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value
        );
    }

    /**
     * @param string $type
     * @return bool
     */
    protected function isDateType(string $type): bool
    {
        return in_array($type, SemanticModel::DATE_TYPES, true);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected function looksLikeDate($value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            return true;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        // Anchored on the ISO shape the wizard sends; a free-form string
        // would let strtotime() invent a date from a typo.
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/',
            $value
        );
    }

    /**
     * Whether a supplied parameter counts as "not provided".
     *
     * @param mixed $value
     * @return bool
     */
    protected function isBlank($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            // An array whose every element is itself blank counts as blank.
            // A wizard keeps its inputs controlled, so an unanswered date
            // range arrives as ['', ''] rather than as an absent key; without
            // this, an *optional* range the user left empty would fail date
            // validation instead of dropping its condition.
            foreach ($value as $entry) {
                if (! $this->isBlank($entry)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param array $value
     * @return bool
     */
    protected function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param mixed $value
     * @param string $fallback
     * @return string
     */
    protected function stringOr($value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return $fallback;
    }
}
