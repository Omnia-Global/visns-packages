<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;

/**
 * A call started ringing in one of the monitored Zoom Phone call queues.
 *
 * Broadcast synchronously (ShouldBroadcastNow): the queue is `sync`, and a call
 * pop that arrives after the phone stopped ringing is worthless.
 */
class CallQueueRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ZoomLiveQueueCall $call;

    public function __construct(ZoomLiveQueueCall $call)
    {
        $this->call = $call;
    }

    public function broadcastOn()
    {
        // Optionally environment-scoped: where dev and prod share one Pusher
        // app, the channel name is what keeps their broadcasts apart.
        return new PrivateChannel(CallQueueChannel::name());
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
