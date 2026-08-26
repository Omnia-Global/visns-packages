<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live (in-flight) Zoom Phone calls, per internal extension.
 *
 * The sibling of `zoom_live_queue_calls`, and deliberately NOT the same table.
 * That one answers "what is ringing in a call queue right now" and its rows die
 * the moment somebody picks up; this one answers "who in the office is on the
 * phone", so a row is created when a leg starts ringing and lives until the
 * call ends.
 *
 * One row per LEG: an internal-to-internal call produces two (the caller's and
 * the callee's), and a queue call ringing five handsets produces five, which is
 * what `leg_key` keeps apart — Zoom reuses one `call_id` across the legs it
 * fans a queue call out to.
 *
 * Transient state only. Nothing here is a record: the presence endpoint prunes
 * anything older than `presence.stale_after_minutes` on read, because Zoom does
 * not guarantee a closing event for every leg it opened.
 *
 * Idempotent: an application that already owns this table is left alone.
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

            // call_id + whichever identifier Zoom gave for this leg. Unique so
            // a repeated webhook (Zoom retries) updates the leg instead of
            // stacking duplicates of it.
            $t->string('leg_key', 191)->unique();
            $t->string('call_id', 128)->index();

            // Three ways to name the same handset, because a payload rarely
            // carries all three and the roster is matched on whichever is
            // present: the Zoom user id first, then the extension number.
            $t->string('zoom_user_id', 128)->nullable()->index();
            $t->string('extension_id', 128)->nullable()->index();
            $t->string('extension_number', 32)->nullable()->index();
            $t->string('user_name', 255)->nullable();

            // 'inbound'  — somebody rang us on this extension
            // 'outbound' — this extension placed the call
            $t->string('direction', 16)->default('inbound');
            // 'ringing' | 'active'
            $t->string('status', 16)->default('ringing');

            // The other end of the call, and the client it resolves to. The
            // match is the configured caller_enrichment hook, run once here on
            // the webhook thread rather than by every watching browser.
            $t->string('peer_number', 64)->nullable();
            $t->string('peer_name', 255)->nullable();
            $t->json('client_preview')->nullable();

            // Set when the call reached this extension through a call queue, so
            // the roster can say "Reception" rather than just showing a number.
            $t->string('queue_name', 255)->nullable();

            $t->timestamp('started_at')->nullable()->index();
            $t->timestamp('answered_at')->nullable();
            $t->json('raw_payload')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.call_queue.presence.tables.live_calls',
            'zoom_phone_live_calls'
        );
    }
};
