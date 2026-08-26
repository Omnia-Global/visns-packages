<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One in-flight leg of a Zoom Phone call, belonging to one internal extension.
 *
 * Written by PhonePresenceRecorder from the same signed webhook the call queue
 * pop uses, and read by the Zoom Phone roster. Rows are transient: they exist
 * for as long as the leg does, and the roster prunes stale ones on read because
 * Zoom does not guarantee a closing event for every leg.
 */
class ZoomPhoneLiveCall extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'client_preview' => 'array',
        'raw_payload' => 'array',
    ];

    /**
     * Configurable for the same reason every other table in this package is:
     * an application that already owns a table of this name keeps it. Resolved
     * in getTable() rather than the constructor so static query builders see it.
     */
    public function getTable()
    {
        return ModuleConfig::get(
            'call_queue.presence.tables.live_calls',
            'zoom_phone_live_calls'
        );
    }

    /**
     * The `call` block on a roster row, and the payload the broadcast carries.
     *
     * `client` is the peer number -> client match resolved by the enrichment
     * hook when the leg started; null when the number matched nobody, or when
     * no hook is configured.
     */
    public function toPresencePayload(): array
    {
        return [
            'call_id' => (string) $this->call_id,
            'direction' => (string) ($this->direction ?: 'inbound'),
            'status' => (string) ($this->status ?: 'ringing'),
            'peer_number' => $this->nullableString($this->peer_number),
            'peer_name' => $this->nullableString($this->peer_name),
            'queue_name' => $this->nullableString($this->queue_name),
            'client' => is_array($this->client_preview) ? $this->client_preview : null,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'answered_at' => optional($this->answered_at)->toIso8601String(),
        ];
    }

    /**
     * How this leg is matched to a roster user.
     *
     * Zoom's webhooks and `GET /phone/users` do not agree on identifiers: the
     * webhook gives `user_id` and `extension_id`, the roster gives `id` and
     * `extension_number`. The one they reliably share is the user id, with the
     * extension number as the fallback — so both are published and the roster
     * tries them in that order.
     */
    public function rosterKeys(): array
    {
        return array_values(array_filter([
            $this->nullableString($this->zoom_user_id),
            $this->nullableString($this->extension_number),
            $this->nullableString($this->extension_id),
        ]));
    }

    private function nullableString($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
