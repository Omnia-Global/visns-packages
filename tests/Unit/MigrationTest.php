<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_adds_fields_to_users_table()
    {
        // Run the migration
        include_once __DIR__ .
            '/../../database/migrations/2023_01_01_000001_add_fields_to_users_table.php';
        $migration = new \AddFieldsToUsersTable();
        $migration->up();

        // Check if the fields were added
        $this->assertTrue(Schema::hasColumn('users', 'username'));
        $this->assertTrue(Schema::hasColumn('users', 'provider'));
        $this->assertTrue(Schema::hasColumn('users', 'provider_id'));
        $this->assertTrue(Schema::hasColumn('users', 'provider_token'));
        $this->assertTrue(Schema::hasColumn('users', 'provider_refresh_token'));

        // Check if the 2FA fields were added
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_secret'));
        $this->assertTrue(
            Schema::hasColumn('users', 'two_factor_recovery_codes')
        );
        $this->assertTrue(
            Schema::hasColumn('users', 'two_factor_confirmed_at')
        );
    }
}
