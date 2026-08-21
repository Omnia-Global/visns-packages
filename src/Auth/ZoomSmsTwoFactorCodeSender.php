<?php

namespace Visnsstudio\VisnsPackages\Auth;

use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Models\SmsSystemMessage;
use Visnsstudio\VisnsPackages\Services\Sms\SmsSystemSender;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Delivers the login code over the messaging module's Zoom Phone line.
 *
 * The package already owns an SMS-capable number for the client inbox, so an
 * application that has switched messaging on needs no second gateway for its
 * second factor: point `auth.two_factor.sender` at this class and the code goes
 * out on the same line the practice texts clients from.
 *
 * It sends through SmsSystemSender, NOT SmsService, and that is the whole point.
 * SmsService writes into a thread, and a thread is readable by every staff
 * member attached to the line - one adviser could open the inbox and read
 * another's login code. Nothing here reaches the inbox: no thread, no
 * broadcast, and the code itself is never stored or logged anywhere.
 *
 * Failure THROWS, because the contract says so: TwoFactorCodeManager lets
 * whatever a sender throws propagate, and the login controller turns it into
 * `two_factor_send_failed`. A login refused because the code could not be sent
 * is correct; a login allowed through because nobody noticed the send failed is
 * an authentication bypass.
 */
class ZoomSmsTwoFactorCodeSender implements TwoFactorCodeSender
{
    public function __construct(private SmsSystemSender $sender)
    {
    }

    /**
     * @param  object  $user     The user logging in.
     * @param  string  $code     The plaintext code. NOT used here beyond being
     *                           already baked into $message - it must not be
     *                           logged, stored or passed anywhere else.
     * @param  string  $message  The rendered body, autofill trailer included.
     *
     * @throws \RuntimeException When there is no mobile on file, or the send
     *                           did not reach the provider.
     */
    public function send(object $user, string $code, string $message): void
    {
        $column = (string) ModuleConfig::get('auth.two_factor.mobile_column', 'mobile');

        $mobile = $user->{$column} ?? null;
        $mobile = is_scalar($mobile) ? trim((string) $mobile) : '';

        if ($mobile === '') {
            // The number is not in the exception message: this text reaches an
            // error response, and a login screen is not a place to confirm
            // which mobile an account is attached to.
            $this->refuse($user, 'no mobile number on file');
        }

        $result = $this->sender->send(
            $mobile,
            $message,
            SmsSystemMessage::PURPOSE_TWO_FACTOR
        );

        if (! $result->ok) {
            $this->refuse($user, (string) ($result->error ?? 'the SMS could not be sent'));
        }

        // Provider id only - enough to find the send in Zoom's own logs when a
        // user says the code never arrived, and nothing that would let a reader
        // of the log file use it.
        Log::info('two-factor code sent by sms', [
            'user_id' => $user->id ?? null,
            'provider_message_id' => $result->providerMessageId,
        ]);
    }

    /**
     * Log why, then throw. One place, so the log line and the exception can
     * never say different things.
     *
     * @throws \RuntimeException
     */
    private function refuse(object $user, string $reason): void
    {
        Log::warning('two-factor code could not be sent by sms', [
            'user_id' => $user->id ?? null,
            'reason' => $reason,
        ]);

        throw new \RuntimeException('Could not send the login code: ' . $reason);
    }
}
