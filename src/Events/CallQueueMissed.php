<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;

/**
 * ONE leg of a monitored call stopped ringing — declined, or timed out.
 *
 * Emphatically not `queue.ended`. Zoom sends `phone.callee_missed` per leg: a
 * queue rings four handsets on a single call_id, and the first person to wave
 * the call away used to close everybody else's pop while their phones were
 * still ringing. A direct call does it to itself, because the desk phone and
 * the mobile app are two legs of the same call.
 *
 * So this says only "that one stopped", and the row stays. The pop may dim the
 * card or do nothing at all; `queue.ended` is still what closes it.
 */
class CallQueueMissed implements ShouldBroadcastNow
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
        return 'queue.missed';
    }

    public function broadcastWith()
    {
        return ['call_id' => $this->callId];
    }
}
