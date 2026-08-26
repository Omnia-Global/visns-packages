<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place for the detail a bare action word cannot carry.
 *
 * The log's four columns answer "who, what, when, from where" and that was
 * enough while every action was something done to an entry inside the CRM. It
 * stopped being enough the moment a share link could be EMAILED: "a link was
 * created" and "a link was created and sent to itsupport@acme.example" are
 * different facts, and the second one is the one an incident asks for.
 *
 * Nullable and JSON rather than a `recipient_email` column, because the shape
 * of that detail is per-action - today it is where a link was sent, tomorrow it
 * is which fields a reveal handed over - and a column per action would be a
 * migration per action.
 *
 * NOTHING SECRET GOES IN HERE. The table is append-only, unencrypted, read by
 * every vault administrator and kept for a year; a password, a token or a share
 * URL written into this column would outlive every control the vault has. The
 * writers are `VaultAccessLog::record()` / `recordAs()` and the rule is theirs
 * to keep.
 *
 * Idempotent: an application that already has the column is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'meta')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            // After `user_agent` so the row reads in the order it is written.
            // Nullable, and null is the normal case: only the actions that have
            // something extra to say fill it in.
            $t->json('meta')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'meta')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('meta');
        });
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.vault.tables.access_logs',
            'vault_access_logs'
        );
    }
};
