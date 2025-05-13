<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDisabledToUsersTable extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Check if disabled column doesn't exist
            if (!Schema::hasColumn('users', 'disabled')) {
                // Check if the referenced column exists before using after()
                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $table
                        ->boolean('disabled')
                        ->default(false)
                        ->after('email_verified_at');
                } else {
                    $table
                        ->boolean('disabled')
                        ->default(false);
                }
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
            // If you want to drop columns, uncomment the line below
            // $table->dropColumn('disabled');
        });
    }
}
