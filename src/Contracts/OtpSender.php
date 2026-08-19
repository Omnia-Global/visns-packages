<?php

namespace Visnsstudio\VisnsPackages\Contracts;

/**
 * Delivers a passwordless-login OTP to a resolved contact record.
 *
 * One method covers every channel: the package tells the implementation which
 * contact field matched, and the implementation decides whether that means an
 * email or an SMS. That keeps channel-specific concerns (mail templates, SMS
 * gateways, number normalisation) in the application, where they belong.
 */
interface OtpSender
{
    /**
     * @param  object  $contact  The record returned by the contact resolver.
     * @param  string  $method   Which field matched - e.g. 'email1', 'email2',
     *                           'mobile', 'username'. The resolver decides the
     *                           vocabulary; the package only passes it through.
     * @param  string  $code     The plaintext OTP.
     *
     * @throws \Throwable Anything thrown is caught by the OTP controller and
     *                    answered as the configured `request_failed` message.
     */
    public function send(object $contact, string $method, string $code): void;
}
