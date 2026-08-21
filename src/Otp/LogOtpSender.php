<?php

namespace Visnsstudio\VisnsPackages\Otp;

use Illuminate\Support\Facades\Log;

use Visnsstudio\VisnsPackages\Contracts\OtpSender;

/**
 * The sender used when an application enables OTP login but binds no sender of
 * its own.
 *
 * It delivers nothing and says so, loudly, in the log. That is the point: a
 * misconfigured deployment should be obvious on the first login attempt rather
 * than quietly accepting requests nobody can ever complete.
 *
 * The code is deliberately NOT logged. Application logs are read by more people
 * than the mailbox or handset the code was meant for, are shipped off-host, and
 * outlive the five minutes the code is good for - writing it there would turn
 * every log reader into someone who can log in as the client.
 */
class LogOtpSender implements OtpSender
{
    public function send(object $contact, string $method, string $code): void
    {
        Log::warning('No OtpSender is bound; the one-time code was not delivered.', [
            'contact_id' => $contact->id ?? null,
            'contact_method' => $method,
            'hint' => 'Bind ' . OtpSender::class
                . ' or set config("visns-packages.otp.sender").',
        ]);
    }
}
