<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Visnsstudio\VisnsPackages\Models\SmsSystemMessage;

/**
 * What came back from SmsSystemSender.
 *
 * Shaped like SmsSendResult - a value object with static constructors, because
 * three of its four fields are optional and an array would make every caller
 * guess which keys are present - but a separate class rather than a reuse: this
 * one carries an SmsSystemMessage and no `status`/`raw`, and merging the two
 * would give each caller fields that are always null for it.
 *
 * Immutable: a result is a statement about something that already happened.
 */
final class SmsSystemResult
{
    /**
     * @param  bool                    $ok                 Did the provider take it?
     * @param  string|null             $error              Human-readable; logged, and
     *                                                     turned into the caller's own
     *                                                     failure (a refused login).
     * @param  string|null             $providerMessageId  The provider's id, when it gave
     *                                                     one. Also what the delivery
     *                                                     webhook matches on.
     * @param  SmsSystemMessage|null   $record             The audit row, when one was
     *                                                     written - null only when the
     *                                                     send failed before it could be.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?SmsSystemMessage $record = null
    ) {
    }

    public static function sent(?string $providerMessageId = null, ?SmsSystemMessage $record = null): self
    {
        return new self(true, null, $providerMessageId, $record);
    }

    public static function failed(string $error, ?SmsSystemMessage $record = null): self
    {
        return new self(false, $error, null, $record);
    }

    public function successful(): bool
    {
        return $this->ok;
    }
}
