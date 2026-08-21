<?php

namespace Visnsstudio\VisnsPackages\Contracts;

/**
 * Delivers a one-time login code to the user.
 *
 * The package generates, stores and verifies the code; how it reaches the human
 * (SMS gateway, email, push) is entirely the application's business, so it binds
 * an implementation of this and the package never learns what a phone number is.
 *
 * Bind it in a service provider:
 *
 *     $this->app->bind(TwoFactorCodeSender::class, SmsTwoFactorCodeSender::class);
 *
 * or name the class in config('visns-packages.auth.two_factor.sender').
 */
interface TwoFactorCodeSender
{
    /**
     * @param  object  $user     The user logging in.
     * @param  string  $code     The plaintext code, already zero-padded.
     * @param  string  $message  The rendered message body, including the
     *                           autofill suffix when that is enabled.
     *
     * @throws \Throwable Anything thrown is caught by the caller and turned
     *                    into the `two_factor_send_failed` message: a login is
     *                    refused rather than let through when the code could
     *                    not be delivered.
     */
    public function send(object $user, string $code, string $message): void;
}
