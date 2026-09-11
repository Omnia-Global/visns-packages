<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * One person on one campaign's list.
 *
 * The row is the audit trail: it says what was attempted, what came back, and
 * which ordinary inbox rows the send produced. `message_id` and `thread_id` are
 * plain ids rather than relations with foreign keys - see the migration - so
 * the campaign's history survives anything that happens to the conversation
 * afterwards.
 *
 * `pending` means "attempt this", and it is the only status the sender picks
 * up. A retryable failure LEAVES the row pending and bumps `retries`, which is
 * the whole retry mechanism: there is no separate queue, no delayed job and
 * nothing to lose if the process dies mid-run.
 */
class SmsCampaignRecipient extends Model
{
    /** Not yet attempted, or attempted and worth attempting again. */
    public const STATUS_PENDING = 'pending';

    /** Handed to the transport; `message_id` and `thread_id` say where it went. */
    public const STATUS_SENT = 'sent';

    /** Refused, or out of retries. `error` says which. */
    public const STATUS_FAILED = 'failed';

    /** Never attempted: opted out, or the campaign was cancelled. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * The `error` written when a recipient is skipped for having opted out.
     *
     * A code rather than a sentence, because it is the one "failure" that is
     * actually the system working correctly, and a screen wants to draw it
     * differently from "Zoom refused this number".
     */
    public const ERROR_OPTED_OUT = 'opted_out';

    /** The `error` written to the pending rows of a cancelled campaign. */
    public const ERROR_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'extra' => 'array',
        'retries' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get(
            'messaging.tables.campaign_recipients',
            'sms_campaign_recipients'
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function campaign()
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function getDisplayNumberAttribute(): string
    {
        return PhoneNumber::toLocal(
            $this->number,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );
    }
}
