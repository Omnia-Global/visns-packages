<?php

namespace Visnsstudio\VisnsPackages\Auth;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The whole lifecycle of the "code" two-factor driver: decide, issue, render,
 * send, verify, consume.
 *
 * It lives here rather than in the controller so the rules can be unit-tested
 * and reused (session login, API login, resend) without three copies drifting
 * apart. The class holds no state - every method works off the user model and
 * the config - so it is safe to resolve from the container per request.
 */
class TwoFactorCodeManager
{
    /**
     * 'totp' (authenticator app, the historical flow) or 'code'.
     */
    public function driver(): string
    {
        return (string) ModuleConfig::get('auth.two_factor.driver', 'totp');
    }

    /**
     * 'always' | 'ip_change' | 'never'.
     */
    public function trigger(): string
    {
        return (string) ModuleConfig::get('auth.two_factor.trigger', 'always');
    }

    /**
     * Remember-this-device is a TOTP feature by history; the code driver only
     * honours it when an application explicitly turns it on.
     */
    public function remembersDevices(): bool
    {
        return (bool) ModuleConfig::get('auth.two_factor.remember_device', false);
    }

    /**
     * Should this login be challenged?
     *
     * The production-only default is deliberate: both flows this replaces
     * (the package's TOTP check and the CRM's SMS check) only ever demanded a
     * second factor in production, because outside production there is no SMS
     * gateway and no shared authenticator, and a developer locked out of
     * staging cannot self-serve. `auth.two_factor.environments` widens the
     * gate deliberately (e.g. to review the challenge on a dev box with a
     * real sender wired) without changing any existing deployment's default.
     */
    public function shouldChallenge(object $user, Request $request): bool
    {
        $environments = (array) ModuleConfig::get(
            'auth.two_factor.environments',
            ['production']
        );

        if (! app()->environment(...$environments)) {
            return false;
        }

        switch ($this->trigger()) {
            case 'never':
                return false;

            case 'ip_change':
                $ipColumn = $this->ipColumn();

                // Cast both sides: the column may be null on a first login, and
                // a null !== '1.2.3.4' comparison would then always challenge -
                // which is in fact what we want, but say so explicitly.
                return (string) ($user->{$ipColumn} ?? '') !== (string) $request->ip();

            case 'always':
            default:
                return true;
        }
    }

    /**
     * Generate, persist and return a fresh code.
     */
    public function issue(object $user): string
    {
        // random_int, not rand/mt_rand: this is a credential.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->{$this->codeColumn()} = $code;
        $user->{$this->sentAtColumn()} = now();
        $user->save();

        return $code;
    }

    /**
     * The message body handed to the sender.
     */
    public function render(string $code): string
    {
        $message = str_replace(
            '{code}',
            $code,
            (string) ModuleConfig::get(
                'auth.two_factor.message_template',
                'Your verification code is: {code}'
            )
        );

        if (ModuleConfig::get('auth.two_factor.append_autofill_suffix', true)) {
            // iOS/Android SMS autofill only offers the code when the trailer
            // names the domain the user is logging in to, in exactly this form.
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            $message .= "\n\n@" . $host . ' #' . $code;
        }

        return $message;
    }

    /**
     * Deliver a code. Anything the sender throws propagates: the caller turns it
     * into a refused login rather than letting someone in with no code sent.
     */
    public function send(object $user, string $code): void
    {
        $sender = ModuleConfig::service(
            'auth.two_factor.sender',
            TwoFactorCodeSender::class,
            LogTwoFactorCodeSender::class
        );

        $sender->send($user, $code, $this->render($code));
    }

    /**
     * @return true|string  true, or the message key describing the failure
     *                      ('two_factor_code_missing', 'two_factor_code_expired',
     *                      'invalid_two_factor_code').
     */
    public function verify(object $user, string $code)
    {
        $stored = $user->{$this->codeColumn()} ?? null;
        $sentAt = $user->{$this->sentAtColumn()} ?? null;

        if ($stored === null || $stored === '' || empty($sentAt)) {
            return 'two_factor_code_missing';
        }

        $sentAt = $sentAt instanceof DateTimeInterface
            ? Carbon::instance($sentAt)
            : Carbon::parse((string) $sentAt);

        if ($sentAt->addMinutes($this->expiryMinutes())->isPast()) {
            return 'two_factor_code_expired';
        }

        // hash_equals over ==: the comparison is on a secret, and a length-safe
        // constant-time check costs nothing here.
        if (! hash_equals(trim((string) $stored), trim($code))) {
            return 'invalid_two_factor_code';
        }

        return true;
    }

    /**
     * Burn the code. Single use, whatever the expiry window says - a code that
     * survived its login could be replayed from an SMS backup or a shared inbox.
     */
    public function consume(object $user): void
    {
        $user->{$this->codeColumn()} = null;
        $user->{$this->sentAtColumn()} = null;
        $user->save();
    }

    protected function ipColumn(): string
    {
        return (string) ModuleConfig::get(
            'auth.two_factor.ip_column',
            'last_logged_ip_address'
        );
    }

    protected function codeColumn(): string
    {
        return (string) ModuleConfig::get(
            'auth.two_factor.code_column',
            'two_factor_token'
        );
    }

    protected function sentAtColumn(): string
    {
        return (string) ModuleConfig::get(
            'auth.two_factor.code_sent_at_column',
            'two_factor_token_sent_at'
        );
    }

    protected function expiryMinutes(): int
    {
        return (int) ModuleConfig::get('auth.two_factor.expiry_minutes', 15);
    }
}
