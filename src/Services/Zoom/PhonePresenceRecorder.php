<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Events\PhonePresenceUpdated;
use Visnsstudio\VisnsPackages\Models\ZoomPhoneLiveCall;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Who is on the phone right now, built from the webhooks the CRM already gets.
 *
 * Zoom Phone has no "is this extension busy" endpoint. `/users/{id}/
 * presence_status` reports Zoom MEETINGS presence (available/in a meeting/DND),
 * not call state, and needs a scope this tenant's app does not carry — so the
 * live half of the roster is derived from the same signed event stream the call
 * queue pop is already subscribed to, and only the roster itself (names and
 * extensions, which change a few times a year) comes from the REST API.
 *
 * The difference from the call queue's own recorder is what a row means. There,
 * a row is "ringing in a queue" and dies on pickup. Here a row is "this
 * extension is on a call", so it is created on the ringing/placed event and
 * lives until the call ends.
 *
 * Everything is best effort and nothing throws out of `record()`: this runs
 * inline on Zoom's webhook thread, and a failure here must never cost the call
 * queue its pop or hand Zoom a non-200 (which gets the subscription disabled).
 */
class PhonePresenceRecorder
{
    /**
     * The event -> (side, transition) map.
     *
     * "side" is which node of the payload is OUR extension: a `callee_*` event
     * describes the phone being rung, a `caller_*` event the phone doing the
     * ringing. The other node is the peer.
     */
    private const EVENTS = [
        'phone.callee_ringing' => ['callee', 'ringing'],
        'phone.caller_ringing' => ['caller', 'ringing'],
        'phone.callee_answered' => ['callee', 'active'],
        'phone.caller_connected' => ['caller', 'active'],
        'phone.callee_ended' => ['callee', 'ended'],
        'phone.caller_ended' => ['caller', 'ended'],
        // A single member declining a queue call. Only that member's leg goes;
        // the queue may still be ringing four other handsets on the same
        // call_id, which is why this is not treated as "the call ended".
        'phone.callee_missed' => ['callee', 'missed'],
    ];

    /** Extension types that are a person's handset rather than a routing object. */
    private const DEFAULT_EXTENSION_TYPES = ['user', 'commonarea', 'common_area'];

    /** Every event this service wants to see, for the webhook's dispatcher. */
    public static function events(): array
    {
        return array_keys(self::EVENTS);
    }

    public function enabled(): bool
    {
        return (bool) ModuleConfig::get('call_queue.presence.enabled', false);
    }

    /**
     * Fold one webhook event into the live-call table.
     *
     * @param  array  $object  `payload.object` from the delivery.
     * @return string  A status word, for the webhook's response and its tests.
     */
    public function record(string $event, array $object): string
    {
        if (! $this->enabled()) {
            return 'presence-disabled';
        }

        try {
            return $this->apply($event, $object);
        } catch (\Throwable $e) {
            Log::warning('zoom.presence record failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return 'presence-failed';
        }
    }

    private function apply(string $event, array $object): string
    {
        [$side, $transition] = self::EVENTS[$event] ?? [null, null];

        if ($side === null) {
            return 'presence-ignored';
        }

        $callId = $this->callId($object);

        if ($callId === '') {
            return 'presence-ignored';
        }

        // A call ending ends both of its legs, and Zoom sends the closing event
        // from whichever side hung up — so one event clears the lot. `missed`
        // is the exception (see the note on the event map).
        if ($transition === 'ended') {
            return $this->clearCall($callId);
        }

        $node = Arr::get($object, $side);

        if (! is_array($node) || ! $this->isTrackedExtension($node)) {
            // A queue, an auto-receptionist, or an outside number. Nothing to
            // show on a roster of staff handsets.
            return 'presence-ignored';
        }

        $legKey = $this->legKey($callId, $node, $side);

        if ($transition === 'missed') {
            return $this->clearLeg($legKey);
        }

        $peer = Arr::get($object, $side === 'callee' ? 'caller' : 'callee');
        $peer = is_array($peer) ? $peer : [];

        $existing = ZoomPhoneLiveCall::where('leg_key', $legKey)->first();

        $peerNumber = $this->trim(Arr::get($peer, 'phone_number'));

        $attributes = [
            'call_id' => $callId,
            'zoom_user_id' => $this->nullable(Arr::get($node, 'user_id')),
            'extension_id' => $this->nullable(Arr::get($node, 'extension_id')),
            'extension_number' => $this->nullable(Arr::get($node, 'extension_number')),
            'user_name' => $this->nullable(Arr::get($node, 'name')),
            'direction' => $side === 'callee' ? 'inbound' : 'outbound',
            'status' => $transition,
            'peer_number' => $peerNumber === '' ? null : $peerNumber,
            'peer_name' => $this->nullable(Arr::get($peer, 'name')),
            'queue_name' => $this->queueName($object),
            'raw_payload' => $object,
        ];

        /*
        | Webhooks arrive out of order often enough to matter, and a connected
        | call that flips back to "ringing" because a delayed ringing delivery
        | landed after the answer is a roster telling the office something that
        | is not true. Answered is therefore one-way for the life of the leg,
        | and the answer timestamp is written once — a Zoom retry of the same
        | event must not restart the duration everyone is reading.
        */
        if ($existing !== null && $existing->status === 'active') {
            $attributes['status'] = 'active';
        }

        if ($transition === 'active' && $existing?->answered_at === null) {
            $attributes['answered_at'] = Carbon::now();
        }

        // The enrichment hook is the expensive part (it queries the CRM), so it
        // runs once per leg: on the event that created the row, and again only
        // if that first attempt produced nothing and the number has changed.
        if ($existing === null || $existing->client_preview === null) {
            $attributes['client_preview'] = $this->clientPreview($peerNumber);
        }

        if ($existing === null) {
            $attributes['started_at'] = $this->startedAt($object);
        }

        $call = ZoomPhoneLiveCall::updateOrCreate(['leg_key' => $legKey], $attributes);

        $this->broadcast($call, false);

        return 'presence-' . $transition;
    }

    /**
     * Drop every leg of a call and tell the browsers about each one.
     */
    private function clearCall(string $callId): string
    {
        $calls = ZoomPhoneLiveCall::where('call_id', $callId)->get();

        if ($calls->isEmpty()) {
            // Not one of ours, or already cleared. Stay quiet: a stray
            // broadcast only makes other tabs flicker.
            return 'presence-ignored';
        }

        foreach ($calls as $call) {
            $call->delete();
            $this->broadcast($call, true);
        }

        return 'presence-ended';
    }

    private function clearLeg(string $legKey): string
    {
        $call = ZoomPhoneLiveCall::where('leg_key', $legKey)->first();

        if ($call === null) {
            return 'presence-ignored';
        }

        $call->delete();
        $this->broadcast($call, true);

        return 'presence-ended';
    }

    /**
     * Tell every watching browser that one extension changed.
     *
     * The event class is configurable for the same reason the three call queue
     * ones are — an application whose listeners are written against its own
     * class can only be reached by dispatching that class.
     */
    private function broadcast(ZoomPhoneLiveCall $call, bool $cleared): void
    {
        $class = PhonePresenceUpdated::class;
        $configured = ModuleConfig::get('call_queue.events.presence');

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            $class = $configured;
        }

        event(new $class($call, $cleared));
    }

