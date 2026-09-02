<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Zoom webhook ledger: one row per delivery, and what the CRM did with it.
 *
 * This is the answer to "the call pop only shows up some of the time". Until
 * now the only trace a delivery left was a log line or two, and a pop that
 * never happened is indistinguishable from a delivery that never arrived — the
 * question "did Zoom send it, did the signature pass, did the queue match, did
 * the broadcast reach Reverb" had four possible answers and no evidence for
 * any of them. Every one of those steps writes its verdict here instead.
 *
 * Deliberately NOT a copy of the payload. The row records the ROUTING shape
 * (which extension types the call went through) and the caller's number, which
 * the live-call table already holds; no names, no bodies. The point is the
 * pipeline's behaviour, not the call's content.
 *
 * Not a permanent record either: the diagnostics endpoint prunes anything older
 * than `call_queue.diagnostics.retain_days` as it reads.
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

            // Zoom's event name, or 'rejected' for a delivery the signature
            // middleware turned away before the controller ever saw it.
            $t->string('event', 80)->index();

            $t->string('call_id', 120)->nullable()->index();
            $t->string('queue_id', 120)->nullable();
            $t->string('queue_name', 160)->nullable();
            $t->string('caller_number', 40)->nullable();

            // What the pipeline decided: 'ringing_recorded', 'ringing_unmatched',
            // 'closed', 'sms', 'failed' and friends. Indexed because every
            // summary in the diagnostics endpoint groups on it.
            $t->string('outcome', 40)->index();

            // The broadcast leg on its own, because it is the one step that can
            // fail while everything else looks perfect: ok | failed | skipped,
            // with how long the publish to Reverb/Pusher took.
            $t->string('broadcast', 20)->nullable();
            $t->unsignedInteger('broadcast_ms')->nullable();

            // The whole delivery, webhook thread only — this is what shows a
            // slow enrichment hook or a stalled publish.
            $t->unsignedInteger('duration_ms')->nullable();

            $t->text('error')->nullable();

            // Routing shape only: callee / forwarded_by extension type, id and
            // extension number. No names, no payload.
            $t->json('meta')->nullable();

            // Millisecond precision: queue events for one call arrive within
            // the same second, and their ORDER is half the diagnosis.
            $t->timestamp('received_at', 3)->index();

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
            'visns-packages.call_queue.tables.webhook_events',
            'zoom_webhook_events'
        );
    }
};
