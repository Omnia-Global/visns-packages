<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit table recording every staff impersonation of a client portal account.
 * One row is written when a staff member starts a session
 * (ImpersonationController::issue). Singular table name to match the
 * convention of the applications this package serves (cf. company_contact).
 */
return new class extends Migration {
    public function up(): void
    {
        // Idempotent: an application that already runs this table from its own
        // migration (the Throughlife CRM does) can adopt the package without a
        // "table already exists" failure on the next deploy.
        if (Schema::hasTable('impersonation_log')) {
            return;
        }

        Schema::create('impersonation_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The staff member who initiated the impersonation.
            $table->unsignedBigInteger('staff_user_id')->nullable()->index();
            // The client User account that was impersonated.
            $table->unsignedBigInteger('client_user_id')->nullable()->index();
            // The client's contact record, for quick per-client lookups.
            $table->unsignedBigInteger('company_contact_id')->nullable()->index();

            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();

            // When the issued impersonation token was set to expire.
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_log');
    }
};
