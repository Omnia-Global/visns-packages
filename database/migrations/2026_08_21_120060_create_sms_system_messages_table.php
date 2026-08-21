<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for APPLICATION-originated texts: login codes, portal OTPs,
 * and anything else the software sends on its own behalf rather than on a staff
 * member's.
 *
 * These deliberately do NOT live in `sms_messages`. A row there belongs to a
 * thread, and a thread is visible to everyone attached to the line - which
 * would mean any holder of "Messaging Access" could read a colleague's
 * two-factor code out of the shared inbox. Separating the table separates the
 * visibility, and there is no read endpoint for it at all.
 *
 * THE BODY IS NOT STORED, and there is no column for it. That is the whole
 * point of the table: what is kept is enough to answer "did the code go out,
 * when, and what did the provider say" and nothing that would let a person who
 * reached the database use somebody's second factor.
 *
 * `status` shares the vocabulary of sms_messages so a reader does not have to
 * learn two:
 *   queued        written, not yet handed to the provider
 *   sent          the provider accepted it
 *   failed        the provider refused it, or could not be reached - see `error`
 *   not_connected there is no SMS provider configured; nothing left the building
 *
 * `provider_message_id` is indexed rather than unique: it is what the
 * `phone.sms_sent` webhook matches on to mark the row sent (and, just as
 * importantly, to know NOT to create an inbox thread for a login code), and a
 * unique index would turn a provider that reuses an id into a 500 on a webhook
 * that must always answer 200.
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

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            // No foreign key: this row is an audit record and must outlive the
            // line it was sent from. Nullable because a send can fail before a
            // line is ever resolved.
            $t->unsignedBigInteger('line_id')->nullable()->index();

            // What the application was doing: 'two_factor', 'portal_otp', ...
            // Free-form on purpose - a new transactional message must not need
            // a migration.
            $t->string('purpose', 40)->index();

            // E.164, normalised before it is written.
            $t->string('to_number', 32)->index();

            $t->string('status', 20)->default('queued')->index();

            $t->string('provider_message_id', 191)->nullable()->index();

            $t->text('error')->nullable();

            $t->dateTime('sent_at')->nullable();

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
            'visns-packages.messaging.tables.system_messages',
            'sms_system_messages'
        );
    }
};
