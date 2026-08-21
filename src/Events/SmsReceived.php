<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Support\SmsChannel;
use Visnsstudio\VisnsPackages\Support\SmsPayload;

/**
 * An inbound SMS arrived on one of the lines.
 *
 * Broadcast synchronously (ShouldBroadcastNow) on the LINE's private channel: an
 * SMS notification that arrives after the staff member has gone home is worth
 * very little, and the queue in these deployments is `sync` anyway.
 *
 * CONSTRUCTOR CONTRACT - a class configured in `messaging.events.received` is
 * constructed with exactly these arguments:
 *
 *     __construct(SmsThread $thread, SmsMessage $message)
 *
 * and both are the PACKAGE's models, not an application's own classes of the
 * same name. A replacement must widen or drop those type hints.
 */
class SmsReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SmsThread $thread;

    public SmsMessage $message;

    public function __construct(SmsThread $thread, SmsMessage $message)
    {
        $this->thread = $thread;
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new PrivateChannel(SmsChannel::name($this->thread->line_id));
    }

    public function broadcastAs()
    {
        return 'sms.received';
    }

    public function broadcastWith()
    {
        // No user in scope on a broadcast, so `unread_count` comes back null -
        // see SmsPayload.
        return [
            'thread' => SmsPayload::thread($this->thread),
            'message' => SmsPayload::message($this->message),
        ];
    }
}
