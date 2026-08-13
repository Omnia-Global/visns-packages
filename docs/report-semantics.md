# Report Semantics (report definition v2)

The semantic model lets non-technical users build reports in business
language. The application publishes a **registry** of entities, fields and
relations; the wizard shows labels; the compiler turns the resulting
definition into SQL. Table and column names never reach the browser, and the
registry is the allowlist - a column that is not published cannot be
selected, filtered or sorted on, whatever the request payload says.

- **Target database:** MySQL 8 (`JSON_EXTRACT` / `JSON_UNQUOTE`,
  `CAST(... AS DECIMAL(14,2))`). Everything except the JSON handling is
  standard SQL and is exercised against SQLite in the test suite.
- **v1 is untouched.** Reports saved with the old table-and-join builder keep
  running down the original code path; see [Back-compatibility](#7-back-compatibility).

Code:

| File | Role |
| --- | --- |
| `src/Services/ReportSemantics/SemanticModel.php` | Loads/validates the registry, serves the client-safe projection |
| `src/Services/ReportSemantics/QueryCompiler.php` | Definition v2 to query builder, execution, row shaping |
| `src/Services/ReportSemantics/SchemaInspector.php` | Cached column listings / `deleted_at` detection |
| `src/Services/ReportSemantics/SemanticException.php` | A rejected definition (422, with per-path detail) |
| `src/Controllers/SemanticModelController.php` | `POST ajax/reportBuilder/semanticModel` |
| `src/Controllers/ReportBuilderController.php` | v2 branch inside the existing execute/export endpoints |

---

## 1. Configuration

Everything lives under `config('visns-packages.report_semantics')`. The
published config file carries the same documentation as a comment block.

```php
'report_semantics' => [
    'connection' => null,   // null = application default connection
    'registrar'  => null,   // optional class, see 1.4
    'entities'   => [ /* ... */ ],
],
```

Nothing is published by default. An application opts in entity by entity,
field by field.

### 1.1 Entity

```php
'clients' => [
    'label'        => 'Client',    // singular. Default: humanised id ("Clients")
    'plural'       => 'Clients',   // Default: the label
    'description'  => 'People the practice advises',  // optional
    'table'        => 'clients',   // Default: the entity id
    'primary_key'  => 'id',        // Default: 'id'
    'soft_deletes' => null,        // null = introspect for deleted_at; true/false = authoritative
    'hidden'       => false,       // true = published with "hidden": true; reachable as a
                                   // relation target, not offered as a root. See 8.2.
    'fields'       => [ /* ... */ ],
    'relations'    => [ /* ... */ ],
],
```

The entity id, table name and primary key must match
`/^[A-Za-z_][A-Za-z0-9_]*$/`. Anything else throws at registry load - see
[section 6](#6-error-handling).

### 1.2 Field

```php
'fee_amount' => [
    'label'          => 'Fee amount',
    'column'         => 'fee_amount',      // Default: the field id
    'type'           => 'money',           // Default: 'text'
    'summable'       => true,              // Default: true for number/money/percent, else false
    'description'    => 'Ongoing advice fee',   // optional
    'null_sentinels' => ['1970-01-01'],    // values that MEAN null
    'values'         => [1 => 'Active', 0 => 'Inactive'],  // enum only
    'hidden'         => false,             // resolvable, but not advertised to the wizard
    'json'           => [                  // use INSTEAD OF 'column'
        'column' => 'home_address',
        'path'   => '$.suburb',
    ],
],
```

**Types:** `text`, `number`, `money`, `percent`, `date`, `datetime`,
`boolean`, `enum`. The type drives the operators offered, the casts applied
to JSON values, and how the value is serialised in a row.

**`json`** - the field is a value inside a JSON document column. The path is
validated against `/^\$(\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])*$/`, i.e. `$.a`,
`$.a.b`, `$.a[0].b`. Quoted or wildcard paths are rejected, because the path
is embedded in a SQL string literal.

**`null_sentinels`** - values a legacy schema uses to mean "not set"
(`1970-01-01`, `0000-00-00`, `0`, ...). They are returned as `null` in rows
and are matched by `is_empty` / excluded by `not_empty`.

**`values`** - required for `enum`. Keys are the stored column values, values
are the labels. Filter values are validated against these keys.

### 1.3 Relation

```php
'adviser' => [
    'label'        => 'Their adviser',
    'entity'       => 'users',       // must be another published entity
    'type'         => 'belongs_to',  // belongs_to | has_one | has_many. Default: belongs_to
    'foreign_key'  => 'user_id',     // REQUIRED
    'owner_key'    => 'id',          // belongs_to: the key on the target. Default: 'id'
    'local_key'    => 'id',          // has_one/has_many: the key here. Default: 'id'
    'zero_is_null' => true,          // a 0 foreign key means "none"
    'hidden'       => false,
],
```

- `belongs_to` joins `this.foreign_key = target.owner_key`.
- `has_one` / `has_many` join `this.local_key = target.foreign_key`.
- Cardinality reported to the client: `one` for belongs_to/has_one, `many`
  for has_many.
- `zero_is_null` adds `owning_key <> 0` to the join condition, so a legacy
  `user_id = 0` never joins to whatever row happens to have id 0.

### 1.4 Registrar hook

For registries that need to be computed:

```php
'registrar' => \App\Reporting\ReportSemantics::class,
```

The class is resolved from the container and must expose `entities(): array`
or be invokable. It may return either the entity map or the whole
`['entities' => [...]]` block. Its entities are merged over the config
entities **one entity at a time** (`array_replace`), so a registrar that
redefines `clients` replaces that entity outright rather than deep-merging
into it. The config array remains the primary path.

---

## 2. The model endpoint

```
POST ajax/reportBuilder/semanticModel
```

Registered inside the existing `report_builder_middleware` group
(`['web', 'auth']` by default), so it is never public.

```json
{
  "success": true,
  "data": {
    "entities": {
      "clients": {
        "label": "Clients",
        "plural": "Clients",
        "description": "People the practice advises",
        "fields": {
          "firstname": {"label": "First name", "type": "text"},
          "fee_amount": {"label": "Fee amount", "type": "money", "summable": true},
          "status": {"label": "Status", "type": "enum", "values": {"1": "Active", "0": "Inactive"}}
        },
        "relations": {
          "adviser": {"label": "Their adviser", "entity": "users", "cardinality": "one"},
          "notes": {"label": "Their notes", "entity": "client_notes", "cardinality": "many"}
        }
      }
    },
    "operators": {"text": ["equals", "..."], "money": ["..."]},
    "field_types": ["text", "number", "..."],
    "aggregates": ["sum", "count", "avg", "min", "max"],
    "parameter_types": ["text", "number", "date", "date_range", "enum"],
    "schema_version": 2
  }
}
```

`data.entities` is the contract. `operators`, `field_types`, `aggregates`,
`parameter_types` and `schema_version` are **additive** - the compiler is the
authority on which operators suit which type, so the wizard can build its
pickers from the response instead of hard-coding a duplicate table.

Field ids are opaque handles. `label`, `type`, `summable`, `values`,
`description`, `entity` and `cardinality` are the only keys that ever leave
the server; `table`, `column`, `json`, `primary_key`, `foreign_key`,
`owner_key`, `local_key`, `zero_is_null`, `soft_deletes` and
`null_sentinels` are stripped.

---

## 3. Report definition v2

Sent as `detail` when saving, as `definition` when executing or exporting.

```json
{
  "schema_version": 2,
  "entity": "clients",
  "fields": [
    {"field": "firstname"},
    {"field": "adviser.name"},
    {"agg": "sum", "field": "fee_amount", "label": "Total fees"}
  ],
  "filters": {"op": "and", "items": [
    {"field": "status", "operator": "equals", "value": "1"},
    {"op": "or", "items": [
      {"field": "home_email", "operator": "not_empty"},
      {"field": "work_email", "operator": "not_empty"}
    ]},
    {"field": "fds_due_date", "operator": "between", "param": "due_range"}
  ]},
  "parameters": [
    {"id": "due_range", "label": "Due date range", "type": "date_range", "required": true}
  ],
  "groupBy": ["adviser.name"],
  "sort": [{"field": "surname", "dir": "asc"}]
}
```

### 3.1 Paths

A field is addressed by a dot-path: `fee_amount`, `adviser.name`,
`adviser.team.name`. Every segment but the last is a **relation** on the
current entity; the last is a **field**. Chained hops work as long as each
hop is declared on the entity it starts from.

Relations become `LEFT JOIN`s aliased by relation *path*, so the same entity
reached two ways (`adviser.name`, `referrer.name`, both landing on `users`)
never collides.

### 3.2 Fields / aggregates

| Key | Meaning |
| --- | --- |
| `field` | Dot-path. Required, except for `{"agg": "count"}` |
| `agg` | `sum` \| `count` \| `avg` \| `min` \| `max` |
| `label` | Row key / column header override |

- `sum` and `avg` require a `number`/`money`/`percent` field, or any field
  marked `summable`. `count`, `min` and `max` work on any type.
- When **any** aggregate is present, every non-aggregate field must appear in
  `groupBy`. If `groupBy` is absent or empty, it is inferred as "every plain
  field groups".
- The **row key** is `label` when given, otherwise the field path verbatim
  (`"adviser.name"`), otherwise `agg(path)` for an unlabelled aggregate
  (`"sum(fee_amount)"`).

### 3.3 Filters

A group is `{"op": "and"|"or", "items": [...]}` and nests arbitrarily. A leaf
is `{"field": path, "operator": op, "value": v}` or
`{"field": path, "operator": op, "param": id}`.

Operators, by field type:

| Type | Operators |
| --- | --- |
| `text` | equals, not_equals, contains, not_contains, is_empty, not_empty |
| `number`, `money`, `percent` | equals, not_equals, gt, gte, lt, lte, between, is_empty, not_empty |
| `date`, `datetime` | equals, before, after, between, is_empty, not_empty |
| `boolean` | is_true, is_false |
| `enum` | equals, not_equals, in, not_in |

An operator that is not listed for the field's type is **rejected with a
422** - there is no fall-through to equality. `between` needs a two-element
`[from, to]`; `in` / `not_in` need a non-empty array.

Note the contract gives `boolean` and `enum` no emptiness operators, and this
implementation follows it exactly. A nullable flag that needs an "is it set"
filter should be published as a `text` or `number` field instead.

### 3.4 Parameters

```json
{"id": "due_range", "label": "Due date range", "type": "date_range", "required": true}
```

Types: `text`, `number`, `date`, `date_range`, `enum`. Values arrive with the
execute/export request:

```json
{"parameters": {"due_range": ["2026-01-01", "2026-03-31"]}}
```

- A **required** parameter with no value gives a **422**.
- An **optional** parameter with no value means the condition it feeds is
  **dropped** from the query.
- A parameter referenced by a filter but not declared is a 422.
- An `enum` parameter is validated against the declared values of the field
  the filter is applied to, at binding time.

### 3.5 groupBy / sort

`groupBy` is a list of field paths. `sort` is a list of
`{"field": ..., "dir": "asc"|"desc"}`; `field` may be a field path **or** an
output key (a plain path or an aggregate's label). `dir` defaults to `asc`.

---

## 4. Execute

```
POST ajax/reportBuilder/execute
{"definition": {...}, "parameters": {...}, "limit": 100, "offset": 0}
```

```json
{
  "success": true,
  "data": [
    {"firstname": "Alice", "adviser.name": "Ada", "Total fees": 350}
  ],
  "total": 42,
  "columns": ["firstname", "adviser.name", "Total fees"]
}
```

Each row is keyed **exactly** by the field path or aggregate label as given.
`total` ignores limit/offset. `columns` is additive: the row keys in
definition order, so a client need not infer column order from the first row.
`sql` is added only when `app.debug` is on.

Value serialisation:

| Type | In the row |
| --- | --- |
| `text`, `enum`, `boolean` | raw, exactly as stored (`"1"` / `1` / `0`) |
| `number`, `money`, `percent` | JSON number, unformatted |
| `date` | `Y-m-d` |
| `datetime` | `Y-m-d H:i:s` |
| any declared sentinel | `null` |

Formatting (currency symbols, enum labels, yes/no) stays with the frontend.

## 5. Export

```
POST ajax/reportBuilder/export
{"definition": {...}, "parameters": {...}, "format": "xlsx"}
```

Same detection, same compiler; the result set is handed to the existing
`generateExportFile()`, which takes its headers from the row keys - so the v2
column labels carry through to CSV/XLSX/PDF unchanged. Formats, the PDF row
limit (`report_export.pdf_max_rows`), the auto-switch-to-CSV behaviour and
the "no data" 404 all behave exactly as they do for v1.

## 6. Error handling

A bad **definition** is the caller's problem and comes back in full:

```json
{
  "success": false,
  "message": "Unknown field [salary] on [clients]. (at: salary)",
  "errors": [{"path": "salary", "message": "Unknown field [salary] on [clients]."}]
}
```

HTTP 422, except 403 when `report_id` names a report the user may not read.
`path` is the offending dot-path, parameter id (`parameters.due_range`) or
definition position (`fields[2]`, `filters.items[1].items[0]`), so the wizard
can highlight the control that caused it.

A bad **registry** is a deployment fault: it throws, is logged in full, and
the client gets a 500 with a `correlation_id` only - the same contract as the
Wave-1 `errorResponse()` helper.

---

## 7. Back-compatibility

`executeQuery()` and `exportReport()` sniff the request before doing anything
else, in this order:

1. `definition` - a v2 payload;
2. `query` - tolerated, for a client that reuses the v1 key with a v2 body;
3. `report_id` - when no inline definition was sent and the saved report's
   `detail` is v2 (the caller's read permission is checked).

A payload is v2 when `schema_version >= 2`, **or** when it has a non-empty
`entity` and no `mainTable`. Everything else returns null from the sniffer
and runs the original v1 code, unchanged.
`tests/Unit/ReportSemanticsVersionDetectionTest.php` locks this in.

Saving and loading need no changes: `detail` is opaque JSON. The
`ReportBuilder` model docblock documents both schemas; `getSelectedTables()`
is a v1 helper and returns `[]` for a v2 definition.

---

## 8. Design decisions where the contract was silent

Everything below was chosen by this implementation. A registry author should
read this section before assuming behaviour.

### Registry

1. **Defaults.** `column` defaults to the field id; `type` to `text`;
   `label` to the humanised id (`fds_due_date` gives "Fds due date");
   `table` to the entity id; `primary_key` to `id`; relation `type` to
   `belongs_to`; `owner_key`/`local_key` to `id`; `summable` to true for
   number/money/percent and false otherwise. `foreign_key` has no default -
   it is required, because guessing it is how you get a silently wrong join.
2. **`hidden`.** Fields and relations accept `hidden => true`: still
   resolvable by a definition, but omitted from the model endpoint. Useful
   for a key a saved report needs but the picker should not show.
   A hidden **entity** works differently, because severing it from the
   payload would silently break every relation pointing at it: it is still
   published, carrying `"hidden": true`, and relations to it are still
   advertised. The wizard keeps it out of the root picker only, so a lookup
   like `client_notes` is reachable as "Their notes › Subject" but is never
   offered as something to start a report about.
3. **Identifier validation at load.** Table/column/key names must match
   `/^[A-Za-z_][A-Za-z0-9_]*$/` and JSON paths a strict pattern; a registry
   that breaks this throws at load rather than being escaped at each use.
4. **Registrar precedence** is entity-level replace, not deep merge (1.4).
5. **`soft_deletes`** may be declared explicitly to skip schema
   introspection; `null` (the default) means "look for a `deleted_at`
   column", cached for 600s under the same cache key Wave-1 uses.
6. **`connection`** config key selects the database connection; `null` uses
   the application default.
7. **Enum `values` and empty maps are forced to JSON objects.** PHP folds
   numeric string keys back to integers, so `{"0": ..., "1": ...}` would
   otherwise serialise as the array `["Inactive","Active"]` and lose the
   stored values.
8. **An empty registry is a 200**, not an error: `{"entities": {}}`, which
   the wizard can render as "reporting is not configured here".

### Definition parsing

9. **Unlabelled aggregate row key** is `agg(path)`, e.g. `sum(fee_amount)`.
10. **`label` works on plain fields too**, renaming the row key, not only on
    aggregates.
11. **Duplicate row keys are a 422.** Two columns cannot share a key,
    because the row is an object.
12. **`{"agg": "count"}` with no `field`** compiles to `COUNT(*)`, keyed
    `count(*)`.
13. **Shorthand accepted:** a bare string in `fields` or `sort` means
    `{"field": "..."}`; `filters` given as a plain list is an implicit `and`
    group.
14. **Unknown aggregate names are rejected** (`median` gives a 422), not
    ignored.
15. **`schema_version` 3+ also routes to the semantic compiler** - a newer
    definition should fail loudly here, not silently run as v1.

### Filters and values

16. **Negations include NULL.** `not_equals`, `not_contains` and `not_in`
    are written as `(expr <> ? or expr is null)`. In plain SQL a row with no
    value would drop out of a "not equal to" filter, which is never what a
    business user means.
17. **`is_false` includes NULL**; `is_true` is strictly `= 1`.
18. **Emptiness** is `IS NULL`, plus `= ''` for `text`/`enum` only (an empty
    string compared to a numeric or date column is coerced by MySQL and
    would match zero), plus every declared sentinel.
19. **Emptiness tests use the uncast expression** while comparisons and
    sorting use the cast one - `CAST('' AS DECIMAL)` is `0`, which would
    make `is_empty` mean "equals zero" on a JSON numeric field.
20. **JSON fields get `'null'` as an automatic extra sentinel**:
    `JSON_UNQUOTE(JSON_EXTRACT(...))` returns the four-character string
    `null` for a JSON null. A text field whose legitimate value is the word
    "null" is the (accepted) casualty.
21. **Sentinels are applied to plain columns, not to aggregate results** -
    unmapping a `SUM` back to null is meaningless.
22. **Enum filter values are validated against the declared keys** (422 for
    anything else), for `equals`, `not_equals`, `in` and `not_in`.
23. **Numeric filter values must be numeric**, date values must match
    `YYYY-MM-DD[ HH:MM[:SS]]`. Free-form date strings are rejected rather
    than passed to `strtotime()`, which would invent a date from a typo.
24. **Date semantics.** For a `date` field the comparison is against the bare
    date. For a `datetime` field a bare date is widened to the whole day:
    `equals` gives `BETWEEN 00:00:00 AND 23:59:59`, `before` gives
    `< 00:00:00`, `after` gives `> 23:59:59`, `between [a,b]` gives
    `a 00:00:00 .. b 23:59:59`. An explicit time in the value is honoured as
    given.
25. **LIKE wildcards in `contains` are escaped** with `!` and an explicit
    `ESCAPE '!'` clause - not a backslash, whose meaning depends on MySQL's
    `NO_BACKSLASH_ESCAPES` mode and which SQLite ignores entirely.
26. **A blank parameter value** (`null`, `""`, `[]`, or an array whose every
    element is itself blank, e.g. the `["", ""]` a wizard sends for an
    untouched date range) counts as "not supplied".
    Declared-but-unreferenced required parameters are not validated - only
    parameters an actual filter reads.

### SQL shape

27. **Aliasing.** The base table is not aliased (it keeps its own name);
    each relation path is aliased `rel_<path with dots as __>`, falling back
    to `rel_<md5 prefix>` beyond 60 characters (MySQL's 64-char identifier
    limit).
28. **Select aliases are generated (`c0`, `c1`, ...) and re-keyed in PHP.**
    Using the user's label as a SQL alias would mean quoting arbitrary text
    (`Total fees ($)`) inside the statement; this side-steps it and makes
    `ORDER BY` on an aggregate label trivial.
29. **Joins are always LEFT**, and the join-side predicates - the joined
    table's `deleted_at IS NULL` and the `zero_is_null` guard - live in the
    `ON` clause. Moved to `WHERE` they would silently turn every LEFT JOIN
    into an inner one and drop rows that merely lack a related record. (The
    base table's soft-delete predicate is a `WHERE`, as in Wave-1.)
30. **`zero_is_null` applies to the owning side's key**: the parent's
    `foreign_key` for `belongs_to`, the parent's `local_key` for
    `has_one`/`has_many`.
31. **No automatic `DISTINCT`.** Selecting through a `has_many` relation
    multiplies rows, which is correct for a detail report and is why the
    intended use of a to-many relation is an aggregate (a `count` of
    `notes.body`). Adding DISTINCT would silently corrupt `sum`.
32. **JSON casts:** `DECIMAL(14,2)` for number/money/percent, `DATE` /
    `DATETIME` for date types, no cast for text/enum/boolean.
33. **Grouping rule.** `groupBy` is inferred only when absent or empty. When
    it *is* given, the "every non-aggregate field must be grouped" rule is
    enforced whether or not an aggregate is present.
34. **Sorting on a grouped report** is limited to output keys (including
    aggregate labels) and grouped paths; anything else is a 422 rather than
    a database error under `ONLY_FULL_GROUP_BY`.
35. **`total`** is a plain `COUNT` for an ungrouped report and a count of
    *groups* (a wrapping subquery) for a grouped one.
36. **Pagination:** default limit 100, hard cap 10,000 for execute; a
    missing, non-numeric or non-positive limit falls back to the default, a
    negative offset to 0. Export uses `report_export.max_rows` (default
    100,000) as both its limit and its ceiling.

### Result shaping

37. **Aggregate result types:** `count` gives an integer; `sum`/`avg` are
    numeric; `min`/`max` keep the source field's type, so `min` over a date
    still serialises as a date.
38. **Numeric values are returned as JSON numbers**, not the driver's
    strings - "raw" in the contract means unformatted, and a string
    `"100.00"` is a formatting decision the frontend should not have to undo.
39. **An unparseable date is returned untouched** rather than nulled: it is
    data the report owner needs to see.

---

## 9. Worked example

A registry for the entities used in the payload above:

```php
'report_semantics' => [
    'entities' => [
        'clients' => [
            'label' => 'Client',
            'plural' => 'Clients',
            'description' => 'People the practice advises',
            'table' => 'clients',
            'fields' => [
                'firstname'    => ['label' => 'First name', 'type' => 'text'],
                'surname'      => ['label' => 'Surname', 'type' => 'text'],
                'home_email'   => ['label' => 'Home email', 'type' => 'text'],
                'work_email'   => ['label' => 'Work email', 'type' => 'text'],
                'fee_amount'   => ['label' => 'Fee amount', 'type' => 'money', 'summable' => true],
                'home_suburb'  => [
                    'label' => 'Home suburb',
                    'type' => 'text',
                    'json' => ['column' => 'home_address', 'path' => '$.suburb'],
                ],
                'fds_due_date' => [
                    'label' => 'FDS due date',
                    'type' => 'date',
                    'null_sentinels' => ['1970-01-01'],
                ],
                'status' => [
                    'label' => 'Status',
                    'column' => 'status_id',
                    'type' => 'enum',
                    'values' => [1 => 'Active', 0 => 'Inactive'],
                ],
            ],
            'relations' => [
                'adviser' => [
                    'label' => 'Their adviser',
                    'entity' => 'users',
                    'type' => 'belongs_to',
                    'foreign_key' => 'user_id',
                    'owner_key' => 'id',
                    'zero_is_null' => true,
                ],
                'notes' => [
                    'label' => 'Their notes',
                    'entity' => 'client_notes',
                    'type' => 'has_many',
                    'foreign_key' => 'client_id',
                    'local_key' => 'id',
                ],
            ],
        ],

        'users' => [
            'label' => 'Adviser',
            'plural' => 'Advisers',
            'table' => 'users',
            'fields' => [
                'name'  => ['label' => 'Name', 'type' => 'text'],
                'email' => ['label' => 'Email', 'type' => 'text'],
            ],
        ],

        'client_notes' => [
            'label' => 'Note',
            'plural' => 'Notes',
            'table' => 'client_notes',
            'fields' => [
                'body'       => ['label' => 'Note', 'type' => 'text'],
                'created_at' => ['label' => 'Created', 'type' => 'datetime'],
            ],
        ],
    ],
],
```

"Total fees per adviser, active clients with an email address, due in a
window the user picks":

```json
{
  "schema_version": 2,
  "entity": "clients",
  "fields": [
    {"field": "adviser.name"},
    {"agg": "sum", "field": "fee_amount", "label": "Total fees"},
    {"agg": "count", "field": "firstname", "label": "Clients"}
  ],
  "filters": {"op": "and", "items": [
    {"field": "status", "operator": "equals", "value": "1"},
    {"op": "or", "items": [
      {"field": "home_email", "operator": "not_empty"},
      {"field": "work_email", "operator": "not_empty"}
    ]},
    {"field": "fds_due_date", "operator": "between", "param": "due_range"}
  ]},
  "parameters": [
    {"id": "due_range", "label": "Due date range", "type": "date_range", "required": true}
  ],
  "groupBy": ["adviser.name"],
  "sort": [{"field": "Total fees", "dir": "desc"}]
}
```

---

## 10. Tests

| File | Covers |
| --- | --- |
| `tests/Unit/ReportSemanticModelTest.php` | Registry normalisation, defaults, identifier/JSON-path rejection, client-payload stripping, enum key serialisation, registrar merge |
| `tests/Feature/ReportSemanticsCompilerTest.php` | The compiler against in-memory SQLite: path resolution, 422s, operator/type validation, AND/OR nesting, parameters, aggregates and groupBy, sentinels, soft deletes, pagination, row keys, plus MySQL-only JSON and zero-FK SQL asserted on `toSql()` |
| `tests/Unit/ReportSemanticsVersionDetectionTest.php` | v1 passthrough and v2 detection |

The package has no consuming application, so the suite is run file by file:

```bash
vendor/bin/phpunit tests/Unit/ReportSemanticModelTest.php
vendor/bin/phpunit tests/Unit/ReportSemanticsVersionDetectionTest.php
vendor/bin/phpunit tests/Feature/ReportSemanticsCompilerTest.php
```
