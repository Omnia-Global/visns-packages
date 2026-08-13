<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved report.
 *
 * `detail` is opaque JSON and holds one of two schemas:
 *
 * v1 (no `schema_version`) - the table-and-join builder:
 *   {"mainTable": "clients", "columns": [...], "joins": [...],
 *    "filters": [...], "sorting": [...], "groupBy": [...]}
 *
 * v2 (`schema_version: 2`) - the semantic model, where every path is an
 * entity/field id resolved through the report semantics registry rather than
 * a table or column name:
 *   {"schema_version": 2, "entity": "clients",
 *    "fields": [{"field": "firstname"}, {"agg": "sum", "field": "fee_amount",
 *                "label": "Total fees"}],
 *    "filters": {"op": "and", "items": [...]},
 *    "parameters": [...], "groupBy": [...], "sort": [...]}
 *
 * Saving and loading are schema agnostic - the column is written and read
 * verbatim. Only execution and export branch, on `schema_version >= 2` (or
 * the presence of `entity`). Helpers below such as getSelectedTables() only
 * make sense for v1 and return an empty list for a v2 definition.
 *
 * @see \Visnsstudio\VisnsPackages\Services\ReportSemantics\QueryCompiler
 * @see docs/report-semantics.md
 */
class ReportBuilder extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'label',
        'detail',
        'user_id',
        'is_public',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'detail' => 'array',
        'is_public' => 'boolean',
    ];

    /**
     * Get the user that owns the report.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('visns-packages.user_model', 'App\\Models\\User'));
    }

    /**
     * Get the report configuration.
     *
     * @return array
     */
    public function getConfiguration(): array
    {
        return $this->detail ?? [];
    }

    /**
     * Set the report configuration.
     *
     * @param array $config
     * @return self
     */
    public function setConfiguration(array $config): self
    {
        $this->detail = $config;
        return $this;
    }

    /**
     * Get the selected tables from the report configuration.
     *
     * The builder stores the main table under `mainTable` and every further
     * table as the `targetTable` of a join - there is no `tables` key, which
     * is why this used to always come back empty.
     *
     * @return array List of table names: the main table first, then each
     *               joined table, in join order and without duplicates.
     */
    public function getSelectedTables(): array
    {
        $detail = $this->detail ?? [];
        $tables = [];

        $mainTable = $detail['mainTable'] ?? null;
        if (is_string($mainTable) && $mainTable !== '') {
            $tables[] = $mainTable;
        }

        $joins = $detail['joins'] ?? [];
        if (is_array($joins)) {
            foreach ($joins as $join) {
                $targetTable = is_array($join)
                    ? $join['targetTable'] ?? null
                    : null;

                if (is_string($targetTable) && $targetTable !== '') {
                    $tables[] = $targetTable;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Get the selected columns from the report configuration.
     *
     * @return array
     */
    public function getSelectedColumns(): array
    {
        return $this->detail['columns'] ?? [];
    }

    /**
     * Get the joins from the report configuration.
     *
     * @return array
     */
    public function getJoins(): array
    {
        return $this->detail['joins'] ?? [];
    }

    /**
     * Get the filters from the report configuration.
     *
     * @return array
     */
    public function getFilters(): array
    {
        return $this->detail['filters'] ?? [];
    }

    /**
     * Get the sorting from the report configuration.
     *
     * @return array
     */
    public function getSorting(): array
    {
        return $this->detail['sorting'] ?? [];
    }
}
