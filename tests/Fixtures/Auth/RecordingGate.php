<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use Illuminate\Http\Request;

/**
 * A gate that lets everything through but records that it ran, so a test can
 * prove WHEN gates are called relative to the password check.
 */
class RecordingGate
{
    /** @var array<int, mixed> */
    public static array $calls = [];

    public function __invoke($user, Request $request)
    {
        self::$calls[] = $user->id;

        return null;
    }
}
