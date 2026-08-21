<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One SMS, in or out.
 *
 * Nothing in this module ever deletes one of these rows. They are client
 * communications under an AFSL record-keeping obligation; `sms:prune` archives
 * threads and leaves the messages exactly where they are.
 */
class SmsMessage extends Model
{
    /** Accepted by us, not yet handed to a transport. */
    public const STATUS_QUEUED = 'queued';

    /** The transport accepted it. */
    public const STATUS_SENT = 'sent';

    /** The carrier confirmed it reached the handset. */
    public const STATUS_DELIVERED = 'delivered';

    /** The transport or carrier rejected it; `error` says why. */
    public const STATUS_FAILED = 'failed';

    /** Inbound. */
    public const STATUS_RECEIVED = 'received';

    /**
     * Stored but never sent, because no transport is connected. The whole point
     * of the null transport: the practice can compose and see its drafts in the
     * thread, greyed out, while the SMS-capable number is still being
     * provisioned.
     */
    public const STATUS_NOT_CONNECTED = 'not_connected';

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    protected $guarded = [];

    protected $casts = [
        'attachments' => 'array',
        'raw_payload' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.messages', 'sms_messages');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function thread()
    {
        return $this->belongsTo(SmsThread::class, 'thread_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(ModuleConfig::userModel('messaging'), 'user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }
}
