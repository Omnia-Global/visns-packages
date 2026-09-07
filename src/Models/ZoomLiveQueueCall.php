<?php

namespace Visnsstudio\VisnsPackages\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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
        // legKey => ['state' => 'ringing'|'missed'|'ended', 'at' => ISO-8601].
        // See the "Legs" block below for why a call needs a map at all.
        'legs' => 'array',
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

    /*
    |--------------------------------------------------------------------------
    | Legs
    |--------------------------------------------------------------------------
    |
    | Zoom sends `phone.callee_ringing` and `phone.callee_ended` PER LEG on one
    | `call_id`. A queue rings every member; each member's extension rings a
    | desk phone AND the Zoom app, so production sees two ringing events per
    | extension per call. One `phone.callee_ended` used to delete the row and
    | close the pop on every screen while the other handsets were still ringing
    | — on one live call, a moment before somebody actually answered it.
    |
    | The rule the office asked for is "if it stops ringing, it should close for
    | everyone", so what the controller needs to know is how many legs are still
    | ringing, not which one stopped. Hence a map rather than a counter column:
    | the key is a best effort at identifying a leg, and the COUNT is what the
    | decision is made on.
    |
    | Zoom does not tell us which of an extension's devices stopped. It names a
    | `device_id` on the callee node only sometimes; without one, an extension's
    | two legs derive the SAME key and would collapse into one entry, which is
    | the original bug all over again. So a ringing event whose key is already
    | ringing is appended as `{key}#2`, `{key}#3` … and a settling event for
    | `{key}` settles the first of its siblings that is still ringing. The keys
    | only do real work when Zoom does say which device; the rest of the time
    | they are a tally that happens to be readable.
    |
    | These methods are deliberately pure model state — no request, no config —
    | so the leg arithmetic can be tested without an HTTP round trip.
    */

    /** A leg Zoom is still ringing. */
    public const LEG_RINGING = 'ringing';

    /** A leg that timed out or was declined (`phone.callee_missed`). */
    public const LEG_MISSED = 'missed';

    /** A leg that hung up / stopped ringing (`phone.callee_ended`). */
    public const LEG_ENDED = 'ended';

    /**
     * The key one leg of this call is filed under.
     *
     * `callee.device_id` when Zoom names it — a desk phone and the same
     * person's mobile app are two device ids on one extension, and that is the
     * only field that tells them apart. Failing that the extension, and failing
     * even that a positional `leg-N`, because an unidentifiable leg still has
     * to be counted.
     *
     * @param  array  $callee  Zoom's `payload.object.callee` node, or [].
     */
    public function legKeyFor(array $callee): string
    {
        return $this->calleeIdentity($callee)
            ?? 'leg-' . (count($this->legsMap()) + 1);
    }

    /**
     * Fold one `phone.callee_ringing` leg into the map.
     *
     * A key that is already RINGING means a second device of the same extension
     * rang and Zoom did not distinguish them, so it is appended as a sibling
     * rather than overwriting — losing the count here is what let a single
     * ended event close a call that was ringing two handsets. A key in a
     * settled state is reused: Zoom's queue overflow re-offers the same
     * `call_id` to a member who already declined it, and that is the same leg
     * ringing again, not a new one.
     */
    public function recordRingingLeg(array $callee): void
    {
        $legs = $this->legsMap();
        $key = $this->legKeyFor($callee);

        if (($legs[$key]['state'] ?? null) === self::LEG_RINGING) {
            $suffix = 2;

            while (($legs[$key . '#' . $suffix]['state'] ?? null) === self::LEG_RINGING) {
                $suffix++;
            }

            $key .= '#' . $suffix;
        }

        $legs[$key] = [
            'state' => self::LEG_RINGING,
            'at' => Carbon::now()->toIso8601String(),
        ];

        $this->legs = $legs;
    }

    /**
     * Mark one leg as no longer ringing.
     *
     * `$state` is LEG_ENDED or LEG_MISSED. When Zoom names the leg, its own
     * entry (or the first of its `#2`, `#3` … siblings that is still ringing)
     * is settled; when Zoom names nothing — closing payloads often carry only a
     * `call_id` — the first still-ringing leg is settled instead, because the
     * count is the thing that matters.
     *
     * @return bool  Whether a ringing leg was found and settled. False for a
     *               row with no legs recorded, and for the duplicate delivery
     *               of an event Zoom already sent (it must not settle a
     *               second, innocent leg).
     */
    public function settleLeg(array $callee, string $state): bool
    {
        $legs = $this->legsMap();

        if ($legs === []) {
            return false;
        }

        $identity = $this->calleeIdentity($callee);

        foreach ($legs as $key => $leg) {
            if (($leg['state'] ?? null) !== self::LEG_RINGING) {
                continue;
            }

            // Insertion order is `key`, `key#2`, `key#3` …, so the first match
            // here IS the first still-ringing sibling.
            if ($identity !== null && ! $this->isSiblingKey((string) $key, $identity)) {
                continue;
            }

            $legs[$key] = [
                'state' => $state,
                'at' => Carbon::now()->toIso8601String(),
            ];

            $this->legs = $legs;

            return true;
        }

        return false;
    }

    /**
     * How many legs of this call Zoom is still ringing.
     *
     * Zero is the whole decision: the pop closes for everyone the moment
     * nothing is ringing any more, and not before.
     */
    public function ringingLegCount(): int
    {
        return count(array_filter(
            $this->legsMap(),
            static fn($leg) => is_array($leg)
                && ($leg['state'] ?? null) === self::LEG_RINGING
        ));
    }

    /**
     * Whether any leg was ever recorded on this row.
     *
     * False for a row written by the release before legs existed, which is why
     * the controller still closes those on the first ended event: no legs is
     * "we do not know", not "nothing is ringing".
     */
    public function hasRecordedLegs(): bool
    {
        return $this->legsMap() !== [];
    }

    /** The legs map, defensively — the column is nullable and JSON. */
    private function legsMap(): array
    {
        return is_array($this->legs) ? $this->legs : [];
    }

    /**
     * Whichever field Zoom used to say which leg this is, or null when it said
     * nothing. Read in the same Arr::get style as the controller's
     * resolveDirect() and the ledger's routingMeta().
     */
    private function calleeIdentity(array $callee): ?string
    {
        foreach (['device_id', 'extension_id', 'id', 'user_id'] as $field) {
            $value = Arr::get($callee, $field);

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                // Capped so a surprise from Zoom cannot bloat the JSON column.
                return substr($value, 0, 120);
            }
        }

        return null;
    }

    /** Is `$key` this identity's own entry, or one of its `#N` siblings? */
    private function isSiblingKey(string $key, string $identity): bool
    {
        return $key === $identity || str_starts_with($key, $identity . '#');
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
     * And, whatever the misses say, a row that has not been rung in
     * `max_ringing_seconds` is not live. Zoom does not guarantee a closing
     * event for every call and a dropped `phone.callee_ended` would otherwise
     * leave a phantom card ringing on every screen until the far longer
     * `stale_after_minutes` sweep noticed. A row with NO `last_ringing_at` is
     * left alone: it predates the column, and the stale sweep owns it.
     *
     * The single definition of "live": anything needing the same answer asks
     * here rather than re-deriving it.
     */
    public function scopeLive(Builder $query): Builder
    {
        $cutoff = self::missedCutoff();
        $ringingCutoff = self::ringingCutoff();

        return $query
            ->where('status', 'ringing')
            ->where(function (Builder $q) use ($ringingCutoff) {
                $q->whereNull('last_ringing_at')
                    ->orWhere('last_ringing_at', '>', $ringingCutoff);
            })
            ->where(function (Builder $q) use ($cutoff) {
                $q->whereNull('last_missed_at')
                    ->orWhereColumn('last_ringing_at', '>', 'last_missed_at')
                    ->orWhere('last_missed_at', '>', $cutoff);
            });
    }

    /**
     * The mirror image of the stale-ring clause above: rows nothing has rung in
     * `max_ringing_seconds`, whose closing event Zoom evidently never sent.
     *
     * Deleted opportunistically by the snapshot endpoint alongside
     * deadAfterMiss(), so a phantom row does not sit in the table for the whole
     * `stale_after_minutes` window merely because scopeLive() stopped showing
     * it.
     */
    public function scopeStaleRinging(Builder $query): Builder
    {
        return $query
            ->whereNotNull('last_ringing_at')
            ->where('last_ringing_at', '<=', self::ringingCutoff());
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
     * How long ago a miss stops keeping a card on screen.
     */
    public static function missedCutoff(): Carbon
    {
        $grace = (int) ModuleConfig::get('call_queue.missed_grace_seconds', 20);

        return Carbon::now()->subSeconds(max(0, $grace));
    }

    /**
     * How long ago the last ringing event stops keeping a card on screen.
     *
     * The safety net for a closing event Zoom never sent — see scopeLive(). Two
     * minutes is comfortably longer than any queue's ring timeout, so it only
     * ever catches calls whose end was lost.
     */
    public static function ringingCutoff(): Carbon
    {
        $window = (int) ModuleConfig::get('call_queue.max_ringing_seconds', 120);

        return Carbon::now()->subSeconds(max(1, $window));
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
