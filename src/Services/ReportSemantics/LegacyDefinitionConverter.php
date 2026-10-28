<?php

namespace Visnsstudio\VisnsPackages\Services\ReportSemantics;

/**
 * Upgrades a legacy (v1, table-and-join) report definition to the v2
 * semantic format, as far as the semantic model allows.
 *
 * Pure: no facades, no database. The semantic model is the only input besides
 * the definition, and everything that cannot be expressed against it is
 * dropped or approximated with a human-readable note rather than guessed. The
 * caller (the `report-builder:upgrade-legacy` command) is expected to compile
 * the result before saving it - this class guarantees the shape, not that the
 * engine will accept every value.
 *
 * Mapping rules are documented in docs/report-semantics.md, section 11.
 */
class LegacyDefinitionConverter
{
    /** Operators whose JSON fan-out must hold for every part, not any part. */
    private const NEGATIVE_OPERATORS = ['not_equals', 'not_contains', 'not_in', 'is_empty'];

    /** @var SemanticModel */
    private $model;

    /**
     * v1 table name => ['entity' => entity id, 'prefix' => relation path prefix].
     *
     * @var array<string, array{entity: string, prefix: string}>
     */
    private $tables = [];

    /** @var array<int, string> */
    private $notes = [];

    /** @var string */
    private $mainTable = '';

    /**
     * Set while fanning a filter out over JSON parts: a part that cannot take
     * the condition is summarised once by the caller, not noted per part.
     *
     * @var bool
     */
    private $quietDrops = false;

    /**
     * Whether the v1 definition is a legacy one this converter should handle.
     *
     * @param mixed $detail
     * @return bool
     */
    public static function isLegacyDefinition($detail): bool
    {
        return is_array($detail) &&
            array_key_exists('mainTable', $detail) &&
            !array_key_exists('schema_version', $detail);
    }

    /**
     * @param array $v1 The legacy definition (decoded `detail`).
     * @param SemanticModel $model
     * @return ConversionResult
     */
    public function convert(array $v1, SemanticModel $model): ConversionResult
    {
        $this->model = $model;
        $this->tables = [];
        $this->notes = [];

        $mainTable = $v1['mainTable'] ?? null;

        if (!is_string($mainTable) || trim($mainTable) === '') {
            return ConversionResult::notConvertible('The definition names no main table.');
        }

        $this->mainTable = $mainTable;
        $entity = $this->entityForTable($mainTable);

        if ($entity === null) {
            return ConversionResult::notConvertible(
                "No entity in the semantic model is backed by table [{$mainTable}]."
            );
        }

        if ($this->model->entity($entity)['hidden']) {
            $this->note(
                "Entity [{$entity}] is a hidden lookup in the semantic model; the report is " .
                'kept on it, but the wizard will not offer it as a starting point.'
            );
        }

        $this->tables[$mainTable] = ['entity' => $entity, 'prefix' => ''];

        $this->resolveJoins(is_array($v1['joins'] ?? null) ? $v1['joins'] : []);
        $this->noteUnsupportedFeatures($v1);

        $fields = $this->convertColumns(is_array($v1['columns'] ?? null) ? $v1['columns'] : []);

        if (empty($fields)) {
            return ConversionResult::notConvertible(
                'None of the selected columns is published in the semantic model.',
                $this->notes
            );
        }

        $definition = [
            'schema_version' => 2,
            'entity' => $entity,
            'fields' => $fields,
        ];

        $filters = $this->convertFilters($v1['filters'] ?? null);

        if ($filters !== null) {
            $definition['filters'] = $filters;
        }

        $sort = $this->convertSorting(is_array($v1['sorting'] ?? null) ? $v1['sorting'] : []);

        if (!empty($sort)) {
            $definition['sort'] = $sort;
        }

        return ConversionResult::converted($definition, $this->notes);
    }

    /* ------------------------------------------------------------------
     | Entities and joins
     | ------------------------------------------------------------------ */

    /**
     * The entity backed by a table, preferring one that is not hidden.
     */
    private function entityForTable(string $table): ?string
    {
        $hidden = null;

        foreach ($this->model->entities() as $id => $entity) {
            if ($entity['table'] !== $table) {
                continue;
            }

            if (!$entity['hidden']) {
                return $id;
            }

            $hidden = $hidden ?? $id;
        }

        return $hidden;
    }

