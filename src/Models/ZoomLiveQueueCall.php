<?php

namespace Visnsstudio\VisnsPackages\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * A single in-flight call ringing on a Zoom Phone extension.
 *
 * Two kinds of row, told apart by `kind`:
 *
 *   queue   Ringing in a call queue. Carries the queue's id and name, and the
 *           queue's pickup code is what staff dial to grab it.
 *   direct  Ringing somebody's own extension (or a common-area handset): a
 *           direct dial, an internal call, or a transfer. There is no queue to
 *           name it, so the callee columns carry who it is ringing.
 *
 * Rows are created by the Zoom webhook on `phone.callee_ringing` and removed
 * again on answer/end, so the table only ever holds "what is ringing right now".
 */
class ZoomLiveQueueCall extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'last_ringing_at' => 'datetime',
        'last_missed_at' => 'datetime',
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
     * Rows that are genuinely still ringing.
     *
     * The subtlety is `phone.callee_missed`. Zoom sends it when ONE leg gives
     * up — a queue member declining while the queue keeps ringing the other four
     * handsets, or a desk phone timing out while the mobile app is still going.
     * It is not "the call is over", so it no longer deletes the row; it stamps
     * `last_missed_at` and lets this decide.
     *
     * A row stays live while any of these holds:
     *
     *   - nothing has missed it yet;
     *   - something rang AFTER the last miss, so the call is plainly still
     *     alive somewhere in the routing;
     *   - the miss is younger than `missed_grace_seconds`. Zoom's per-leg events
     *     arrive out of order often enough that a miss and the next leg's
     *     ringing can land the wrong way round, and a card that blinks off and
     *     back on is worse than one that lingers a few seconds.
     *
     * The single definition of "live": anything needing the same answer asks
     * here rather than re-deriving it.
     */
    public function scopeLive(Builder $query): Builder
    {
        $cutoff = self::missedCutoff();

        return $query
            ->where('status', 'ringing')
            ->where(function (Builder $q) use ($cutoff) {
                $q->whereNull('last_missed_at')
                    ->orWhereColumn('last_ringing_at', '>', 'last_missed_at')
                    ->orWhere('last_missed_at', '>', $cutoff);
            });
    }

    /**
     * The mirror image of scopeLive()'s miss clause: rows a leg gave up on, that
     * nothing rang again, and whose grace has run out.
     *
     * Deleted opportunistically by the snapshot endpoint. Without it every
     * declined call would sit in the table until the far longer stale-ring
     * window caught it.
     */
    public function scopeDeadAfterMiss(Builder $query): Builder
    {
        $cutoff = self::missedCutoff();

        return $query
            ->whereNotNull('last_missed_at')
            ->where('last_missed_at', '<=', $cutoff)
            ->where(function (Builder $q) {
                $q->whereNull('last_ringing_at')
                    ->orWhereColumn('last_ringing_at', '<=', 'last_missed_at');
            });
    }

    /**
     * The grace window itself, in seconds.
     *
     * Read here rather than at each call site because it now leaves the server:
     * `CallQueueMissed` broadcasts it so the browser's timer and this scope's
     * cutoff are the same number. A browser that removed a card sooner than
     * this had it put back by the next snapshot, which still listed the call as
     * live — see the note on CallQueueMissed::broadcastWith().
     */
    public static function missedGraceSeconds(): int
    {
        return max(0, (int) ModuleConfig::get('call_queue.missed_grace_seconds', 20));
    }

    /**
     * How long ago a miss stops keeping a card on screen.
     */
    public static function missedCutoff(): Carbon
    {
        return Carbon::now()->subSeconds(self::missedGraceSeconds());
    }

    /**
     * The exact shape the call queue pop (and the broadcast contract) expects.
     *
     * `client` is the server-side caller -> client match (the configured
     * caller_enrichment hook), resolved once when the call started ringing. Null
     * when the number matched nobody.
     *
     * Every key that existed before direct calls did is unchanged and in the
     * same place; the direct-call keys are added after them, so a front end that
     * has not been taught about them simply ignores them.
     */
    public function toPopPayload(): array
    {
        $direct = $this->isDirect();

        $queueId = $this->queue_id === null || $this->queue_id === ''
            ? null
            : (string) $this->queue_id;

        return [
            'call_id' => (string) $this->call_id,
            // The Zoom call queue this rang on. `queue_id` keys the pickup-code
            // lookup (null when Zoom named the queue but gave no id, in which
            // case the card simply has no Pick up button); `queue_name` is the
            // badge text.
            'queue_id' => $queueId,
            // A direct call has no queue, and the badge still has to say
            // something — "Direct" is what distinguishes it at a glance from
            // the queue cards beside it.
            'queue_name' => $direct
                ? 'Direct'
                : (string) ($this->queue_name ?: 'Call Queue'),
            'caller_number' => (string) ($this->caller_number ?? ''),
            'caller_name' => (string) ($this->caller_name ?? ''),
            'client' => is_array($this->client_preview)
                ? $this->client_preview
                : null,
            'started_at' => optional($this->started_at)->toIso8601String()
                ?? now()->toIso8601String(),

            // 'queue' | 'direct'. The card renders the two differently.
            'kind' => $direct ? 'direct' : 'queue',

            // Whose phone is ringing. Only meaningful for a direct call: Zoom
            // names a queue member's extension here too, but the subject of a
            // queue pop is the queue, not whichever handset the routing
            // happened to reach first.
            'callee_name' => $direct ? (string) ($this->callee_name ?? '') : '',
            'callee_extension' => $direct
                ? (string) ($this->callee_extension_number ?? '')
                : '',

            // "Transferred by Steve", when Zoom said who handed it on.
            'forwarded_by_name' => (string) ($this->forwarded_by_name ?? ''),

            // What the pickup-code map is keyed by. A queue call is keyed by its
            // queue id; every direct call shares the one pseudo-queue key
            // 'direct', because there is one account-wide code for grabbing a
            // call ringing a person rather than a queue. Null when there is
            // nothing to key on, in which case the card has no Pick up button.
            'pickup_key' => $direct ? 'direct' : $queueId,
        ];
    }

    /** Ringing somebody's own extension rather than a call queue. */
    public function isDirect(): bool
    {
        return (string) ($this->kind ?? 'queue') === 'direct';
    }
}
