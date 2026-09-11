<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Visnsstudio\VisnsPackages\Models\SmsMessage;

/**
 * What came back from a transport.
 *
 * A value object rather than an array because most of its fields are optional
 * and an array would make every caller guess which keys are present. Immutable:
 * a result is a statement about something that already happened.
 *
 * ## `retryable` is advice about the TRANSPORT, not about the message
 *
 * `status` records what happened to this message and is what goes in the row.
 * `retryable` answers a different question - "would asking again in a minute be
 * worth anything" - and it is true only for the answers that are about the
 * provider rather than about the recipient: a 429, a 5xx, a request that never
 * completed. A refused number is never retryable, because Zoom will refuse it
 * again at the same price.
 *
 * Nothing in the interactive path reads it: a staff member pressing send is
 * told what happened and decides for themselves. It exists for
 * Services\Sms\SmsCampaignSender, which has four hundred more messages to send
 * and has to tell "the account is rate-limited, come back in a minute" from
 * "this one is never going to work" without a human in the loop. Defaulting it
 * to false means every transport that has not thought about it - the null one,
 * the dev one, an application's own - keeps behaving exactly as it did.
 */
class SmsSendResult
{
    /**
     * @param  string|null  $providerMessageId  The provider's own id, when it gave one.
     *                                          Stored unique, so it is also what makes
     *                                          a webhook redelivery idempotent.
     * @param  string       $status             One of the SmsMessage::STATUS_* values.
     * @param  string|null  $error              Human-readable; shown next to a failed message.
     * @param  array        $raw                The provider's response, kept verbatim.
     * @param  bool         $retryable          Whether the same send, later, might work.
     */
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly array $raw = [],
        public readonly bool $retryable = false
    ) {
    }

    /**
     * The provider took it.
     */
    public static function sent(?string $providerMessageId = null, array $raw = []): self
    {
        return new self($providerMessageId, SmsMessage::STATUS_SENT, null, $raw);
    }

    /**
     * The provider refused it, or could not be reached.
     *
     * `$retryable` defaults to false, which is the conservative answer: a
     * transport that has not thought about the question says "do not try this
     * again", and the cost of being wrong is a campaign recipient marked failed
     * rather than a client texted twice.
     */
    public static function failed(string $error, array $raw = [], bool $retryable = false): self
    {
        return new self(null, SmsMessage::STATUS_FAILED, $error, $raw, $retryable);
    }

    /**
     * There is no provider yet. Distinct from `failed` on purpose: a failure
     * invites a retry, and there is nothing here to retry against.
     */
    public static function notConnected(string $error, array $raw = []): self
    {
        return new self(null, SmsMessage::STATUS_NOT_CONNECTED, $error, $raw);
    }

    public function successful(): bool
    {
        return in_array(
            $this->status,
            [SmsMessage::STATUS_SENT, SmsMessage::STATUS_DELIVERED],
            true
        );
    }
}
