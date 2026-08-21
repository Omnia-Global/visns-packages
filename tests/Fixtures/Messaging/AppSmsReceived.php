<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stands in for an application's own App\Events\SmsReceived - the case the
 * `messaging.events` config exists for.
 *
 * Note both constructor parameters are untyped. The module hands over the
 * PACKAGE's SmsThread and SmsMessage, so an application class type-hinting its
 * own models of the same name would be a TypeError; it must widen or drop the
 * hints. That is the required constructor contract, and this fixture is what
 * honouring it looks like.
 */
class AppSmsReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $thread;

    public $message;

    public function __construct($thread, $message)
    {
        $this->thread = $thread;
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('app-owned-sms-channel');
    }

    public function broadcastAs()
    {
        return 'sms.received';
    }

    public function broadcastWith()
    {
        return ['thread' => $this->thread->id, 'message' => $this->message->id];
    }
}
