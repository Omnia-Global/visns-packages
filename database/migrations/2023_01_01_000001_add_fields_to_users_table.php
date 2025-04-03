<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToUsersTable extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Check if username column doesn't exist
            if (!Schema::hasColumn('users', 'username')) {
                $table
                    ->string('username')
                    ->nullable()
                    ->unique();
            }

            // Check if provider columns don't exist (for Socialite)
            if (!Schema::hasColumn('users', 'provider')) {
                $table->string('provider')->nullable();
            }

            if (!Schema::hasColumn('users', 'provider_id')) {
                $table->string('provider_id')->nullable();
            }

            if (!Schema::hasColumn('users', 'provider_token')) {
                $table->text('provider_token')->nullable();
            }

            if (!Schema::hasColumn('users', 'provider_refresh_token')) {
                $table->text('provider_refresh_token')->nullable();
            }

            // Add Laravel Fortify 2FA fields
            if (!Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }

            if (!Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }

            if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            // We don't want to drop columns on rollback as they might contain user data
            // If you want to drop columns, uncomment the lines below

            // $table->dropColumn('username');
            // $table->dropColumn('provider');
            // $table->dropColumn('provider_id');
            // $table->dropColumn('provider_token');
            // $table->dropColumn('provider_refresh_token');
            // $table->dropColumn('two_factor_secret');
            // $table->dropColumn('two_factor_recovery_codes');
            // $table->dropColumn('two_factor_confirmed_at');
        });
    }
}
