<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stands in for an application's own App\Events\CallQueueRinging - the case the
 * `call_queue.events` config exists for.
 *
 * Note the constructor takes the call untyped. The webhook hands over a
 * Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall, so an application class
 * that type-hints its OWN model of the same name would be a TypeError; it must
 * widen or drop the hint. That is the required constructor contract, and this
 * fixture is what honouring it looks like.
 */
class AppCallQueueRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $call;

    public function __construct($call)
    {
        $this->call = $call;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('app-owned-channel');
    }

    public function broadcastAs()
    {
        return 'queue.ringing';
    }

    public function broadcastWith()
    {
        return ['call' => $this->call->toPopPayload()];
    }
}
