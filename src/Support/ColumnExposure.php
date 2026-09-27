<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * May a request filter, sort or select by this column of this model?
 *
 * A column a model hides from its JSON (`$hidden`) is not something a grid
 * displays, and a filter or a sort on it answers a yes/no question about the
 * hidden value one row at a time - a password hash or a token can be read a
 * character at a time that way. So a column is refused when:
 *
 *   - it is in the model's getHidden(), or
 *   - Support\ReportSchemaPolicy's column rule denies it (a name that says it
 *     holds a secret, plus `report_builder.denied_columns`) - the same list
 *     the report builder uses, not a second one.
 *
 * A JSON path (`details->status`, `details.status`) and a table-qualified name
 * (`users.password`) are judged by their base column.
 */
final class ColumnExposure
{
    public static function allowed(?Model $model, mixed $column): bool
    {
        if (!is_string($column) || $column === '') {
            return false;
        }

        $base = self::baseColumn($model, $column);

        if ($base === '' || $base === '*') {
            return $base === '*';
        }

        if (!(new ReportSchemaPolicy())->columnAllowed($base)) {
            return false;
        }

        if ($model) {
            $hidden = array_map('strtolower', $model->getHidden());
            if (in_array(strtolower($base), $hidden, true)) {
                return false;
            }
        }

        return true;
    }

    /** The column a possibly qualified or JSON-pathed name is about. */
    public static function baseColumn(?Model $model, string $column): string
    {
        $column = trim($column);

        if (str_contains($column, '->')) {
            $column = explode('->', $column, 2)[0];
        }

        // `table.column` where the table is the model's own: the column.
        // Anything else dotted is a JSON path: its first segment.
        if (str_contains($column, '.')) {
            $parts = explode('.', $column);
            if ($model && count($parts) === 2 && $parts[0] === $model->getTable()) {
                return $parts[1];
            }

            return $parts[0];
        }

        return $column;
    }
}
