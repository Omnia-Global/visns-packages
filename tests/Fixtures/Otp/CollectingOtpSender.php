<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Otp;

use Visnsstudio\VisnsPackages\Contracts\OtpSender;

/**
 * Captures what the OTP module tried to deliver, and to which channel.
 */
class CollectingOtpSender implements OtpSender
{
    /** @var array<int, array{contact_id: mixed, method: string, code: string}> */
    public static array $sent = [];

    public static bool $shouldFail = false;

    public static function reset(): void
    {
        self::$sent = [];
        self::$shouldFail = false;
    }

    public static function lastCode(): ?string
    {
        $last = end(self::$sent);

        return $last === false ? null : $last['code'];
    }

    public function send(object $contact, string $method, string $code): void
    {
        if (self::$shouldFail) {
            throw new \RuntimeException('SMS gateway unreachable');
        }

        self::$sent[] = [
            'contact_id' => $contact->id,
            'method' => $method,
            'code' => $code,
        ];
    }
}
