<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Support\Facades\Schema;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticException;

/**
 * Which tables and columns the free-form (v1) report builder may name.
 *
 * The builder lists the schema and runs SELECTs built from the request, so
 * without a policy a report can read any table in the database — password
 * hashes, session rows, OAuth tokens, integration credentials. Identifiers are
 * quoted by Laravel's grammar, so the exposure is WHICH table or column is
 * named, not injection through the name; this class answers that question in
 * one place for the listings and for every query.
 *
 * Deny by name, with a config hook either way:
 *
 *   visns-packages.report_builder.denied_tables   extra tables to hide
 *   visns-packages.report_builder.allowed_tables  when set, the ONLY tables
 *   visns-packages.report_builder.denied_columns  extra exact column names
 *
 * The semantic (v2) builder is untouched: it only ever names what the
 * application's registry declares.
 */
final class ReportSchemaPolicy
{
    /** Framework and credential tables no report has a reason to read. */
    public const DENIED_TABLES = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
        'migrations', 'password_reset_tokens', 'password_resets',
        'personal_access_tokens', 'sessions',
        'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients',
        'oauth_personal_access_clients', 'oauth_refresh_tokens',
        'integration_settings', 'webauthn_credentials',
        'vault_entries', 'vault_access_logs', 'vault_shares', 'vault_share_links',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
    ];

    /**
     * A column whose name says it holds a secret. Matched on whole
     * underscore-separated words, so `password`, `two_factor_secret`,
     * `remember_token` and `api_key` are hidden while `tokens_used` is not.
     */
    public const DENIED_COLUMN_PATTERN =
        '/(^|_)(password|passwd|secret|token|api_key|apikey|private_key|credentials|recovery_codes|otp_code)($|_)/i';

    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/';

    public function tableAllowed(?string $table): bool
    {
        if (!is_string($table) || !preg_match(self::IDENTIFIER, $table)) {
            return false;
        }

        $name = strtolower($table);

        $allowed = config('visns-packages.report_builder.allowed_tables');
        if (is_array($allowed) && $allowed !== []) {
            if (!in_array($name, array_map('strtolower', $allowed), true)) {
                return false;
            }
        }

        $denied = array_merge(
            self::DENIED_TABLES,
            (array) config('visns-packages.report_builder.denied_tables', [])
        );

        return !in_array($name, array_map('strtolower', $denied), true);
    }

    public function columnAllowed(?string $column): bool
    {
        if (!is_string($column) || !preg_match(self::IDENTIFIER, $column)) {
            return false;
        }

        if (preg_match(self::DENIED_COLUMN_PATTERN, $column)) {
            return false;
        }

        $denied = array_map(
            'strtolower',
            (array) config('visns-packages.report_builder.denied_columns', [])
        );

        return !in_array(strtolower($column), $denied, true);
    }

    /** @param array<int, string> $tables */
    public function filterTables(array $tables): array
    {
        return array_values(array_filter($tables, fn ($t) => $this->tableAllowed($t)));
    }

    /** @param array<int, string> $columns */
    public function filterColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn ($c) => $this->columnAllowed($c)));
    }

    /** Refuse a table this policy will not report on (422, client safe). */
    public function assertTable(?string $table, string $path = 'mainTable'): void
    {
        if (!$this->tableAllowed($table) || !Schema::hasTable($table)) {
            throw SemanticException::forPath($path, 'That table is not available for reporting.');
        }
    }

    public function assertColumn(?string $column, string $path): void
    {
        if (!$this->columnAllowed($column)) {
            throw SemanticException::forPath($path, 'That column is not available for reporting.');
        }
    }

    /**
     * Every table and column a v1 query configuration names: the main table,
     * each join's two ends, the selected columns, calculated formulas, the
     * filters (nested groups included), the sorting and the distinct field.
     */
    public function assertQueryConfig(array $config): void
    {
        $main = $config['mainTable'] ?? null;
        $this->assertTable(is_string($main) ? $main : null);

        foreach ((array) ($config['joins'] ?? []) as $i => $join) {
            if (!is_array($join)) {
                continue;
            }
            foreach (['sourceTable', 'targetTable'] as $key) {
                if (isset($join[$key])) {
                    $this->assertTable((string) $join[$key], "joins.{$i}.{$key}");
                }
            }
            foreach (['sourceColumn', 'targetColumn'] as $key) {
                if (isset($join[$key])) {
                    $this->assertColumn((string) $join[$key], "joins.{$i}.{$key}");
                }
            }
        }

        foreach ((array) ($config['columns'] ?? []) as $i => $column) {
            if (!is_array($column)) {
                continue;
            }
            if (!empty($column['isCalculated'])) {
                $this->assertFormula((string) ($column['formula'] ?? ''), "columns.{$i}.formula");
                continue;
            }
            $this->assertReference($column, "columns.{$i}");
        }

        $this->assertFilters((array) ($config['filters'] ?? []), 'filters');

        foreach ((array) ($config['sorting'] ?? []) as $i => $sort) {
            if (is_array($sort)) {
                $this->assertReference($sort, "sorting.{$i}");
            }
        }

        $unique = $config['unique'] ?? null;
        if (is_array($unique) && !empty($unique['field'])) {
            $parts = explode('.', (string) $unique['field']);
            if (count($parts) === 2) {
                $this->assertTable($parts[0], 'unique.field');
            }
            $this->assertColumn(end($parts), 'unique.field');
        }
    }

    /** A formula may not mention a denied table or column by name. */
    public function assertFormula(string $formula, string $path): void
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $formula, $m);

        foreach ($m[0] as $word) {
            $name = strtolower($word);
            if (preg_match(self::DENIED_COLUMN_PATTERN, $word)
                || in_array($name, array_map('strtolower', self::DENIED_TABLES), true)
                || in_array($name, array_map('strtolower', (array) config('visns-packages.report_builder.denied_tables', [])), true)
                || in_array($name, array_map('strtolower', (array) config('visns-packages.report_builder.denied_columns', [])), true)) {
                throw SemanticException::forPath($path, 'That formula names a column that is not available for reporting.');
            }
        }
    }

    private function assertFilters(array $filters, string $path): void
    {
        foreach ($filters as $i => $filter) {
            if (!is_array($filter)) {
                continue;
            }
            if (isset($filter['filters']) && is_array($filter['filters'])) {
                $this->assertFilters($filter['filters'], "{$path}.{$i}.filters");
                continue;
            }
            if (isset($filter['column'])) {
                $this->assertReference($filter, "{$path}.{$i}");
            }
        }
    }

    private function assertReference(array $ref, string $path): void
    {
        if (isset($ref['table'])) {
            $this->assertTable((string) $ref['table'], "{$path}.table");
        }
        if (isset($ref['column'])) {
            $this->assertColumn((string) $ref['column'], "{$path}.column");
        }
    }
}
