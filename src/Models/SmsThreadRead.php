<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One staff member's read high-water mark in one thread.
 *
 * Keyless by design: the table's identity is (thread_id, user_id) and Eloquent
 * is told so, because a surrogate id would only be another thing to keep unique.
 */
class SmsThreadRead extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
        'last_read_message_id' => 'integer',
    ];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.thread_reads', 'sms_thread_reads');
    }

    /**
     * Move a user's mark to the newest message in a thread.
     *
     * Written with updateOrInsert rather than a model save because the table has
     * no key for Eloquent to update by, and because two browser tabs marking the
     * same thread read at once must not race into two rows.
     */
    public static function markRead(int $threadId, $userId, ?int $lastMessageId): void
    {
        if ($userId === null) {
            return;
        }

        $table = (string) ModuleConfig::get('messaging.tables.thread_reads', 'sms_thread_reads');

        \Illuminate\Support\Facades\DB::table($table)->updateOrInsert(
            ['thread_id' => $threadId, 'user_id' => $userId],
            [
                'last_read_message_id' => $lastMessageId,
                'read_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
