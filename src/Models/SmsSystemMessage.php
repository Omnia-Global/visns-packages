<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One application-originated text - a login code, a portal OTP - and what
 * happened to it.
 *
 * Kept apart from SmsMessage on purpose: an SmsMessage belongs to a thread, and
 * a thread is readable by everyone attached to its line. A second factor sent to
 * one person must not be legible to another, so these rows have no thread, no
 * inbox and no read endpoint.
 *
 * There is no `body` attribute because there is no `body` column. If a future
 * change is tempted to add one, the code it would store is the reason not to.
 *
 * No soft deletes: nothing deletes these, and a `deleted_at` would only invite
 * something to.
 */
class SmsSystemMessage extends Model
{
    /** Written, not yet handed to the provider. */
    public const STATUS_QUEUED = 'queued';

    /** The provider accepted it. */
    public const STATUS_SENT = 'sent';

    /** The provider refused it, or could not be reached; `error` says why. */
    public const STATUS_FAILED = 'failed';

    /**
     * There is no SMS provider configured, so nothing left the building.
     * Distinct from `failed` for the same reason it is on SmsMessage: a failure
     * invites a retry and there is nothing here to retry against.
     */
    public const STATUS_NOT_CONNECTED = 'not_connected';

    /** The two purposes the package itself sends for. */
    public const PURPOSE_TWO_FACTOR = 'two_factor';
    public const PURPOSE_PORTAL_OTP = 'portal_otp';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * Resolved here rather than in the constructor so static query builders see
     * the configured name too.
     */
    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.system_messages', 'sms_system_messages');
    }

    /**
     * The line it was sent from, when that line still exists.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function line()
    {
        return $this->belongsTo(SmsLine::class, 'line_id');
    }

    /**
     * The row a provider webhook is talking about, or null.
     *
     * The webhook handler's first question on every `phone.sms_sent` /
     * `phone.sms_sent_failed`: a hit here means "this was a login code, update
     * it and do NOT build an inbox thread out of it".
     */
    public static function findByProviderId(?string $providerMessageId): ?self
    {
        $id = is_string($providerMessageId) ? trim($providerMessageId) : '';

        if ($id === '') {
            return null;
        }

        return static::query()->where('provider_message_id', $id)->first();
    }

    public function successful(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
