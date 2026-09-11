<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers that have asked not to be texted.
 *
 * This is the module's compliance floor rather than a feature of the bulk
 * sub-module, which is why it is created whenever messaging is installed and
 * not behind `bulk.enabled`. The Spam Act 2003 requires a functional
 * unsubscribe on a commercial electronic message; Zoom does no STOP handling
 * for Australian numbers, so if this table did not exist the word "STOP" would
 * arrive as an ordinary inbound message and be read by nobody.
 *
 * `number` is UNIQUE and is the whole key. An opt-out is per handset, not per
 * line and not per campaign - somebody who types STOP means "stop texting me",
 * and a narrower reading is the kind that ends up being explained to the ACMA.
 * It follows that `line_id` is context ("this is the line they said it to"),
 * never part of the lookup.
 *
 * Neither `line_id` nor `message_id` carries a foreign key, deliberately. A
 * cascade from either would silently re-subscribe somebody the day a line was
 * deleted or a message row was tidied away - the one direction this table must
 * never move in on its own. A dangling id is a lost breadcrumb; a dropped row
 * is a text to a person who asked us to stop.
 *
 * Nothing here is encrypted and nothing here is secret: it is a list of numbers
 * that must be consulted before every bulk send, and an unreadable one would be
 * worse than useless.
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

            // E.164, normalised before write - see Support\PhoneNumber. Unique,
            // because "are they opted out" has to be one row's worth of work on
            // every recipient of every campaign.
            $t->string('number', 32)->unique();

            // The line that received the keyword, for context. Nullable because
            // a manual entry is typed on an administration screen and belongs to
            // no line at all.
            $t->unsignedBigInteger('line_id')->nullable()->index();

            // 'keyword' (they texted STOP) or 'manual' (somebody in the practice
            // recorded a request made another way - on the phone, by email, in
            // the room). The two are worth telling apart when a complaint is
            // being answered.
            $t->string('source', 16)->default('keyword')->index();

            // The inbound message that triggered it. The evidence: without it,
            // "they asked us to stop" is an assertion with nothing behind it.
            $t->unsignedBigInteger('message_id')->nullable();

            $t->string('note', 191)->nullable();

            // Who recorded a manual one. Null for a keyword: nobody did, the
            // client did.
            $this->userKey($t, 'user_id')->nullable()->index();

            $t->timestamps();
        });

        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            // nullOnDelete, never cascade: a staff member leaving must not take
            // a client's unsubscribe with them.
            $t->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.opt_outs',
            'sms_opt_outs'
        );
    }

    /**
     * A column able to reference users.id whatever width the consumer's users
     * table uses.
     */
    private function userKey(Blueprint $t, string $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        return $this->usersKeyIsBig()
            ? $t->unsignedBigInteger($column)
            : $t->unsignedInteger($column);
    }

    private function usersKeyIsBig(): bool
    {
        if (! Schema::hasTable('users')) {
            return true;
        }

        $type = strtolower((string) Schema::getColumnType('users', 'id'));

        return ! in_array($type, ['int', 'integer', 'int unsigned', 'mediumint', 'smallint'], true);
    }
};
