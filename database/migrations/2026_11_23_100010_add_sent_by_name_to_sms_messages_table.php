<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent an outbound message when it was not a `users` row.
 *
 * `sms_messages.user_id` names the staff member behind a send. A person signed
 * in to a client portal is not one, and a message nobody can be named against
 * is the first thing asked about one that should not have gone - so their
 * name is recorded here as written at the time (the campaign table's
 * `created_by_name` is the same idea). Staff sends leave it NULL; the payload
 * reads the user relation first and falls back to this.
 *
 * Written by SmsService::send() only when the column exists (SchemaColumns), so
 * the package runs unchanged between an update and this migration.
 *
 * Idempotent and guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'sent_by_name')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->string('sent_by_name', 191)->nullable();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sent_by_name')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('sent_by_name');
        });
    }

    private function table(): string
    {
        return (string) config('visns-packages.messaging.tables.messages', 'sms_messages');
    }
};
