<?php

namespace Visnsstudio\VisnsPackages\Services\ReportSemantics;

use RuntimeException;

/**
 * The semantic model: the report builder's business-language schema.
 *
 * The registry maps user-facing entity/field/relation ids onto tables,
 * columns and joins. Two things follow from that:
 *
 *  1. It is the *allowlist*. The compiler resolves every path through this
 *     class and nothing else, so a caller can only reach the columns the
 *     application has deliberately published.
 *  2. It is the *translation layer*. `toClientPayload()` returns the model
 *     with every internal key (table, column, json, foreign_key, ...)
 *     stripped, so table and column names never reach the browser.
 *
 * The registry is built from `config('visns-packages.report_semantics')` and,
 * optionally, from a registrar class the application binds - see
 * docs/report-semantics.md.
 */
class SemanticModel
{
    /**
     * Every field type the model understands.
     */
    const TYPES = [
        'text',
        'number',
        'money',
        'percent',
        'date',
        'datetime',
        'boolean',
        'enum',
    ];

    /**
     * Types whose values are numeric, and therefore sum/avg-able and cast to
     * DECIMAL when they come out of a JSON document.
     */
    const NUMERIC_TYPES = ['number', 'money', 'percent'];

    /**
     * Types carrying a date, cast to DATE/DATETIME when JSON-extracted.
     */
    const DATE_TYPES = ['date', 'datetime'];

    /**
     * Operators permitted per field type.
     *
     * This is the contract the wizard is built against; an operator not
     * listed for a type is rejected outright rather than falling through to
     * equality.
     */
    const OPERATORS = [
        'text' => [
            'equals',
            'not_equals',
            'contains',
            'not_contains',
            'is_empty',
            'not_empty',
        ],
        'number' => [
            'equals',
            'not_equals',
            'gt',
            'gte',
            'lt',
            'lte',
            'between',
            'is_empty',
            'not_empty',
        ],
        'money' => [
            'equals',
            'not_equals',
            'gt',
            'gte',
            'lt',
            'lte',
            'between',
            'is_empty',
            'not_empty',
        ],
        'percent' => [
            'equals',
            'not_equals',
            'gt',
            'gte',
            'lt',
            'lte',
            'between',
            'is_empty',
            'not_empty',
        ],
        'date' => [
            'equals',
            'before',
            'after',
            'between',
            'is_empty',
            'not_empty',
        ],
        'datetime' => [
            'equals',
            'before',
            'after',
            'between',
            'is_empty',
            'not_empty',
        ],
        'boolean' => ['is_true', 'is_false'],
        'enum' => ['equals', 'not_equals', 'in', 'not_in'],
    ];

    /**
     * Relation types and the cardinality each implies.
     */
    const CARDINALITY = [
        'belongs_to' => 'one',
        'has_one' => 'one',
        'has_many' => 'many',
    ];

    /**
     * A SQL identifier the registry is allowed to name.
     *
     * Table, column and key names are interpolated into raw SQL fragments
     * (JSON extraction, join conditions), so they are validated once here
     * rather than trusted at every use site.
     */
    const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * A MySQL JSON path. `$.a`, `$.a.b`, `$.a[0].b` - nothing else, because
     * the path is embedded in a string literal.
     */
    const JSON_PATH = '/^\$(\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])*$/';

    /**
     * Normalised entities, keyed by entity id.
     *
     * @var array<string, array>
     */
    protected $entities = [];

    /**
     * @param array<string, array> $entities Raw entity definitions.
     */
    public function __construct(array $entities = [])
    {
        $this->entities = $this->normalise($entities);
    }

