<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue's extension number, so a webhook that names a queue WITHOUT AN ID
 * can still find its settings row.
 *
 * ==========================================================================
 *  THE PAYLOAD ZOOM ACTUALLY SENDS DOES NOT CARRY THE QUEUE'S ID.
 * ==========================================================================
 *
 * Verified on production after the first successful queue pop. A
 * `phone.callee_ringing` for a leg distributed by a call queue names the queue
 * under `forwarded_by` like this — and that is the whole node:
 *
 *   `{"name": "Test Dev Call Queue", "extension_type": "callQueue",
 *     "extension_number": "805"}`
 *
 * No `id`, no `extension_id`. `ZoomWebhookController::resolveQueue()` reads only
 * those two, so the live row stored `queue_id` null with the name resolved,
 * `ZoomLiveQueueCall::present()` had nothing to key the pickup-code map with, and
 * the card drew no Pick up button — the product owner's report, *"can we look
 * into picking up the call with the call pop"*. `isExcludedQueue()` was blind for
 * exactly the same reason, so an opted-out queue popped anyway.
 *
 * The queue listing DOES carry both, so the settings row can hold the extension
 * number and the webhook can look the id up locally. This column is that bridge.
 *
 * NULLABLE AND INDEXED. Nullable because a row only ever learns its extension
 * number when the settings page next loads while Zoom is reachable, and every
 * row that exists today has none; indexed because the webhook path resolves
 * through it on every ringing event — though in practice
 * `ZoomCallQueueSetting::idsByExtensionAndName()` reads the whole (tiny) table
 * into a cached map once every ten minutes, so the index is insurance for an
 * account with hundreds of queues rather than the hot path.
 *
 * A STRING, NOT AN INTEGER. Zoom sends `"805"` and an extension number is an
 * identifier rather than a quantity: it can carry a leading zero, and comparing
 * it as a number would make `805` and `0805` the same queue.
 *
 * Idempotent: an application that already grew this column keeps what it has.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, 'extension_number')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->string('extension_number', 16)->nullable()->index();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'extension_number')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('extension_number');
        });
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.call_queue.tables.settings',
            'zoom_call_queue_settings'
        );
    }
};
