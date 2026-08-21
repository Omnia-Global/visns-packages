<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Visnsstudio\VisnsPackages\Models\SmsMessage;

/**
 * What came back from a transport.
 *
 * A value object rather than an array because three of its four fields are
 * optional and an array would make every caller guess which keys are present.
 * Immutable: a result is a statement about something that already happened.
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
     */
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly array $raw = []
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
     */
    public static function failed(string $error, array $raw = []): self
    {
        return new self(null, SmsMessage::STATUS_FAILED, $error, $raw);
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
