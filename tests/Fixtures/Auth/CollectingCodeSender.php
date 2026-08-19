<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;

/**
 * Captures what the code driver tried to deliver, so a test can read the code
 * the way the user's phone would.
 */
class CollectingCodeSender implements TwoFactorCodeSender
{
    /** @var array<int, array{user_id: mixed, code: string, message: string}> */
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

    public function send(object $user, string $code, string $message): void
    {
        if (self::$shouldFail) {
            throw new \RuntimeException('SMS gateway unreachable');
        }

        self::$sent[] = [
            'user_id' => $user->id,
            'code' => $code,
            'message' => $message,
        ];
    }
}
