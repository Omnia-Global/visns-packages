<?php

namespace Visnsstudio\VisnsPackages\Services\ReportSemantics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cached schema lookups shared by the report builder.
 *
 * This is the Wave-1 `getCachedColumnListing()` / `tableHasDeletedAt()` pair
 * lifted out of the controller so the semantic compiler can reuse it without
 * duplicating the caching rules. The cache key prefix is deliberately
 * identical to the controller's, so both paths share the same entries.
 *
 * The instance-level `$deletedAt` map is intentionally not static: the
 * package runs under Octane, where a static would survive between requests
 * and outlive a migration.
 */
class SchemaInspector
{
    /**
     * Seconds a column listing is cached for. Listings only change on
     * migration, and the report builder walks the schema on every execution.
     */
    const CACHE_TTL = 600;

    /**
     * Per-instance `deleted_at` lookup cache: table => bool.
     *
     * @var array<string, bool>
     */
    protected $deletedAt = [];

    /**
     * A table's column listing, cached for {@see self::CACHE_TTL} seconds.
     *
     * @param string $table
     * @return array<int, string>
     */
    public function columnListing(string $table): array
    {
        return Cache::remember(
            'visns:report-builder:columns:' . $table,
            self::CACHE_TTL,
            function () use ($table) {
                return Schema::getColumnListing($table);
            }
        );
    }

    /**
     * Whether a table is soft-deletable (i.e. has a `deleted_at` column).
     *
     * An unreadable table is reported as not soft-deletable rather than
     * failing the whole report - the same call the Wave-1 controller makes.
     *
     * @param string $table
     * @return bool
     */
    public function hasDeletedAt(string $table): bool
    {
        if (array_key_exists($table, $this->deletedAt)) {
            return $this->deletedAt[$table];
        }

        try {
            $this->deletedAt[$table] = in_array(
                'deleted_at',
                $this->columnListing($table),
                true
            );
        } catch (\Exception $e) {
            Log::warning(
                "Error checking deleted_at on table {$table}: " .
                    $e->getMessage()
            );
            $this->deletedAt[$table] = false;
        }

        return $this->deletedAt[$table];
    }

    /**
     * Force a table's soft-delete answer, skipping introspection.
     *
     * Used when an entity declares `soft_deletes` in the registry: an
     * explicit declaration is authoritative and saves a schema round trip.
     *
     * @param string $table
     * @param bool $hasDeletedAt
     * @return void
     */
    public function assumeDeletedAt(string $table, bool $hasDeletedAt): void
    {
        $this->deletedAt[$table] = $hasDeletedAt;
    }
}
