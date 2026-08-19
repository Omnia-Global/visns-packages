<?php

namespace Visnsstudio\VisnsPackages\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Visnsstudio\VisnsPackages\VisnsPackagesServiceProvider;

/**
 * Base case for the package's own feature tests.
 *
 * The suite runs on Testbench against an in-memory SQLite database, with a
 * hand-built schema rather than the package's publishable migrations: those
 * migrations describe tables an application already owns (users, files) and
 * carry MySQL-only statements, while what a test needs is the small set of
 * columns the module under test reads and writes.
 *
 * The `App\` namespace is supplied by tests/Fixtures/app (see composer's
 * autoload-dev) because every controller here extends the host application's
 * base controller.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \Spatie\Permission\PermissionServiceProvider::class,
            VisnsPackagesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://crm.example.test');
        $app['config']->set('auth.providers.users.model', \App\Models\User::class);

        // The package resolves its user model through config, never a literal
        // class name - so pointing it at the fixture is all a test needs to do.
        $app['config']->set('visns-packages.user_model', \App\Models\User::class);

        // Broadcasting goes nowhere by default; tests that care assert on the
        // dispatched event rather than on a wire.
        $app['config']->set('broadcasting.default', 'null');
    }

    protected function defineDatabaseMigrations()
    {
        $this->createUsersTable();
        $this->createAuthSupportTables();
        $this->loadPermissionSchema();
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('firstname')->nullable();
            $table->string('surname')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('mobile')->nullable();
            $table->boolean('disabled')->default(false);

            // TOTP (the package's existing two-factor flow).
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Code-channel two-factor.
            $table->string('two_factor_token')->nullable();
            $table->timestamp('two_factor_token_sent_at')->nullable();

            // Written by the post-login hooks; read by the ip_change trigger.
            $table->string('last_logged_ip_address')->nullable();
            $table->timestamp('dateLastLogged')->nullable();

            // Links a portal user to the contact record OTP codes hang off.
            $table->unsignedBigInteger('company_contact_id')->nullable();

            // OTP columns, so the bundled users-table contact resolver has
            // somewhere to store a code.
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_sent_at')->nullable();
            $table->string('otp_contact_method')->nullable();
            $table->unsignedInteger('otp_attempts')->default(0);
            $table->timestamp('otp_locked_until')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    private function createAuthSupportTables(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('two_factor_remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token')->unique();
            $table->string('device_identifier');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function loadPermissionSchema(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }

    /**
     * Run one of the package's publishable migrations against the test schema.
     *
     * The module migrations (impersonation log, call queue tables) ARE the
     * shipped schema, so tests exercise them directly rather than restating
     * their columns.
     */
    protected function runPackageMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../database/migrations/' . $filename;

        $migration->up();
    }
}
