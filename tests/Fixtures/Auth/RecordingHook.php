<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use Illuminate\Http\Request;

/**
 * Stands in for the CRM's last-login stamp hook.
 */
class RecordingHook
{
    /** @var array<int, mixed> */
    public static array $calls = [];

    public function __invoke($user, Request $request): void
    {
        self::$calls[] = $user->id;

        $user->last_logged_ip_address = $request->ip();
        $user->dateLastLogged = now();
        $user->save();
    }
}
