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
     * @param  int|null     $retryAfter         Seconds the provider asked us to wait, when it
     *                                          said so - `Retry-After` on a 429. Null means it
     *                                          did not say, which is NOT the same as zero: a
     *                                          caller reading null falls back to a default wait
     *                                          rather than to no wait at all.
     * @param  bool         $rateLimited        The provider said we are going too fast - a 429,
     *                                          specifically, and not merely a failure worth
     *                                          retrying. `retryable` is true for a 5xx as well,
     *                                          and a 5xx is Zoom having a bad morning rather
     *                                          than an instruction about our own pace, so the
     *                                          two cannot be one flag.
     * @param  int|null     $code               The provider's own numeric code, when it gave
     *                                          one - Zoom's `data.code`. Kept beside `error`
     *                                          rather than parsed back out of it, because
     *                                          `error` is a sentence for a person and is
     *                                          allowed to change wording.
     * @param  bool         $optedOut           The provider has BLOCKED this recipient, and
     *                                          will answer identically for ever until the
     *                                          recipient opts back in - Zoom's 7037. Not a
     *                                          flavour of `retryable` (it is the opposite of
     *                                          retryable) and not a flavour of `rateLimited`:
     *                                          it is a fact about the NUMBER rather than about
     *                                          this request, and the one refusal a caller may
     *                                          want to treat as expected rather than as a
     *                                          failure worth showing anybody.
     */
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly array $raw = [],
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly bool $rateLimited = false,
        public readonly ?int $code = null,
        public readonly bool $optedOut = false
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
     *
     * `$retryAfter` travels with the result rather than being read back off the
     * transport, and that is not tidiness: SmsService resolves a transport out
     * of the container per send, so the instance that answered a 429 is not the
     * instance a later caller would get hold of. The result is the only thing
     * that crosses that boundary.
     */
    public static function failed(
        string $error,
        array $raw = [],
        bool $retryable = false,
        ?int $retryAfter = null,
        bool $rateLimited = false,
        ?int $code = null,
        bool $optedOut = false
    ): self {
        return new self(
            null,
            SmsMessage::STATUS_FAILED,
            $error,
            $raw,
            $retryable,
            $retryAfter,
            $rateLimited,
            $code,
            $optedOut
        );
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
