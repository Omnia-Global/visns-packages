<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One Zoom webhook delivery, and what the CRM did with it.
 *
 * Written by WebhookLedger — once per delivery, in a finally block, so a row
 * exists even for the deliveries that blew up. Read by the call queue
 * diagnostics endpoint, which also prunes it.
 */
class ZoomWebhookEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'received_at' => 'datetime',
        'broadcast_ms' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * The table is configurable so an application that already owns one of these
     * tables (or namespaces its own) keeps its name. Resolved in getTable()
     * rather than the constructor so static query builders see it too.
     */
    public function getTable()
    {
        return ModuleConfig::get(
            'call_queue.tables.webhook_events',
            'zoom_webhook_events'
        );
    }

    /**
     * The shape the diagnostics screen renders. `received_at` carries its
     * offset: these rows are read from a laptop in another timezone often
     * enough that a bare wall clock would be a trap.
     */
    public function toDiagnosticsPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'event' => (string) $this->event,
            'call_id' => $this->call_id === null || $this->call_id === ''
                ? null
                : (string) $this->call_id,
            'queue_id' => $this->queue_id === null || $this->queue_id === ''
                ? null
                : (string) $this->queue_id,
            'queue_name' => $this->queue_name,
            'caller_number' => $this->caller_number,
            'outcome' => (string) $this->outcome,
            'broadcast' => $this->broadcast,
            'broadcast_ms' => $this->broadcast_ms === null
                ? null
                : (int) $this->broadcast_ms,
            'duration_ms' => $this->duration_ms === null
                ? null
                : (int) $this->duration_ms,
            'error' => $this->error,
            'received_at' => optional($this->received_at)->toIso8601String(),
        ];
    }
}
