<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an integration's credentials live once somebody types them into the
 * UI rather than into a .env file.
 *
 * One row per integration, holding an ENCRYPTED blob rather than a column per
 * field. Two reasons: an integration's field list is config-driven and differs
 * per provider, so columns would mean a migration per integration; and one
 * encrypted cast covers every secret rather than leaving it to whoever adds
 * the next field to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_settings')) {
            return;
        }

        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();

            // The registry key, e.g. 'zoho' or 'meilisearch'.
            $table->string('provider')->unique();

            // Encrypted JSON: {client_id: '...', client_secret: '...'}. Text
            // rather than json, because ciphertext is not valid JSON and MySQL
            // rejects it in a json column.
            $table->text('credentials')->nullable();

            // Non-secret switches — region, org id, whether writes are
            // allowed. Kept apart from credentials so they stay readable.
            $table->json('options')->nullable();

            $table->boolean('is_enabled')->default(true);

            // The last time a human pressed "Test connection", and what came
            // back. Shown on the card so a stale integration is visible
            // without opening a log.
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_message')->nullable();

            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
