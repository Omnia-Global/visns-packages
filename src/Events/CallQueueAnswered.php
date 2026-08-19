<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;

/**
 * A monitored queue call was picked up — every open pop for it should close.
 */
class CallQueueAnswered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $callId;

    public function __construct(string $callId)
    {
        $this->callId = $callId;
    }

    public function broadcastOn()
    {
        // Optionally environment-scoped: where dev and prod share one Pusher
        // app, the channel name is what keeps their broadcasts apart.
        return new PrivateChannel(CallQueueChannel::name());
    }

    public function broadcastAs()
    {
        return 'queue.answered';
    }

    public function broadcastWith()
    {
        return ['call_id' => $this->callId];
    }
}
