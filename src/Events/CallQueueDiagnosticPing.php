<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;

/**
 * A test broadcast on the call queue channel, fired by the diagnostics endpoint.
 *
 * The whole point is to prove the leg between PHP and the browser without
 * waiting for somebody to ring the office: same channel, same private
 * subscription, same ShouldBroadcastNow path as CallQueueRinging, so a ping the
 * browser receives means a real pop would have arrived too.
 *
 * NOT routed through `call_queue.events` — that map exists so an application's
 * own event classes can be dispatched for the three real call events, and there
 * is nothing for an application to substitute here. This one stays package
 * owned.
 */
class CallQueueDiagnosticPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $nonce;

    public string $sentAt;

    public function __construct(string $nonce, ?string $sentAt = null)
    {
        $this->nonce = $nonce;
        $this->sentAt = $sentAt ?? now()->toIso8601String();
    }

    public function broadcastOn()
    {
        // Exactly the channel the pop listens on — an environment-scoped name
        // included. A ping on a channel of its own would prove nothing.
        return new PrivateChannel(CallQueueChannel::name());
    }

    public function broadcastAs()
    {
        return 'queue.diagnostic-ping';
    }

    public function broadcastWith()
    {
        return [
            'nonce' => $this->nonce,
            'sent_at' => $this->sentAt,
        ];
    }
}
