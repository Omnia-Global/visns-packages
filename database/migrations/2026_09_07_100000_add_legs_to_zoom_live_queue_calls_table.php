<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call is not enough to know whether the call is still ringing.
 *
 * Zoom sends `phone.callee_ringing` and `phone.callee_ended` PER LEG on a single
 * `call_id`. A queue rings every member, and each member's extension rings a
 * desk phone AND the Zoom app, so production routinely sees two ringing events
 * per extension per call. Until now the FIRST `phone.callee_ended` deleted the
 * row and closed the pop on every screen while the other handsets rang on — one
 * live call closed its card a moment before somebody actually answered it.
 *
 * `legs` is the count that fixes it: a map of leg key -> {state, at}, written by
 * the webhook as each leg rings and settled as each one ends or is missed. The
 * call closes for everyone the moment NO leg is ringing any more, which is the
 * rule the office asked for — "if it stops ringing, it should close for
 * everyone" — rather than the moment the first one stops.
 *
 * Nullable, and read as "no legs recorded" rather than "no legs ringing": a row
 * written by the previous release has nothing here, and must keep closing on the
 * first ended event it sees. See ZoomWebhookController::handleEndedLeg().
 *
 * Idempotent: an application that already grew the column keeps what it has.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'legs')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->json('legs')->nullable();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legs')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('legs');
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
