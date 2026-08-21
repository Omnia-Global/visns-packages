<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

/**
 * Stands in for the CRM's mirror of the new password onto the client's contact
 * record.
 */
class MirrorPasswordHook
{
    /** @var array<int, array{user_id: mixed, password: string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function __invoke($user, string $plainPassword): void
    {
        self::$calls[] = [
            'user_id' => $user->id,
            'password' => $plainPassword,
        ];
    }
}