    /**
     * Map every v1 join onto a relation path, dropping (with a note) any join
     * that no declared relation reproduces.
     *
     * Joins are resolved in passes, so a join listed before the join that
     * reaches its source table still resolves.
     */
    private function resolveJoins(array $joins): void
    {
        $pending = array_values(array_filter($joins, 'is_array'));

        do {
            $progress = false;

            foreach ($pending as $index => $join) {
                $source = (string) ($join['sourceTable'] ?? '');
                $target = (string) ($join['targetTable'] ?? '');
                $sourceColumn = (string) ($join['sourceColumn'] ?? '');
                $targetColumn = (string) ($join['targetColumn'] ?? '');

                if (isset($this->tables[$target]) && !isset($this->tables[$source])) {
                    // Written the other way round: walk it from the side the
                    // report already reaches.
                    [$source, $target] = [$target, $source];
                    [$sourceColumn, $targetColumn] = [$targetColumn, $sourceColumn];
                }

                if (!isset($this->tables[$source])) {
                    continue; // Maybe reachable after a later join resolves.
                }

                unset($pending[$index]);
                $progress = true;
                $description = $this->describeJoin($join);

                if (isset($this->tables[$target])) {
                    $this->note(
                        "Join {$description} dropped: table [{$target}] is already part of the report."
                    );
                    continue;
                }

                $relation = $this->findRelation(
                    $this->tables[$source]['entity'],
                    $target,
                    $sourceColumn,
                    $targetColumn
                );

                if ($relation === null) {
                    $this->note(
                        "Join {$description} dropped: no relation in the semantic model " .
                        'matches it. Columns, filters and sorting on ' . "[{$target}] are dropped too."
                    );
                    continue;
                }

                $this->tables[$target] = [
                    'entity' => $relation['entity'],
                    'prefix' => $this->tables[$source]['prefix'] . $relation['name'] . '.',
                ];

                $joinType = strtoupper(trim((string) ($join['joinType'] ?? 'LEFT JOIN')));

                if (!in_array($joinType, ['', 'LEFT', 'LEFT JOIN', 'LEFT OUTER JOIN'], true)) {
                    $this->note(
                        "Join {$description} was a {$joinType}; v2 relations are always LEFT JOINs, " .
                        "so rows with no matching [{$target}] record are now included."
                    );
                }
            }
        } while ($progress && !empty($pending));

        foreach ($pending as $join) {
            $this->note(
                'Join ' . $this->describeJoin($join) . ' dropped: it does not connect to ' .
                'any table already in the report.'
            );
        }
    }

    /**
     * The relation on `$entity` that lands on `$targetTable` and joins on
     * exactly the given columns. Visible relations win over hidden ones.
     */
    private function findRelation(
        string $entity,
        string $targetTable,
        string $sourceColumn,
        string $targetColumn
    ): ?array {
        $hidden = null;

        foreach ($this->model->entity($entity)['relations'] as $relation) {
            if (!$this->model->hasEntity($relation['entity'])) {
                continue;
            }

            if ($this->model->entity($relation['entity'])['table'] !== $targetTable) {
                continue;
            }

            $matches = $relation['type'] === 'belongs_to'
                ? $sourceColumn === $relation['foreign_key'] && $targetColumn === $relation['owner_key']
                : $sourceColumn === $relation['local_key'] && $targetColumn === $relation['foreign_key'];

            if (!$matches) {
                continue;
            }

            if (!$relation['hidden']) {
                return $relation;
            }

            $hidden = $hidden ?? $relation;
        }

        return $hidden;
    }

    private function describeJoin(array $join): string
    {
        return sprintf(
            '%s.%s = %s.%s (%s%s)',
            $join['sourceTable'] ?? '?',
            $join['sourceColumn'] ?? '?',
            $join['targetTable'] ?? '?',
            $join['targetColumn'] ?? '?',
            strtoupper((string) ($join['joinType'] ?? 'LEFT JOIN')),
            !empty($join['isManual']) ? ', manual' : ''
        );
    }

    /**
     * v1 settings with no v2 equivalent.
     */
    private function noteUnsupportedFeatures(array $v1): void
    {
        if (!empty($v1['groupBy'])) {
            $this->note('v1 grouping dropped: it has no direct v2 equivalent; rebuild it with aggregates in the wizard.');
        }

        if (!empty($v1['unique']['enabled'])) {
            $this->note('v1 "unique rows" setting dropped: v2 has no de-duplication option.');
        }

        if (!empty($v1['excludedRows'])) {
            $this->note(
                'v1 excluded rows dropped (' . count((array) $v1['excludedRows']) .
                ' row ids): v2 has no per-row exclusion.'
            );
        }
    }

