<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * A read high-water mark for a reader who is NOT a row in `users`.
 *
 * SmsThreadRead is the staff mark and its `user_id` is a foreign key to
 * `users`, so a person signed in to a client portal - who is somebody else
 * entirely - has nowhere to keep one there. This is the same mark keyed on
 * (thread, reader type, reader id) instead: the type names the kind of reader
 * (`portal_user`), so two kinds of non-user reader whose ids overlap never
 * share a mark.
 *
 * The base inbox never reads this table. It exists for a host's SmsController
 * subclass, whose unreadCounts()/markThreadRead() hooks call
 * SmsThread::unreadCountsForReader() and mark() below.
 *
 * Keyless for Eloquent like SmsThreadRead, for the same reason: the identity
 * is the unique triple, and writes go through updateOrInsert.
 */
class SmsThreadReaderMark extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
        'reader_id' => 'integer',
        'last_read_message_id' => 'integer',
    ];

    public function getTable()
    {
        return self::tableName();
    }

    public static function tableName(): string
    {
        return (string) ModuleConfig::get('messaging.tables.thread_reader_marks', 'sms_thread_reader_marks');
    }

    /**
     * Has the table been migrated yet?
     *
     * Asked rather than assumed for the window between an application updating
     * the package and running its migrations - the SchemaColumns convention, for
     * a whole table. Memoised in the container (never a static, which would
     * outlive a test's fresh application or an Octane request's migration).
     */
    public static function tableExists(): bool
    {
        $key = 'visns-packages.schema-tables.' . self::tableName();
        $app = app();

        if ($app->bound($key)) {
            return (bool) $app->make($key);
        }

        try {
            $exists = Schema::hasTable(self::tableName());
        } catch (\Throwable $e) {
            // No database to ask is not a table that exists - and not cached,
            // so the next request asks again.
            return false;
        }

        // Only a positive answer is remembered: a table that appears mid-run
        // (a test migrating after the first read) is then seen at once.
        if ($exists) {
            $app->instance($key, true);
        }

        return $exists;
    }

    /**
     * Move a reader's mark to the given message (normally the thread's newest).
     *
     * updateOrInsert rather than a model save, as SmsThreadRead::markRead: no key
     * for Eloquent to update by, and two tabs marking the same thread at once
     * must not race into two rows (the unique index settles the rest).
     *
     * A no-op until the table exists, so a subclass calling it before the
     * migration has run costs nothing but the mark.
     */
    public static function mark(int $threadId, string $readerType, int $readerId, ?int $lastMessageId): void
    {
        if (! self::tableExists()) {
            return;
        }

        $now = now();

        DB::table(self::tableName())->updateOrInsert(
            ['thread_id' => $threadId, 'reader_type' => $readerType, 'reader_id' => $readerId],
            [
                'last_read_message_id' => $lastMessageId,
                'read_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }
}
