<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far each reader who is NOT a `users` row has read in each thread.
 *
 * The staff marks live in `sms_thread_reads`, whose `user_id` is a foreign key
 * to `users` - right for staff, and impossible for a person signed in to a
 * client portal, who is a row somewhere else entirely. This is the same
 * high-water mark keyed on (thread, reader_type, reader_id): `reader_type`
 * names the kind of reader (`portal_user`), so two kinds whose ids overlap
 * never share a mark. No foreign key on `reader_id` for that reason - the
 * package cannot know which table each type lives in.
 *
 * A missing row means "never opened", exactly as for staff. Read through
 * SmsThread::unreadCountsForReader(), written through SmsThreadReaderMark::mark().
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
            $t->string('reader_type', 32);
            $t->unsignedBigInteger('reader_id');

            $t->unsignedBigInteger('last_read_message_id')->nullable();
            $t->dateTime('read_at')->nullable();

            $t->timestamps();

            // Named: the default name from a configurable table plus three
            // columns can pass MySQL's 64-character limit.
            $t->unique(['thread_id', 'reader_type', 'reader_id'], 'sms_reader_marks_thread_reader_unique');
        });

        // A deleted thread takes its marks with it, as the staff marks do.
        if (Schema::hasTable($threads)) {
            Schema::table($table, function (Blueprint $t) use ($threads) {
                $t->foreign('thread_id')
                    ->references('id')
                    ->on($threads)
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.thread_reader_marks',
            'sms_thread_reader_marks'
        );
    }
};