    /* ------------------------------------------------------------------
     | Column resolution
     | ------------------------------------------------------------------ */

    /**
     * Resolve a v1 table/column pair to field paths.
     *
     * @return array{kind: string, paths: array<int, string>, types: array<string, array>}
     *   kind: direct | json | unpublished | unreachable
     */
    private function resolveColumn(?string $table, string $column): array
    {
        $table = $table !== null && $table !== '' ? $table : $this->mainTable;

        if (!isset($this->tables[$table])) {
            return ['kind' => 'unreachable', 'paths' => [], 'types' => []];
        }

        ['entity' => $entity, 'prefix' => $prefix] = $this->tables[$table];
        $fields = $this->model->entity($entity)['fields'];

        $direct = $this->pickVisible(array_filter(
            $fields,
            static fn ($field) => $field['column'] === $column
        ));

        if (!empty($direct)) {
            $field = reset($direct);

            return [
                'kind' => 'direct',
                'paths' => [$prefix . $field['id']],
                'types' => [$prefix . $field['id'] => $field],
            ];
        }

        $json = $this->pickVisible(array_filter(
            $fields,
            static fn ($field) => ($field['json']['column'] ?? null) === $column
        ));

        if (!empty($json)) {
            $paths = [];
            $types = [];

            foreach ($json as $field) {
                $paths[] = $prefix . $field['id'];
                $types[$prefix . $field['id']] = $field;
            }

            return ['kind' => 'json', 'paths' => $paths, 'types' => $types];
        }

        return ['kind' => 'unpublished', 'paths' => [], 'types' => []];
    }

    /**
     * Visible fields when there are any, otherwise the hidden ones (still
     * resolvable by a definition), in registry order.
     */
    private function pickVisible(array $fields): array
    {
        $visible = array_filter($fields, static fn ($field) => !$field['hidden']);

        return array_values(empty($visible) ? $fields : $visible);
    }

    private function labelsFor(array $resolved): string
    {
        return implode(', ', array_map(
            static fn ($field) => $field['label'],
            array_values($resolved['types'])
        ));
    }

    /**
     * The note for a column that could not be mapped, or null when it could.
     */
    private function unmappedReason(array $resolved, string $table, string $column): ?string
    {
        if ($resolved['kind'] === 'unreachable') {
            return "[{$table}] is not joined through a relation the semantic model declares";
        }

        if ($resolved['kind'] === 'unpublished') {
            return "column [{$table}.{$column}] is not published in the semantic model";
        }

        return null;
    }

    /* ------------------------------------------------------------------
     | Columns and sorting
     | ------------------------------------------------------------------ */

    private function convertColumns(array $columns): array
    {
        $fields = [];
        $seen = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $table = (string) ($column['table'] ?? $this->mainTable);
            $name = (string) ($column['column'] ?? '');
            $display = (string) ($column['displayName'] ?? $column['alias'] ?? $name);

            if (!empty($column['isCalculated'])) {
                $this->note("Calculated column \"{$display}\" dropped: v2 has no formula columns.");
                continue;
            }

            if ($name === '') {
                continue;
            }

            $resolved = $this->resolveColumn($table, $name);
            $reason = $this->unmappedReason($resolved, $table, $name);

            if ($reason !== null) {
                $this->note("Column \"{$display}\" dropped: {$reason}.");
                continue;
            }

            if ($resolved['kind'] === 'json') {
                $this->note(
                    "Column \"{$display}\" ({$table}.{$name}) is a JSON document; " .
                    'replaced by its published parts: ' . $this->labelsFor($resolved) . '.'
                );
            }

            foreach ($resolved['paths'] as $path) {
                if (isset($seen[$path])) {
                    $this->note("Column \"{$display}\": field [{$path}] is already selected, not repeated.");
                    continue;
                }

                $seen[$path] = true;
                $fields[] = ['field' => $path];
            }
        }

