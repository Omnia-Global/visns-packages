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
 * A message on one of the lines changed: it was sent from here, or the provider
 * has told us it was delivered, or it failed.
 *
 * The same event covers all three because the front end does the same thing with
 * each - replace the message in place. Splitting them would only mean three
 * listeners doing one job.
 *
 * CONSTRUCTOR CONTRACT - a class configured in `messaging.events.updated` is
 * constructed with exactly these arguments:
 *
 *     __construct(SmsThread $thread, SmsMessage $message)
 *
 * and both are the PACKAGE's models. A replacement must widen or drop those type
 * hints.
 */
class SmsMessageUpdated implements ShouldBroadcastNow
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
        return 'sms.updated';
    }

    public function broadcastWith()
    {
        return [
            'thread' => SmsPayload::thread($this->thread),
            'message' => SmsPayload::message($this->message),
        ];
    }
}
