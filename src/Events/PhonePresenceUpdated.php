<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Visnsstudio\VisnsPackages\Models\ZoomPhoneLiveCall;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;

/**
 * One extension's phone state changed: it started ringing, connected, or hung up.
 *
 * Broadcast on the call queue's own channel rather than one of its own. The two
 * surfaces are gated on the same permission and watched by the same people, and
 * a second private channel would mean a second `/broadcasting/auth` round trip
 * and a second registration in every consuming application's channels.php for
 * no gain.
 *
 * ShouldBroadcastNow for the same reason the pop's events are: the queue is
 * `sync`, and a roster that catches up thirty seconds late is the polling
 * fallback, not the live path.
 */
class PhonePresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ZoomPhoneLiveCall $call;

    /** True when the leg has just been removed, i.e. the extension is free. */
    public bool $cleared;

    public function __construct(ZoomPhoneLiveCall $call, bool $cleared = false)
    {
        $this->call = $call;
        $this->cleared = $cleared;
    }

    public function broadcastOn()
    {
        return new PrivateChannel(CallQueueChannel::name());
    }

    public function broadcastAs()
    {
        return 'phone.presence';
    }

    /**
     * `keys` is how the browser finds the roster row to patch: the same
     * identifier list the server matches on, in the same order, so neither side
     * has to know which one Zoom actually supplied.
     */
    public function broadcastWith()
    {
        return [
            'cleared' => $this->cleared,
            'keys' => $this->call->rosterKeys(),
            'call' => $this->cleared ? null : $this->call->toPresencePayload(),
        ];
    }
}