        return $fields;
    }

    private function convertSorting(array $sorting): array
    {
        $sort = [];
        $seen = [];

        foreach ($sorting as $entry) {
            if (!is_array($entry) || empty($entry['column'])) {
                continue;
            }

            $table = (string) ($entry['table'] ?? $this->mainTable);
            $name = (string) $entry['column'];
            $dir = strtolower((string) ($entry['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            $resolved = $this->resolveColumn($table, $name);
            $reason = $this->unmappedReason($resolved, $table, $name);

            if ($reason !== null) {
                $this->note("Sort on {$table}.{$name} dropped: {$reason}.");
                continue;
            }

            if ($resolved['kind'] === 'json') {
                $this->note(
                    "Sort on {$table}.{$name} (a JSON document) replaced by a sort on its parts: " .
                    $this->labelsFor($resolved) . '.'
                );
            }

            foreach ($resolved['paths'] as $path) {
                if (isset($seen[$path])) {
                    continue;
                }

                $seen[$path] = true;
                $sort[] = ['field' => $path, 'dir' => $dir];
            }
        }

        return $sort;
    }

    /* ------------------------------------------------------------------
     | Filters
     | ------------------------------------------------------------------ */

    /**
     * Convert the v1 filter structure into a v2 filter tree whose root is
     * always a group (the wizard's editing tree requires one), or null when
     * no condition survives.
     *
     * @param mixed $filters
     */
    private function convertFilters($filters): ?array
    {
        if (!is_array($filters) || empty($filters)) {
            return null;
        }

        // The oldest v1 shape is a bare list of filters, ANDed.
        if (!isset($filters['groups']) && !isset($filters['filters']) && array_is_list($filters)) {
            $filters = ['operator' => 'AND', 'filters' => $filters];
        }

        $usesOr = false;
        $root = $this->convertGroup($filters, $usesOr, true);

        if ($root === null) {
            return null;
        }

        if ($usesOr) {
            $this->note(
                'The saved filters combine conditions with OR. The v1 engine applied every ' .
                'condition with AND regardless; the upgraded report honours the OR as saved, ' .
                'so it may return more rows than before.'
            );
        }

        // A root holding a single group is that group.
        if (count($root['items']) === 1 && isset($root['items'][0]['op'])) {
            return $root['items'][0];
        }

        return $root;
    }

    /**
     * @param array $group A v1 group: {operator, groups?: [...], filters?: [...]}.
     * @param bool $usesOr Set when a v1-authored OR survives with 2+ items.
     * @param bool $isRoot Roots always stay groups.
     * @return array|null A v2 group, a single leaf (non-root), or null.
     */
    private function convertGroup(array $group, bool &$usesOr, bool $isRoot = false): ?array
    {
        $op = strtolower(trim((string) ($group['operator'] ?? 'and'))) === 'or' ? 'or' : 'and';
        $children = [];

        foreach (['groups', 'filters'] as $key) {
            foreach ((array) ($group[$key] ?? []) as $child) {
                if (!is_array($child)) {
                    continue;
                }

                $converted = isset($child['groups']) || isset($child['filters'])
                    ? $this->convertGroup($child, $usesOr)
                    : $this->convertFilter($child);

                if ($converted === null) {
                    continue;
                }

                // A nested group with the same operator adds nothing.
                if (isset($converted['op']) && $converted['op'] === $op && empty($converted['_fanout'])) {
                    array_push($children, ...$converted['items']);
                    continue;
                }

                unset($converted['_fanout']);
                $children[] = $converted;
            }
        }

        if (empty($children)) {
            return null;
        }

        if ($op === 'or' && count($children) > 1) {
            $usesOr = true;
        }

        if (!$isRoot && count($children) === 1) {
            return $children[0];
        }

        return ['op' => $op, 'items' => $children];
    }

    /**
     * Convert one v1 filter into a leaf, a fan-out group, or null (dropped).
     */
    private function convertFilter(array $filter): ?array
    {
        $table = (string) ($filter['table'] ?? $this->mainTable);
        $column = (string) ($filter['column'] ?? '');
        $operator = (string) ($filter['operator'] ?? '=');
        $value = $filter['value'] ?? null;

        if ($column === '') {
            return null;
        }

        $description = "{$table}.{$column} {$operator}" .
            (is_scalar($value) ? " '{$value}'" : (is_array($value) ? ' ' . json_encode($value) : ''));

        $resolved = $this->resolveColumn($table, $column);
        $reason = $this->unmappedReason($resolved, $table, $column);

        if ($reason !== null) {
            $this->note("Filter {$description} dropped: {$reason}.");
            return null;
        }

        $nodes = [];
        $skipped = [];
        $this->quietDrops = $resolved['kind'] === 'json';

        foreach ($resolved['paths'] as $path) {
            $field = $resolved['types'][$path];
            $node = $this->convertCondition($path, $field, $operator, $value, $description);

            if ($node === null) {
                $skipped[] = $field['label'];
                continue;
            }

            $nodes[] = $node;
        }

        $this->quietDrops = false;

        if (empty($nodes)) {
            // convertCondition() already noted why for a single field.
            if ($resolved['kind'] === 'json') {
                $this->note("Filter {$description} dropped: no part of the JSON document supports it.");
            }

            return null;
        }

        if ($resolved['kind'] !== 'json') {
            return $nodes[0];
        }

        $leafOperator = $nodes[0]['operator'] ?? null;
        $combine = in_array($leafOperator, self::NEGATIVE_OPERATORS, true) ? 'and' : 'or';

        $this->note(
            "Filter {$description} is on a JSON document; it now tests each published part (" .
            implode(', ', array_map(fn ($path) => $resolved['types'][$path]['label'], $resolved['paths'])) .
            ') combined with ' . strtoupper($combine) .
            '. Text elsewhere in the document (e.g. the street) is no longer searched.' .
            (empty($skipped) ? '' : ' Parts that cannot take this condition were left out: ' . implode(', ', $skipped) . '.')
        );

        if (count($nodes) === 1) {
            return $nodes[0];
        }

        // Marked so the parent does not dissolve it into a same-operator group.
        return ['op' => $combine, 'items' => $nodes, '_fanout' => true];
    }

    /**
     * Translate one v1 operator/value pair for one field.
     *
     * @return array|null A leaf, a small group, or null (with a note) when the
     *   field's type has no equivalent.
     */
    private function convertCondition(
        string $path,
        array $field,
        string $operator,
        $value,
        string $description
    ): ?array {
        $type = $field['type'];
        $op = $this->normaliseOperator($operator);
        $isNumeric = in_array($type, SemanticModel::NUMERIC_TYPES, true);
        $isDate = in_array($type, SemanticModel::DATE_TYPES, true);
        $isText = $type === 'text';
        $leaf = static function (string $operator, $value = null) use ($path): array {
            $node = ['field' => $path, 'operator' => $operator];

            if (func_num_args() > 1) {
                $node['value'] = $value;
            }

            return $node;
        };
        $drop = function (string $why) use ($description, $field): ?array {
            if ($this->quietDrops) {
                return null;
            }

            $this->note("Filter {$description} dropped for \"{$field['label']}\": {$why}.");
            return null;
        };

        switch ($op) {
            case 'IS NULL':
            case 'IS NOT NULL':
                $empty = $op === 'IS NULL';

                if ($isText || $isNumeric || $isDate) {
                    return $leaf($empty ? 'is_empty' : 'not_empty');
                }

                if ($type === 'boolean' && $empty) {
                    $this->note("Filter {$description} approximated as \"is no\" for \"{$field['label']}\" (v2 \"is no\" matches 0 as well as blank).");
                    return $leaf('is_false');
                }

                return $drop("{$type} fields have no blank/not-blank test in v2");

            case 'LIKE':
            case 'NOT LIKE':
                if (!$isText) {
                    return $drop("\"contains\" is only available on text fields, this one is {$type}");
                }

                $term = trim((string) (is_scalar($value) ? $value : ''), '%');

                if ($term === '') {
                    return $drop('the pattern matches everything');
                }

                if (strpbrk($term, '%_') !== false) {
                    $this->note("Filter {$description}: wildcards inside the pattern are now matched literally.");
                }

                return $leaf($op === 'LIKE' ? 'contains' : 'not_contains', $term);

            case '=':
            case '!=':
                $equals = $op === '=';

                if ($type === 'boolean') {
                    $truthy = $this->booleanValue($value);

                    if ($truthy === null) {
                        return $drop('the value is not a yes/no value');
                    }

                    return $leaf($truthy === $equals ? 'is_true' : 'is_false');
                }

                if ($isDate && !$equals) {
                    $this->note("Filter {$description}: \"is not\" on a date became \"before OR after\" (blank dates no longer match).");
                    return ['op' => 'or', 'items' => [$leaf('before', $value), $leaf('after', $value)]];
                }

                return $leaf($equals ? 'equals' : 'not_equals', $value);

            case '>':
            case '<':
            case '>=':
            case '<=':
                return $this->convertComparison($op, $type, $value, $leaf, $drop, $description);

            case 'IN':
            case 'NOT IN':
                return $this->convertList($op === 'IN', $type, $value, $leaf, $drop, $description);

            case 'BETWEEN':
                $range = $this->listValue($value);

                if (!($isNumeric || $isDate) || count($range) !== 2) {
                    return $drop('"between" needs a number or date field and exactly two values');
                }

                return $leaf('between', $range);
        }

        return $drop("operator [{$operator}] has no v2 equivalent");
    }

    private function convertComparison(
        string $op,
        string $type,
        $value,
        callable $leaf,
        callable $drop,
        string $description
    ): ?array {
        if (in_array($type, SemanticModel::NUMERIC_TYPES, true)) {
            return $leaf(['>' => 'gt', '<' => 'lt', '>=' => 'gte', '<=' => 'lte'][$op], $value);
        }

        if (!in_array($type, SemanticModel::DATE_TYPES, true)) {
            return $drop("comparisons are only available on number and date fields, this one is {$type}");
        }

        if ($op === '>') {
            return $leaf('after', $value);
        }

        if ($op === '<') {
            return $leaf('before', $value);
        }

        // Inclusive bounds: v2 has strict before/after only. A bare date is
        // shifted by a day (exact for both date and datetime fields, since a
        // datetime compared with a bare date is widened to the whole day);
        // anything else becomes "equals OR after/before".
        $strict = $op === '>=' ? 'after' : 'before';
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date !== false && $date->format('Y-m-d') === $value) {
            $shifted = $date->modify($op === '>=' ? '-1 day' : '+1 day')->format('Y-m-d');
            $this->note("Filter {$description}: inclusive bound rewritten as {$strict} {$shifted}.");
            return $leaf($strict, $shifted);
        }

        $this->note("Filter {$description}: inclusive bound rewritten as \"equals OR {$strict}\".");

        return ['op' => 'or', 'items' => [$leaf('equals', $value), $leaf($strict, $value)]];
    }

    private function convertList(
        bool $in,
        string $type,
        $value,
        callable $leaf,
        callable $drop,
        string $description
    ): ?array {
        $values = $this->listValue($value);

        if (empty($values)) {
            return $drop('the value list is empty');
        }

        if ($type === 'enum') {
            return $leaf($in ? 'in' : 'not_in', $values);
        }

        if ($type === 'boolean') {
            return $drop('lists are not available on yes/no fields');
        }

        if (!$in && in_array($type, SemanticModel::DATE_TYPES, true)) {
            return $drop('"is none of" is not available on date fields');
        }

        // "is any of" / "is none of" exist for enum fields only; elsewhere
        // the list becomes OR-ed equals / AND-ed not-equals.
        if (count($values) === 1) {
            return $leaf($in ? 'equals' : 'not_equals', $values[0]);
        }

        $this->note(
            "Filter {$description}: " . ($in ? '"is any of"' : '"is none of"') .
            " is only available on option fields; written as " .
            ($in ? 'one "is" condition per value, OR-ed.' : 'one "is not" condition per value, AND-ed.')
        );

        return [
            'op' => $in ? 'or' : 'and',
            'items' => array_map(
                static fn ($item) => $leaf($in ? 'equals' : 'not_equals', $item),
                $values
            ),
        ];
    }

    /**
     * Canonical upper-case operator, folding the spellings the v1 engine
     * accepted.
     */
    private function normaliseOperator(string $operator): string
    {
        $op = strtoupper(preg_replace('/\s+/', ' ', trim($operator)));

        $aliases = [
            '==' => '=',
            '<>' => '!=',
            'NULL' => 'IS NULL',
            'ISNULL' => 'IS NULL',
            '= NULL' => 'IS NULL',
            'EQUALS NULL' => 'IS NULL',
            'NOT NULL' => 'IS NOT NULL',
            'ISNOT NULL' => 'IS NOT NULL',
            'ISNOTNULL' => 'IS NOT NULL',
            '!= NULL' => 'IS NOT NULL',
            '<> NULL' => 'IS NOT NULL',
            'NOT EQUALS NULL' => 'IS NOT NULL',
        ];

        return $aliases[$op] ?? $op;
    }

    /**
     * A list value: an array as given, or a comma-separated string.
     */
    private function listValue($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_scalar($value) && $value !== '') {
            $items = explode(',', (string) $value);
        } else {
            return [];
        }

        $items = array_map(
            static fn ($item) => is_string($item) ? trim($item) : $item,
            array_filter($items, 'is_scalar')
        );

        return array_values(array_filter($items, static fn ($item) => $item !== ''));
    }

    private function booleanValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = strtolower(trim((string) (is_scalar($value) ? $value : '')));

        if (in_array($normalised, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }

    private function note(string $note): void
    {
        $this->notes[] = $note;
    }
}
