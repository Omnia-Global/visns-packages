<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-queue call-pop settings, edited in Settings -> Call Queues.
 *
 * One row per Zoom call queue id. `queue_name` is only a cache of the name last
 * seen on the Zoom API so the settings page (and anyone reading the table by
 * hand) can tell the queues apart while Zoom is unreachable; the queue list
 * itself always comes from Zoom.
 *
 * `pickup_code` is stored as bare digits — the leading `*99` staff dial is a
 * presentation detail added by the resolver.
 *
 * Idempotent: an application that already owns this table is left exactly as it
 * is.
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
            $t->string('queue_id', 64)->unique();
            $t->string('queue_name', 255)->nullable();
            $t->string('pickup_code', 16)->nullable();
            $t->boolean('excluded')->default(false);
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
            'visns-packages.call_queue.tables.settings',
            'zoom_call_queue_settings'
        );
    }
};
