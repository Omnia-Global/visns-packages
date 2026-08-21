<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation: a line and the outside number it is talking to.
 *
 * (line_id, external_number) is unique because that pair IS the conversation -
 * an inbound webhook and a staff member starting a new message must land on the
 * same row, or the same person's history would split in two.
 *
 * The denormalised `last_message_*` columns exist so the thread list - the most
 * frequently loaded screen in the module - can be ordered and rendered without
 * touching the messages table at all. They are maintained on every write.
 *
 * `client_id` is deliberately NOT a foreign key. It points at whatever the host
 * application calls a client (in the CRM, a company contact); this package does
 * not own that table and must not assume its name. `client_name` caches the
 * matched name so a thread list never has to reach into the application's
 * schema, and so a renamed or deleted client leaves the history readable.
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

        $lines = (string) config('visns-packages.messaging.tables.lines', 'sms_lines');

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('line_id')->index();

            // E.164, normalised before write - see Support\PhoneNumber.
            $t->string('external_number', 32);

            // The application's client id. No FK: the foreign table belongs to
            // the application, not to this package.
            $t->unsignedBigInteger('client_id')->nullable()->index();
            $t->string('client_name', 191)->nullable();

            // A label a staff member typed, for the numbers that match nobody
            // in the CRM ("Bob's accountant").
            $t->string('contact_name', 191)->nullable();

            $t->dateTime('last_message_at')->nullable()->index();
            $t->string('last_message_preview', 191)->nullable();
            $t->string('last_direction', 8)->nullable();

            // The newest message's status, so the thread list can badge a
            // failed or not-yet-connected send without opening the
            // conversation - which is the one thing in that list somebody has
            // to act on.
            $t->string('last_message_status', 16)->nullable();

            // Archiving hides a thread from the default list without deleting
            // anything - see the AFSL note in the README.
            $t->dateTime('archived_at')->nullable();

            // Zoom groups an SMS conversation under a session id; stored so a
            // thread can be reconciled against Zoom's own view of it.
            $t->string('provider_session_id', 191)->nullable()->index();

            $t->timestamps();

            $t->unique(['line_id', 'external_number']);
        });

        if (! Schema::hasTable($lines)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($lines) {
            $t->foreign('line_id')
                ->references('id')
                ->on($lines)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.threads',
            'sms_threads'
        );
    }
};
