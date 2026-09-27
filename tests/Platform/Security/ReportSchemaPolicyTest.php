<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticException;
use Visnsstudio\VisnsPackages\Support\ReportSchemaPolicy;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The free-form report builder may name only tables and columns the policy
 * reports on: credential tables and secret-bearing columns are hidden from
 * the listings and refused in a query, before any SQL runs.
 */
class ReportSchemaPolicyTest extends TestCase
{
    private ReportSchemaPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('api_key')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
        });

        $this->policy = new ReportSchemaPolicy();
    }

    #[Test]
    public function credential_tables_and_secret_columns_are_refused(): void
    {
        foreach (['sessions', 'personal_access_tokens', 'integration_settings', 'password_reset_tokens', 'vault_entries'] as $table) {
            $this->assertFalse($this->policy->tableAllowed($table), $table);
        }
        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_key', 'token_hash', 'otp_code', 'credentials'] as $column) {
            $this->assertFalse($this->policy->columnAllowed($column), $column);
        }

        $this->assertTrue($this->policy->tableAllowed('customers'));
        foreach (['name', 'email', 'tokens_used', 'secretary_name', 'created_at'] as $column) {
            $this->assertTrue($this->policy->columnAllowed($column), $column);
        }
    }

    #[Test]
    public function a_name_that_is_not_a_plain_identifier_is_refused(): void
    {
        foreach (['users`; drop', 'customers.name', '../x', '', 'a b'] as $name) {
            $this->assertFalse($this->policy->tableAllowed($name), $name);
            $this->assertFalse($this->policy->columnAllowed($name), $name);
        }
    }

    #[Test]
    public function listings_drop_hidden_tables_and_columns(): void
    {
        $this->assertSame(['customers', 'orders'], $this->policy->filterTables(['customers', 'sessions', 'orders', 'failed_jobs']));
        $this->assertSame(['id', 'name', 'email'], $this->policy->filterColumns(['id', 'name', 'password', 'email', 'remember_token']));
    }

    #[Test]
    public function a_query_naming_a_hidden_table_or_column_anywhere_is_refused(): void
    {
        $base = ['mainTable' => 'customers', 'columns' => [['table' => 'customers', 'column' => 'name']]];
        $this->policy->assertQueryConfig($base); // allowed
        $this->addToAssertionCount(1);

        $refused = [
            'main table' => ['mainTable' => 'sessions'] + $base,
            'unknown table' => ['mainTable' => 'nope'] + $base,
            'column' => ['columns' => [['table' => 'customers', 'column' => 'api_key']]] + $base,
            'join table' => $base + ['joins' => [['sourceTable' => 'customers', 'targetTable' => 'sessions', 'sourceColumn' => 'id', 'targetColumn' => 'id']]],
            'join column' => $base + ['joins' => [['sourceTable' => 'customers', 'targetTable' => 'customers', 'sourceColumn' => 'id', 'targetColumn' => 'password']]],
            'filter' => $base + ['filters' => [['table' => 'customers', 'column' => 'password', 'operator' => 'like', 'value' => 'a']]],
            'nested filter' => $base + ['filters' => [['filters' => [['table' => 'customers', 'column' => 'remember_token', 'operator' => '=', 'value' => 'x']]]]],
            'sort' => $base + ['sorting' => [['table' => 'customers', 'column' => 'two_factor_secret', 'direction' => 'asc']]],
            'formula' => ['columns' => [['isCalculated' => true, 'formula' => 'LENGTH(customers.api_key)']]] + $base,
            'distinct' => $base + ['unique' => ['enabled' => true, 'distinct' => true, 'field' => 'customers.password']],
        ];

        foreach ($refused as $label => $config) {
            try {
                $this->policy->assertQueryConfig($config);
                $this->fail("{$label} was not refused");
            } catch (SemanticException $e) {
                $this->assertSame(422, $e->status(), $label);
                $this->assertStringNotContainsString('password', $e->getMessage(), 'the refusal names no column');
            }
        }
    }

    #[Test]
    public function the_config_can_deny_more_or_allow_only_a_list(): void
    {
        config(['visns-packages.report_builder.denied_tables' => ['orders']]);
        $this->assertFalse($this->policy->tableAllowed('orders'));

        config(['visns-packages.report_builder.denied_columns' => ['salary']]);
        $this->assertFalse($this->policy->columnAllowed('salary'));

        config(['visns-packages.report_builder.allowed_tables' => ['customers']]);
        $this->assertTrue($this->policy->tableAllowed('customers'));
        $this->assertFalse($this->policy->tableAllowed('invoices'));
    }
}
