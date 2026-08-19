<?php

namespace Visnsstudio\VisnsPackages\Auth;

use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;

/**
 * The fallback used when the code driver is switched on but no sender is bound.
 *
 * It deliberately fails loudly-but-safely: the login is still challenged (so
 * nobody gets in without a code), a warning names the misconfiguration, and the
 * code itself is NOT written to the log - a log file is not a delivery channel,
 * and a leaked code is a leaked second factor.
 */
class LogTwoFactorCodeSender implements TwoFactorCodeSender
{
    public function send(object $user, string $code, string $message): void
    {
        Log::warning(
            'visns-packages: no TwoFactorCodeSender bound; login code not delivered',
            ['user_id' => $user->id ?? null]
        );
    }
}
