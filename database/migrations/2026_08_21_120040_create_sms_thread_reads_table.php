<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far each staff member has read in each thread.
 *
 * Unread is per USER, not per thread: a shared line is read by several people
 * and a thread one of them has opened is still new to the others. A high-water
 * mark (`last_read_message_id`) rather than a per-message read table, because
 * the only question ever asked is "how many inbound messages are newer than
 * this", which is one indexed count.
 *
 * A missing row means "never opened", i.e. every inbound message is unread.
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
            $t->unsignedBigInteger('thread_id')->index();
            $this->userKey($t, 'user_id')->index();

            $t->unsignedBigInteger('last_read_message_id')->nullable();
            $t->dateTime('read_at')->nullable();

            $t->timestamps();

            $t->unique(['thread_id', 'user_id']);
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
            $t->foreign('user_id')
                ->references('id')
                ->on('users')
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
            'visns-packages.messaging.tables.thread_reads',
            'sms_thread_reads'
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