    /**
     * Build the model from the application's configuration.
     *
     * Entities come from `visns-packages.report_semantics.entities`; a
     * registrar (if configured) is merged on top, entity by entity, so an
     * application can publish most of the model as config and compute the
     * awkward parts in code.
     *
     * @param array|null $config The `report_semantics` config block.
     * @return static
     */
    public static function fromConfig($config = null): self
    {
        if ($config === null) {
            $config = function_exists('config')
                ? config('visns-packages.report_semantics', [])
                : [];
        }

        $config = is_array($config) ? $config : [];

        $entities = isset($config['entities']) && is_array($config['entities'])
            ? $config['entities']
            : [];

        $registrar = static::resolveRegistrar($config['registrar'] ?? null);

        if ($registrar !== null) {
            // Entity-level merge, not a deep merge: a registrar redefining
            // `clients` replaces that entity outright, which keeps the
            // precedence rule easy to reason about.
            $entities = array_replace($entities, $registrar);
        }

        return new static($entities);
    }

    /**
     * Resolve the optional registrar hook to an entity array.
     *
     * Accepted shapes: an array (used as-is), a callable, a class name that
     * the container can build exposing `entities()` or `__invoke()`.
     *
     * @param mixed $registrar
     * @return array|null
     */
    protected static function resolveRegistrar($registrar)
    {
        if ($registrar === null || $registrar === '' || $registrar === false) {
            return null;
        }

        if (is_array($registrar)) {
            return $registrar;
        }

        if (is_string($registrar)) {
            if (!function_exists('app')) {
                throw new RuntimeException(
                    'Report semantics registrar [' .
                        $registrar .
                        '] cannot be resolved outside a Laravel application.'
                );
            }

            $registrar = app($registrar);
        }

        if (is_object($registrar) && method_exists($registrar, 'entities')) {
            $result = $registrar->entities();
        } elseif (is_callable($registrar)) {
            $result = $registrar();
        } else {
            throw new RuntimeException(
                'Report semantics registrar must expose entities() or be callable.'
            );
        }

        if (!is_array($result)) {
            throw new RuntimeException(
                'Report semantics registrar must return an array of entities.'
            );
        }

        // Tolerate a registrar that returns the whole block rather than just
        // the entity map.
        if (isset($result['entities']) && is_array($result['entities'])) {
            $result = $result['entities'];
        }

        return $result;
    }

    /**
     * Validate and normalise the raw registry.
     *
     * A malformed registry is a deployment problem, not a caller problem, so
     * it throws a plain RuntimeException (rendered as a 500 with a
     * correlation id) rather than a SemanticException.
     *
     * @param array $entities
     * @return array<string, array>
     */
    protected function normalise(array $entities): array
    {
        $normalised = [];

        foreach ($entities as $name => $entity) {
            if (!is_string($name) || !preg_match(self::IDENTIFIER, $name)) {
                throw new RuntimeException(
                    "Report semantics: invalid entity id [{$name}]."
                );
            }

            if (!is_array($entity)) {
                throw new RuntimeException(
                    "Report semantics: entity [{$name}] must be an array."
                );
            }

            $table = $entity['table'] ?? $name;

            if (
                !is_string($table) ||
                !preg_match(self::IDENTIFIER, $table)
            ) {
                throw new RuntimeException(
                    "Report semantics: entity [{$name}] has an invalid table name."
                );
            }

            $primaryKey = $entity['primary_key'] ?? 'id';

            if (!preg_match(self::IDENTIFIER, (string) $primaryKey)) {
                throw new RuntimeException(
                    "Report semantics: entity [{$name}] has an invalid primary_key."
                );
            }

            $normalised[$name] = [
                'name' => $name,
                'label' => $this->stringOr(
                    $entity['label'] ?? null,
                    $this->humanise($name)
                ),
                'plural' => $this->stringOr(
                    $entity['plural'] ?? null,
                    $this->stringOr(
                        $entity['label'] ?? null,
                        $this->humanise($name)
                    )
                ),
                'description' => $this->stringOr(
                    $entity['description'] ?? null,
                    ''
                ),
                'table' => $table,
                'primary_key' => (string) $primaryKey,
                // null = introspect for a deleted_at column; true/false =
                // authoritative, no schema round trip.
                'soft_deletes' => array_key_exists('soft_deletes', $entity)
                    ? (bool) $entity['soft_deletes']
                    : null,
                'hidden' => (bool) ($entity['hidden'] ?? false),
                'fields' => $this->normaliseFields(
                    $name,
                    $entity['fields'] ?? []
                ),
                'relations' => $this->normaliseRelations(
                    $name,
                    $entity['relations'] ?? []
                ),
            ];
        }

        // Relation targets can only be checked once every entity is known.
        foreach ($normalised as $name => $entity) {
            foreach ($entity['relations'] as $relation => $definition) {
                if (!isset($normalised[$definition['entity']])) {
                    throw new RuntimeException(
                        "Report semantics: relation [{$name}.{$relation}] points at unknown entity [{$definition['entity']}]."
                    );
                }
            }
        }

        return $normalised;
    }

