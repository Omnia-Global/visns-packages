<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every SMS the module has sent or received, forever.
 *
 * Nothing in this module deletes a message. These are client communications
 * under an AFSL record-keeping obligation, so `sms:prune` archives threads and
 * leaves the rows alone; see the README.
 *
 * `status` is the message's life story in one column:
 *   queued        accepted by us, not yet handed to the transport
 *   sent          the transport accepted it
 *   delivered     the carrier confirmed handset delivery
 *   failed        the transport or carrier rejected it - `error` says why
 *   received      inbound
 *   not_connected stored but never sent, because no transport is connected yet
 *
 * `provider_message_id` is unique so a webhook redelivery (Zoom retries) cannot
 * create the same message twice - the handler upserts on it.
 *
 * `raw_payload` keeps the provider's own JSON for anything the columns above do
 * not model. It is the first thing to read when a real Zoom account starts
 * sending shapes this module has never seen.
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

        $threads = (string) config('visns-packages.messaging.tables.threads', 'sms_threads');

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('thread_id');

            // 'in' or 'out'. A string rather than an enum: enum changes are a
            // table rebuild in MySQL and this module may yet grow a third
            // direction (a system note).
            $t->string('direction', 8)->index();

            $t->text('body');

            $t->string('status', 16)->default('queued')->index();
            $t->text('error')->nullable();

            // The staff member who sent it. Null for inbound, and null for an
            // outbound that was sent from the Zoom app rather than from here.
            $this->userKey($t, 'user_id')->nullable()->index();

            $t->string('provider_message_id', 191)->nullable()->unique();

            $t->json('attachments')->nullable();
            $t->json('raw_payload')->nullable();

            $t->dateTime('sent_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->dateTime('received_at')->nullable();

            $t->timestamps();

            // The one query this table exists to serve: a page of one thread,
            // oldest to newest.
            $t->index(['thread_id', 'created_at']);
        });

        if (Schema::hasTable($threads)) {
            Schema::table($table, function (Blueprint $t) use ($threads) {
                $t->foreign('thread_id')
                    ->references('id')
                    ->on($threads)
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            // nullOnDelete, never cascade: a staff member leaving must not take
            // the client's message history with them.
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
            'visns-packages.messaging.tables.messages',
            'sms_messages'
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
