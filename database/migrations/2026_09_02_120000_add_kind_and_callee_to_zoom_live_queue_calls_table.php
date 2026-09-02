<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct calls join the live-call table, and a declined leg stops ending the
 * call.
 *
 * TWO CHANGES, one table.
 *
 * `kind` — until now a row could only be a call ringing in a call queue, so the
 * table needed no word for what it was holding. A call ringing somebody's own
 * extension (a direct dial, an internal call, a transfer) now pops too, and the
 * pop card renders the two differently: a queue call carries a queue badge and
 * a queue pickup code, a direct one carries the person's name. The `callee_*`
 * columns are that person, copied off the callee node the webhook already had
 * in its hands — a direct call has no queue to name it.
 *
 * `last_ringing_at` / `last_missed_at` — Zoom sends `phone.callee_missed` when
 * ONE leg times out or is declined, not when the call is over. A queue rings
 * four handsets on one call_id and the first person to wave it away used to
 * delete the row out from under the other three; a direct call rings the desk
 * phone and the app, so it does it to itself. Keeping both timestamps lets the
 * snapshot answer "is this still ringing?" — a miss only wins if nothing rang
 * after it, and only until the grace window expires.
 *
 * Idempotent per column: an application that already grew any of these keeps
 * what it has.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'kind')) {
                // Defaulted rather than nullable so every row written before
                // direct calls existed reads back as what it was: a queue call.
                $t->string('kind', 16)->default('queue')->index();
            }

            if (! Schema::hasColumn($table, 'callee_name')) {
                $t->string('callee_name', 160)->nullable();
            }

            if (! Schema::hasColumn($table, 'callee_extension_id')) {
                $t->string('callee_extension_id', 120)->nullable();
            }

            if (! Schema::hasColumn($table, 'callee_extension_number')) {
                $t->string('callee_extension_number', 32)->nullable();
            }

            if (! Schema::hasColumn($table, 'callee_extension_type')) {
                $t->string('callee_extension_type', 40)->nullable();
            }

            // Who handed the call on, when Zoom said. A transfer is the common
            // case, and "Transferred by Steve" is the whole reason the pop is
            // worth reading.
            if (! Schema::hasColumn($table, 'forwarded_by_name')) {
                $t->string('forwarded_by_name', 160)->nullable();
            }

            if (! Schema::hasColumn($table, 'last_ringing_at')) {
                $t->timestamp('last_ringing_at')->nullable();
            }

            if (! Schema::hasColumn($table, 'last_missed_at')) {
                $t->timestamp('last_missed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = array_values(array_filter([
            'kind',
            'callee_name',
            'callee_extension_id',
            'callee_extension_number',
            'callee_extension_type',
            'forwarded_by_name',
            'last_ringing_at',
            'last_missed_at',
        ], fn(string $column) => Schema::hasColumn($table, $column)));

        if ($columns === []) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns) {
            $t->dropColumn($columns);
        });
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.call_queue.tables.live_calls',
            'zoom_live_queue_calls'
        );
    }
};