    /**
     * @param string $entityName
     * @param mixed $fields
     * @return array<string, array>
     */
    protected function normaliseFields(string $entityName, $fields): array
    {
        if (!is_array($fields)) {
            throw new RuntimeException(
                "Report semantics: entity [{$entityName}] has a non-array fields block."
            );
        }

        $normalised = [];

        foreach ($fields as $id => $field) {
            if (!is_string($id) || !preg_match(self::IDENTIFIER, $id)) {
                throw new RuntimeException(
                    "Report semantics: invalid field id [{$entityName}.{$id}]."
                );
            }

            if (!is_array($field)) {
                throw new RuntimeException(
                    "Report semantics: field [{$entityName}.{$id}] must be an array."
                );
            }

            $type = strtolower(
                (string) $this->stringOr($field['type'] ?? null, 'text')
            );

            if (!in_array($type, self::TYPES, true)) {
                throw new RuntimeException(
                    "Report semantics: field [{$entityName}.{$id}] has unknown type [{$type}]."
                );
            }

            $json = null;
            $column = null;

            if (isset($field['json'])) {
                if (
                    !is_array($field['json']) ||
                    !isset($field['json']['column'])
                ) {
                    throw new RuntimeException(
                        "Report semantics: field [{$entityName}.{$id}] has a malformed json block."
                    );
                }

                $jsonColumn = (string) $field['json']['column'];
                $jsonPath = (string) ($field['json']['path'] ?? '$');

                if (!preg_match(self::IDENTIFIER, $jsonColumn)) {
                    throw new RuntimeException(
                        "Report semantics: field [{$entityName}.{$id}] has an invalid json column."
                    );
                }

                if (!preg_match(self::JSON_PATH, $jsonPath)) {
                    throw new RuntimeException(
                        "Report semantics: field [{$entityName}.{$id}] has an invalid json path [{$jsonPath}]."
                    );
                }

                $json = ['column' => $jsonColumn, 'path' => $jsonPath];
            } else {
                // A field with no explicit column maps onto its own id.
                $column = (string) ($field['column'] ?? $id);

                if (!preg_match(self::IDENTIFIER, $column)) {
                    throw new RuntimeException(
                        "Report semantics: field [{$entityName}.{$id}] has an invalid column name."
                    );
                }
            }

            $values = null;

            if ($type === 'enum') {
                $rawValues = $field['values'] ?? [];

                if (!is_array($rawValues)) {
                    throw new RuntimeException(
                        "Report semantics: enum field [{$entityName}.{$id}] must declare an array of values."
                    );
                }

                $values = [];

                // Keys are cast to string so json_encode always emits an
                // object. A 0/1 keyed array in ascending order would
                // otherwise serialise as a JSON array and the wizard would
                // lose the value keys.
                foreach ($rawValues as $key => $label) {
                    $values[(string) $key] = (string) $label;
                }
            }

            $sentinels = $field['null_sentinels'] ?? [];

            if (!is_array($sentinels)) {
                $sentinels = [$sentinels];
            }

            $normalised[$id] = [
                'id' => $id,
                'entity' => $entityName,
                'label' => $this->stringOr(
                    $field['label'] ?? null,
                    $this->humanise($id)
                ),
                'description' => $this->stringOr(
                    $field['description'] ?? null,
                    ''
                ),
                'type' => $type,
                'column' => $column,
                'json' => $json,
                'summable' =>
                    array_key_exists('summable', $field)
                        ? (bool) $field['summable']
                        : in_array($type, self::NUMERIC_TYPES, true),
                'values' => $values,
                'null_sentinels' => array_values(
                    array_filter($sentinels, 'is_scalar')
                ),
                'hidden' => (bool) ($field['hidden'] ?? false),
            ];
        }

        return $normalised;
    }

