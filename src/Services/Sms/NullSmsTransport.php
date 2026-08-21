<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Visnsstudio\VisnsPackages\Contracts\SmsTransport;
use Visnsstudio\VisnsPackages\Models\SmsMessage;

/**
 * The transport for "the number is not provisioned yet".
 *
 * This is the module's default, and the one it is safe to leave switched on in
 * production while the SMS-capable Zoom number is still being arranged. Nothing
 * leaves the building; the message is still written to the thread, with status
 * `not_connected`, so the practice can see exactly what would have gone out and
 * nobody is left wondering whether a client was texted.
 *
 * The alternative - refusing the send with a 4xx - was rejected deliberately: it
 * would have meant the composer could not be used or demonstrated at all, and
 * the day Zoom is connected there would be no record of what people had wanted
 * to send.
 */
class NullSmsTransport implements SmsTransport
{
    public const MESSAGE = 'Messaging is not connected to Zoom yet.';

    public function send(SmsMessage $message): SmsSendResult
    {
        return SmsSendResult::notConnected(self::MESSAGE);
    }

    public function name(): string
    {
        return 'null';
    }
}
