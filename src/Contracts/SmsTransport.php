<?php

namespace Visnsstudio\VisnsPackages\Contracts;

use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Services\Sms\SmsSendResult;

/**
 * What it takes to actually put an SMS on the wire.
 *
 * The module is deliberately transport-agnostic because it was built before the
 * provider was: the practice is waiting on an SMS-capable mobile number for its
 * Zoom Phone account, and the inbox had to be usable - and demonstrable - in the
 * meantime. Three implementations ship:
 *
 *   NullSmsTransport   nothing leaves, the message is stored `not_connected`.
 *                      The production default while Zoom is not connected.
 *   LogSmsTransport    logs, reports success, and fakes a reply so the UI can be
 *                      exercised end to end. Development only.
 *   ZoomSmsTransport   the real thing.
 *
 * An implementation MUST NOT throw. A provider being down is an outcome of the
 * send, not an exception in the caller - the message row is already persisted by
 * the time this is called, and the status returned here is what the user sees
 * next to it. Return a failed result instead.
 *
 * An implementation MUST NOT modify or save the message it is given. Persisting
 * the outcome is the caller's job (Services\Sms\SmsService), which is what keeps
 * the status vocabulary in one place.
 */
interface SmsTransport
{
    /**
     * Hand one already-persisted outbound message to the provider.
     *
     * @param  SmsMessage  $message  Loaded, with its thread and line available.
     */
    public function send(SmsMessage $message): SmsSendResult;

    /**
     * The name this transport is configured as ('zoom', 'log', 'null', or a
     * class name). Reported by GET {base}/status so the UI can say plainly that
     * messaging is not connected yet rather than pretending to send.
     */
    public function name(): string;
}