    /**
     * @param string $entityName
     * @param mixed $relations
     * @return array<string, array>
     */
    protected function normaliseRelations(string $entityName, $relations): array
    {
        if (!is_array($relations)) {
            throw new RuntimeException(
                "Report semantics: entity [{$entityName}] has a non-array relations block."
            );
        }

        $normalised = [];

        foreach ($relations as $name => $relation) {
            if (!is_string($name) || !preg_match(self::IDENTIFIER, $name)) {
                throw new RuntimeException(
                    "Report semantics: invalid relation id [{$entityName}.{$name}]."
                );
            }

            if (!is_array($relation) || !isset($relation['entity'])) {
                throw new RuntimeException(
                    "Report semantics: relation [{$entityName}.{$name}] must declare a target entity."
                );
            }

            $type = strtolower(
                (string) $this->stringOr(
                    $relation['type'] ?? null,
                    'belongs_to'
                )
            );

            if (!isset(self::CARDINALITY[$type])) {
                throw new RuntimeException(
                    "Report semantics: relation [{$entityName}.{$name}] has unknown type [{$type}]."
                );
            }

            if (!isset($relation['foreign_key'])) {
                throw new RuntimeException(
                    "Report semantics: relation [{$entityName}.{$name}] must declare a foreign_key."
                );
            }

            $foreignKey = (string) $relation['foreign_key'];
            $ownerKey = (string) ($relation['owner_key'] ?? 'id');
            $localKey = (string) ($relation['local_key'] ?? 'id');

            foreach (
                ['foreign_key' => $foreignKey, 'owner_key' => $ownerKey, 'local_key' => $localKey]
                as $keyName => $keyValue
            ) {
                if (!preg_match(self::IDENTIFIER, $keyValue)) {
                    throw new RuntimeException(
                        "Report semantics: relation [{$entityName}.{$name}] has an invalid {$keyName}."
                    );
                }
            }

            $normalised[$name] = [
                'name' => $name,
                'label' => $this->stringOr(
                    $relation['label'] ?? null,
                    $this->humanise($name)
                ),
                'description' => $this->stringOr(
                    $relation['description'] ?? null,
                    ''
                ),
                'entity' => (string) $relation['entity'],
                'type' => $type,
                'cardinality' => self::CARDINALITY[$type],
                'foreign_key' => $foreignKey,
                'owner_key' => $ownerKey,
                'local_key' => $localKey,
                'zero_is_null' => (bool) ($relation['zero_is_null'] ?? false),
                'hidden' => (bool) ($relation['hidden'] ?? false),
            ];
        }

        return $normalised;
    }

    /**
     * All normalised entities, keyed by id.
     *
     * @return array<string, array>
     */
    public function entities(): array
    {
        return $this->entities;
    }

    /**
     * @param string $entity
     * @return bool
     */
    public function hasEntity(string $entity): bool
    {
        return isset($this->entities[$entity]);
    }

    /**
     * @param string $entity
     * @param string|null $path Path reported in the error, if any.
     * @return array
     *
     * @throws SemanticException when the entity is not published.
     */
    public function entity(string $entity, $path = null): array
    {
        if (!isset($this->entities[$entity])) {
            throw SemanticException::forPath(
                $path ?? $entity,
                "Unknown entity [{$entity}]."
            );
        }

        return $this->entities[$entity];
    }

    /**
     * @param string $entity
     * @param string $field
     * @param string|null $path Full dot-path, for the error message.
     * @return array
     *
     * @throws SemanticException when the field is not published.
     */
    public function field(string $entity, string $field, $path = null): array
    {
        $definition = $this->entity($entity, $path);

        if (!isset($definition['fields'][$field])) {
            throw SemanticException::forPath(
                $path ?? $field,
                "Unknown field [{$field}] on [{$entity}]."
            );
        }

        return $definition['fields'][$field];
    }

