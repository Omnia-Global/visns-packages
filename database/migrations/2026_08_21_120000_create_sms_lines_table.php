<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SMS-capable numbers the messaging module sends and receives on.
 *
 * A "line" is one phone number the organisation owns - in practice a Zoom Phone
 * number assigned to a user or a common area - together with the staff who are
 * allowed to work its inbox (the pivot table) and, once Zoom is connected, the
 * Zoom user the number hangs off.
 *
 * `phone_number` is E.164 and unique: it is the join key for every inbound
 * webhook, so two rows claiming the same number would make delivery ambiguous.
 * Normalisation happens in PHP (Support\PhoneNumber) before anything is written,
 * so the column can be compared with a plain equality.
 *
 * `zoom_user_id` / `zoom_user_email` are nullable because a line is perfectly
 * usable under the dev transport with no Zoom account behind it at all; they are
 * filled in by hand in Settings, or by `sms:sync-lines` once the live account
 * exists.
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

            $t->string('label', 191);

            // E.164 - "+61412345678". 32 is well past the 15-digit maximum and
            // leaves room for the plus and any future formatting.
            $t->string('phone_number', 32)->unique();

            $t->string('zoom_user_id', 191)->nullable()->index();
            $t->string('zoom_user_email', 191)->nullable();

            // An inactive line keeps its history and stops being offered as a
            // sender - deactivating is what you do to a number that has been
            // handed back, rather than deleting the conversations with it.
            $t->boolean('active')->default(true)->index();

            // Room for per-line preferences (signatures, business hours) without
            // another migration; nothing in the module requires a key here.
            $t->json('settings')->nullable();

            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.lines',
            'sms_lines'
        );
    }
};
