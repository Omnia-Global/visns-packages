<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * A single in-flight call ringing in a Zoom Phone call queue.
 *
 * Rows are created by the Zoom webhook on `phone.callee_ringing` and removed
 * again on answer/end, so the table only ever holds "what is ringing right now".
 */
class ZoomLiveQueueCall extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'raw_payload' => 'array',
        'client_preview' => 'array',
    ];

    /**
     * The table is configurable so an application that already owns one of these
     * tables (or namespaces its own) keeps its name. Resolved in getTable()
     * rather than the constructor so static query builders see it too.
     */
    public function getTable()
    {
        return ModuleConfig::get('call_queue.tables.live_calls', 'zoom_live_queue_calls');
    }

    /**
     * The exact shape the call queue pop (and the broadcast contract) expects.
     *
     * `client` is the server-side caller -> client match (the configured
     * caller_enrichment hook), resolved once when the call started ringing. Null
     * when the number matched nobody.
     */
    public function toPopPayload(): array
    {
        return [
            'call_id' => (string) $this->call_id,
            // The Zoom call queue this rang on. `queue_id` keys the pickup-code
            // lookup (null when Zoom named the queue but gave no id, in which
            // case the card simply has no Pick up button); `queue_name` is the
            // badge text.
            'queue_id' => $this->queue_id === null || $this->queue_id === ''
                ? null
                : (string) $this->queue_id,
            'queue_name' => (string) ($this->queue_name ?: 'Call Queue'),
            'caller_number' => (string) ($this->caller_number ?? ''),
            'caller_name' => (string) ($this->caller_name ?? ''),
            'client' => is_array($this->client_preview)
                ? $this->client_preview
                : null,
            'started_at' => optional($this->started_at)->toIso8601String()
                ?? now()->toIso8601String(),
        ];
    }
}