    /**
     * The identity of one leg.
     *
     * Zoom reuses a single `call_id` across every handset a queue call is fanned
     * out to, so the call id alone would let five ringing phones overwrite each
     * other into one row. The extension identifier disambiguates them; the side
     * is the last resort, and covers the internal-to-internal case where both
     * legs of one call belong to us.
     */
    private function legKey(string $callId, array $node, string $side): string
    {
        $identity = $this->trim(Arr::get($node, 'user_id'))
            ?: $this->trim(Arr::get($node, 'extension_id'))
            ?: $this->trim(Arr::get($node, 'extension_number'))
            ?: $side;

        $key = $callId . '|' . $identity;

        // The column is 191 characters; a pathological id must truncate to
        // something still unique rather than blow up the insert.
        return strlen($key) <= 191 ? $key : substr($key, 0, 151) . '|' . sha1($key);
    }

    /**
     * Is this node a staff handset?
     *
     * Call queues, auto receptionists and outside numbers are all "not a person
     * on the roster". The list is configurable because Zoom has added extension
     * types before and will again.
     */
    private function isTrackedExtension(array $node): bool
    {
        $types = (array) ModuleConfig::get(
            'call_queue.presence.extension_types',
            self::DEFAULT_EXTENSION_TYPES
        );

        $types = array_map(
            fn ($type) => str_replace('_', '', strtolower($this->trim($type))),
            $types
        );

        $type = str_replace('_', '', strtolower($this->trim(Arr::get($node, 'extension_type'))));

        if ($type === '') {
            // Zoom omits the type on some payload shapes. An extension number
            // is the next best proof that this is an internal handset rather
            // than an outside caller.
            return $this->trim(Arr::get($node, 'extension_number')) !== '';
        }

        return in_array($type, array_filter($types), true);
    }

    /**
     * The queue a call arrived through, when it arrived through one.
     *
     * Same two nodes the call queue matcher reads, and the same inconsistency:
     * sometimes the callee IS the queue, sometimes the queue only shows up under
     * `forwarded_by`.
     */
    private function queueName(array $object): ?string
    {
        foreach (['forwarded_by', 'callee'] as $key) {
            $node = Arr::get($object, $key);

            if (! is_array($node)) {
                continue;
            }

            $type = str_replace('_', '', strtolower($this->trim(Arr::get($node, 'extension_type'))));

            if ($type === 'callqueue') {
                return $this->nullable(Arr::get($node, 'name')) ?? 'Call Queue';
            }
        }

        return null;
    }

    /**
     * The peer -> client match. A hook that throws costs the row its client
     * block, never the row.
     */
    private function clientPreview(string $peerNumber): ?array
    {
        if ($peerNumber === '') {
            return null;
        }

        $enrichment = ModuleConfig::callable('call_queue.caller_enrichment');

        if ($enrichment === null) {
            return null;
        }

        try {
            $preview = $enrichment($peerNumber);

            return is_array($preview) ? $preview : null;
        } catch (\Throwable $e) {
            Log::warning('zoom.presence client preview failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * When this leg started. Zoom sends UTC; datetime columns round-trip as
     * app-timezone wall clock, so without the conversion every duration on the
     * roster would be out by the UTC offset.
     */
    private function startedAt(array $object): Carbon
    {
        $raw = Arr::get($object, 'ringing_start_time')
            ?? Arr::get($object, 'ringing_time')
            ?? Arr::get($object, 'answer_start_time')
            ?? Arr::get($object, 'date_time');

        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse($raw)->setTimezone(config('app.timezone'));
            } catch (\Throwable $e) {
                // Fall through to now().
            }
        }

        return Carbon::now();
    }

    private function callId(array $object): string
    {
        return $this->trim(
            Arr::get($object, 'call_id') ?? Arr::get($object, 'callId')
        );
    }

    private function nullable($value): ?string
    {
        $value = $this->trim($value);

        return $value === '' ? null : $value;
    }

    private function trim($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
