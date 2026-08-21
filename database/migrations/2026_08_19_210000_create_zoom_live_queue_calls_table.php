<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live (in-flight) Zoom Phone call queue calls.
 *
 * Transient state only: a row exists for as long as a call is ringing in one of
 * the monitored queues. It is deleted when the call is answered/ended, and the
 * snapshot endpoint prunes anything older than `stale_after_minutes` as a
 * stale-ring guard.
 *
 * Idempotent: an application that already owns this table (having grown it
 * itself before adopting the package) is left exactly as it is.
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
            $t->string('call_id', 64)->unique();
            $t->string('queue_id', 128)->nullable()->index();
            $t->string('queue_name', 255)->nullable();
            // Legacy `primary`/`secondary` slot from before every queue in the
            // account popped. Nothing writes it any more; kept nullable so an
            // application whose table already has it is unchanged.
            $t->string('queue', 32)->nullable()->index();
            $t->string('caller_number', 64)->nullable();
            $t->string('caller_name', 255)->nullable();
            // The caller -> client match, resolved once by the webhook rather
            // than by every watching browser. Nullable: an unmatched caller
            // simply has no preview.
            $t->json('client_preview')->nullable();
            $t->string('status', 32)->default('ringing');
            $t->timestamp('started_at')->nullable()->index();
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
            'visns-packages.call_queue.tables.live_calls',
            'zoom_live_queue_calls'
        );
    }
};
