<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who looked at what, and when.
 *
 * Append-only by design: there is no `updated_at`, because a row here is a
 * statement about a moment and nothing may revise it. `created_at` is indexed
 * because every read of this table is either "newest first" or "older than N
 * days" (the prune command).
 *
 * `vault_entry_id` is nullable and clears when an entry is hard-deleted - the
 * record that somebody revealed a password must outlive the credential itself.
 * It is also null for the actions that are not about one entry, `confirm_failed`
 * being the one that matters: a run of those is exactly the signal this table
 * exists to preserve.
 *
 * Idempotent: an application that already owns this table is left as it is.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('vault_entry_id')->nullable()->index();
            // Same width as users.id (see the entries migration); no FK, the
            // log must outlive the account.
            $big = ! Schema::hasTable('users')
                || ! in_array(strtolower((string) Schema::getColumnType('users', 'id')), ['int', 'integer', 'int unsigned', 'mediumint', 'smallint'], true);
            ($big ? $t->unsignedBigInteger('user_id') : $t->unsignedInteger('user_id'))->index();

            $t->string('action', 32)->index();

            // Wide enough for IPv6, and for an IPv4-mapped IPv6 address.
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();

            $t->dateTime('created_at')->index();
        });

        $entries = (string) config(
            'visns-packages.vault.tables.entries',
            'vault_entries'
        );

        if (Schema::hasTable($entries)) {
            Schema::table($table, function (Blueprint $t) use ($entries) {
                $t->foreign('vault_entry_id')
                    ->references('id')
                    ->on($entries)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.vault.tables.access_logs',
            'vault_access_logs'
        );
    }
};