    /**
     * @param string $entity
     * @param string $relation
     * @param string|null $path Full dot-path, for the error message.
     * @return array
     *
     * @throws SemanticException when the relation is not published.
     */
    public function relation(
        string $entity,
        string $relation,
        $path = null
    ): array {
        $definition = $this->entity($entity, $path);

        if (!isset($definition['relations'][$relation])) {
            throw SemanticException::forPath(
                $path ?? $relation,
                "Unknown relation [{$relation}] on [{$entity}]."
            );
        }

        return $definition['relations'][$relation];
    }

    /**
     * The model as the browser sees it.
     *
     * Every internal key is dropped: no table, column, json path, foreign
     * key or primary key leaves the server in semantic mode. Field ids stay
     * as they are - they are opaque handles as far as the client is
     * concerned, and they are what comes back in a definition.
     *
     * @return array{entities: array<string, array>}
     */
    public function toClientPayload(): array
    {
        $entities = [];

        foreach ($this->entities as $name => $entity) {
            // A hidden entity is still published, flagged. It is a lookup
            // ("Task types", "Notes") that nobody starts a report *about*,
            // but a relation has to be able to land on it and the wizard has
            // to be able to list its fields to offer "Their notes › Subject".
            // Dropping it here instead would silently sever every relation
            // pointing at it. The wizard reads the flag and leaves it out of
            // the "what are you reporting on?" picker.
            $fields = [];

            foreach ($entity['fields'] as $id => $field) {
                if ($field['hidden']) {
                    continue;
                }

                $projected = [
                    'label' => $field['label'],
                    'type' => $field['type'],
                ];

                if ($field['description'] !== '') {
                    $projected['description'] = $field['description'];
                }

                if ($field['summable']) {
                    $projected['summable'] = true;
                }

                if ($field['values'] !== null) {
                    // Cast to an object: PHP folds numeric string keys back
                    // to integers, so ['0' => ..., '1' => ...] would
                    // json_encode as the array ["Inactive","Active"] and the
                    // stored values would be lost on the wire.
                    $projected['values'] = (object) $field['values'];
                }

                $fields[$id] = $projected;
            }

            $relations = [];

            foreach ($entity['relations'] as $id => $relation) {
                if ($relation['hidden']) {
                    continue;
                }

                $projected = [
                    'label' => $relation['label'],
                    'entity' => $relation['entity'],
                    'cardinality' => $relation['cardinality'],
                ];

                if ($relation['description'] !== '') {
                    $projected['description'] = $relation['description'];
                }

                $relations[$id] = $projected;
            }

            $projectedEntity = [
                'label' => $entity['label'],
                'plural' => $entity['plural'],
                'description' => $entity['description'],
                // An empty PHP array encodes as [], and the wizard indexes
                // these by id - so they are forced to objects.
                'fields' => $this->asJsonObject($fields),
                'relations' => $this->asJsonObject($relations),
            ];

            // Sent only when true, so an ordinary entity's payload is
            // byte-for-byte what it was.
            if ($entity['hidden']) {
                $projectedEntity['hidden'] = true;
            }

            $entities[$name] = $projectedEntity;
        }

        return ['entities' => $this->asJsonObject($entities)];
    }

    /**
     * Operators permitted for a field type.
     *
     * @param string $type
     * @return array<int, string>
     */
    public static function operatorsFor(string $type): array
    {
        return self::OPERATORS[$type] ?? [];
    }

    /**
     * Keep an id-keyed map a JSON object even when it is empty.
     *
     * @param array $value
     * @return array|object
     */
    protected function asJsonObject(array $value)
    {
        return empty($value) ? (object) [] : $value;
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

        return $fallback;
    }

    /**
     * `fds_due_date` => `Fds due date`. Only used when a label is missing.
     *
     * @param string $value
     * @return string
     */
    protected function humanise(string $value): string
    {
        return ucfirst(trim(str_replace('_', ' ', $value)));
    }
}
