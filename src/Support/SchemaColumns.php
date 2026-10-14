<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Has this column been migrated yet? Asked once per table and column per
 * application instance.
 *
 * For the columns 4.19.0 added (`client_id` on the messaging tables and on
 * `oauth_connections`). An application updates the package and runs its
 * migrations as two separate steps, and between them every query that named a
 * column the table does not have yet would be a 500 - on the inbox, on the
 * send path, and on Settings -> Integrations, none of which needed the column
 * the day before. So the house-only reading ("client_id is null") degrades to
 * "every row is the house's" until the column exists, which is exactly what
 * every row WAS before the column existed.
 *
 * Memoised in the container rather than in a static, so a test (which boots a
 * fresh application per test) never inherits the previous test's answer.
 */
class SchemaColumns
{
    private const KEY = 'visns-packages.schema-columns';

    public static function has(string $table, string $column): bool
    {
        $app = app();

        if (! $app->bound(self::KEY)) {
            $app->instance(self::KEY, new \ArrayObject());
        }

        /** @var \ArrayObject $memo */
        $memo = $app->make(self::KEY);
        $key = $table . '.' . $column;

        if (! isset($memo[$key])) {
            try {
                $memo[$key] = Schema::hasColumn($table, $column);
            } catch (\Throwable $e) {
                // No database to ask is not a column that exists.
                return false;
            }
        }

        return (bool) $memo[$key];
    }

    /** Forget every answer - for a migration run mid-request, i.e. a test. */
    public static function flush(): void
    {
        $app = app();

        if ($app->bound(self::KEY)) {
            $app->forgetInstance(self::KEY);
        }
    }
}
