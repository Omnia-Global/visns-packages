<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Events\CallQueueAnswered;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Receiver for Zoom Phone event subscriptions.
 *
 * Only calls ringing in a call queue are of interest — any queue in the account
 * counts, bar the ones excluded in Settings -> Call Queues; every other
 * extension's traffic is acknowledged and dropped. Zoom retries and eventually
 * disables endpoints that error or answer slowly, so this controller always
 * returns 200 and never lets an exception escape.
 *
 * Signature verification lives in the `zoom_webhook` middleware.
 */
class ZoomWebhookController extends \App\Http\Controllers\Controller
{
    /** Extension types Zoom uses for a call queue, across payload shapes. */
    private const QUEUE_EXTENSION_TYPES = [
        'callqueue',
        'call_queue',
    ];

    /** Events that mean "a call started ringing somewhere". */
    private const RINGING_EVENTS = [
        'phone.callee_ringing',
    ];

    /** Events that mean "the call was picked up". */
    private const ANSWERED_EVENTS = [
        'phone.callee_answered',
    ];

    /** Events that mean "the call is over / nobody is ringing any more". */
    private const ENDED_EVENTS = [
        'phone.callee_ended',
        'phone.caller_ended',
        'phone.callee_missed',
    ];

    public function handle(Request $request)
    {
        $body = $request->json()->all();

        if (! is_array($body)) {
            $body = [];
        }

        $event = (string) Arr::get($body, 'event', '');

        try {
            // Zoom's endpoint ownership challenge, sent on save and periodically
            // thereafter. Must be answered inline, not queued.
            if ($event === 'endpoint.url_validation') {
                return $this->urlValidation($body);
            }

            if (in_array($event, self::RINGING_EVENTS, true)) {
                return $this->handleRinging($event, $body);
            }

            if (in_array($event, self::ANSWERED_EVENTS, true)) {
                return $this->handleClosingEvent($event, $body, 'answered');
            }

            if (in_array($event, self::ENDED_EVENTS, true)) {
                return $this->handleClosingEvent($event, $body, 'ended');
            }

            $this->logUnhandled($event, $body, 'event not handled');
        } catch (\Throwable $e) {
            // A malformed payload must never 500 back to Zoom — that gets the
            // subscription disabled.
            Log::error('zoom.webhook failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Answer Zoom's URL validation challenge.
     */
    private function urlValidation(array $body)
    {
        $plainToken = (string) Arr::get($body, 'payload.plainToken', '');
        $secret = (string) ModuleConfig::get('call_queue.webhook_secret_token');

        return response()->json([
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, $secret),
        ]);
    }

    /**
     * A call is ringing. Record + broadcast it only when it is ringing in a
     * call queue.
     */
    private function handleRinging(string $event, array $body)
    {
        $object = (array) Arr::get($body, 'payload.object', []);
        $match = $this->resolveQueue($object);

        if ($match['excluded']) {
            // A queue the operator deliberately opted out of. Silent by design:
            // it is not a payload we failed to understand.
            return response()->json(['status' => 'ignored']);
        }

        $queue = $match['queue'];

        if ($queue === null) {
            // Ringing on somebody's own extension. Nothing to do — but log the
            // routing shape (extension types/ids only, no caller identity) so
            // the matcher can be tuned from real events without putting PII
            // into the log. Debug level: invaluable when first pointing Zoom at
            // this endpoint, noise once the subscription is tuned.
            $routing = static fn ($node) => is_array($node)
                ? Arr::only($node, [
                    'extension_type',
                    'extension_id',
                    'id',
                    'name',
                ])
                : null;

            Log::debug('zoom.webhook ringing unmatched', [
                'call_id' => $this->callId($object),
                'callee' => $routing(Arr::get($object, 'callee')),
                'forwarded_by' => $routing(Arr::get($object, 'forwarded_by')),
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $callId = $this->callId($object);

        if ($callId === '') {
            $this->logUnhandled($event, $body, 'ringing event without a call_id');

            return response()->json(['status' => 'ignored']);
        }

        $callerNumber = $this->trim(Arr::get($object, 'caller.phone_number'));

        $call = ZoomLiveQueueCall::updateOrCreate(
            ['call_id' => $callId],
            [
                'queue_id' => $queue['id'],
                'queue_name' => $queue['name'],
                'caller_number' => $callerNumber,
                'caller_name' => $this->trim(Arr::get($object, 'caller.name')),
                'status' => 'ringing',
                'started_at' => $this->startedAt($object),
                'client_preview' => $this->clientPreview($callerNumber),
                'raw_payload' => $object,
            ]
        );

        $ringing = $this->eventClass('ringing', CallQueueRinging::class);

        event(new $ringing($call));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Which class to dispatch for one of the three call-queue events.
     *
     * Configurable because Laravel's Event::fake() keys listeners by EXACT
     * class name - a subclass or a class_alias of the package event is a
     * different key entirely - so an application whose listeners and tests are
     * written against its own App\Events\CallQueue* classes can only be reached
     * by dispatching those classes themselves.
     *
     * A name that does not resolve falls back to the package's own event rather
     * than throwing: a typo in config should cost the pop its custom listener,
     * not stop the webhook recording calls at all.
     *
     * @param  class-string  $default
     * @return class-string
     */
    private function eventClass(string $key, string $default): string
    {
        $configured = ModuleConfig::get('call_queue.events.' . $key);

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            return $configured;
        }

        if (is_string($configured) && $configured !== '') {
            Log::warning('zoom.webhook configured event class does not exist', [
                'event' => $key,
                'class' => $configured,
            ]);
        }

        return $default;
    }

    /**
     * The client snapshot for a caller, resolved here rather than in each of the
     * ~17 watching browsers. Enrichment is a nicety — a failed lookup must never
     * cost the user the pop itself, so anything thrown becomes a null preview.
     *
     * The hook is the configured `call_queue.caller_enrichment`; unset means the
     * pop simply carries no client block.
     */
    private function clientPreview(string $callerNumber): ?array
    {
        $enrichment = ModuleConfig::callable('call_queue.caller_enrichment');

        if ($enrichment === null) {
            return null;
        }

        try {
            $preview = $enrichment($callerNumber);

            return is_array($preview) ? $preview : null;
        } catch (\Throwable $e) {
            Log::warning('zoom.webhook client preview failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A call left the ringing state: drop the row and tell the browsers.
     *
     * `$kind` picks the broadcast — 'answered' when somebody picked it up,
     * 'ended' for hangups / misses.
     */
    private function handleClosingEvent(string $event, array $body, string $kind)
    {
        $object = (array) Arr::get($body, 'payload.object', []);
        $callId = $this->callId($object);

        if ($callId === '') {
            $this->logUnhandled($event, $body, 'closing event without a call_id');

            return response()->json(['status' => 'ignored']);
        }

        $call = ZoomLiveQueueCall::where('call_id', $callId)->first();

        if ($call === null) {
            // Not one of ours (or already cleared) — stay quiet, a stray
            // broadcast would only make other tabs flicker.
            return response()->json(['status' => 'ignored']);
        }

        $call->delete();

        $class = $kind === 'answered'
            ? $this->eventClass('answered', CallQueueAnswered::class)
            : $this->eventClass('ended', CallQueueEnded::class);

        event(new $class($callId));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Which call queue (if any) this call is ringing for.
     *
     * Every queue in the Zoom account pops, so any callee/forwarded_by node
     * whose `extension_type` says "call queue" is a match — no registry to keep
     * in step with Zoom admin. Zoom's queue events are inconsistently shaped:
     * sometimes the callee IS the call queue, sometimes the callee is the
     * member's extension and the queue only appears under `forwarded_by`. Both
     * are accepted, first one wins.
     *
     * @return array{queue: ?array{id: ?string, name: string}, excluded: bool}
     *         `excluded` is true when the only queue found is opted out in
     *         Settings -> Call Queues — the caller drops it silently rather
     *         than logging it as an unrecognised payload.
     */
    private function resolveQueue(array $object): array
    {
        $excluded = false;

        foreach (['callee', 'forwarded_by'] as $key) {
            $node = Arr::get($object, $key);

            if (! is_array($node) || ! $this->isCallQueueNode($node)) {
                continue;
            }

            $id = $this->trim(Arr::get($node, 'extension_id'))
                ?: $this->trim(Arr::get($node, 'id'));

            if ($id !== '' && $this->isExcludedQueue($id)) {
                // Keep looking: a call forwarded through an excluded queue may
                // still be ringing on one we do want.
                $excluded = true;

                continue;
            }

            return [
                'queue' => [
                    'id' => $id === '' ? null : $id,
                    'name' => $this->trim(Arr::get($node, 'name'))
                        ?: 'Call Queue',
                ],
                'excluded' => false,
            ];
        }

        return ['queue' => null, 'excluded' => $excluded];
    }

    private function isCallQueueNode(array $node): bool
    {
        $type = strtolower($this->trim(Arr::get($node, 'extension_type')));

        return in_array($type, self::QUEUE_EXTENSION_TYPES, true);
    }

    /**
     * Has the operator opted this queue id out of popping entirely?
     *
     * Maintained in Settings -> Call Queues; the model caches the list, because
     * this runs on every ringing webhook.
     */
    private function isExcludedQueue(string $id): bool
    {
        $excluded = array_map(
            fn($value) => strtolower($this->trim($value)),
            ZoomCallQueueSetting::excludedIds()
        );

        return in_array(strtolower($id), array_filter($excluded), true);
    }

    private function callId(array $object): string
    {
        return $this->trim(
            Arr::get($object, 'call_id') ?? Arr::get($object, 'callId')
        );
    }

    /**
     * When the phone started ringing. Zoom's timestamp is preferred; a missing
     * or unparseable one falls back to now so the pop timer still works.
     *
     * Zoom sends UTC ("...Z"), but datetime columns are stored and re-read as
     * app-timezone wall clock — without the conversion the round trip would
     * shift the instant by the UTC offset and the pop's timer would be wrong.
     */
    private function startedAt(array $object): Carbon
    {
        $raw =
            Arr::get($object, 'ringing_start_time') ??
            Arr::get($object, 'ringing_time') ??
            Arr::get($object, 'date_time');

        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse($raw)->setTimezone(
                    config('app.timezone')
                );
            } catch (\Throwable $e) {
                // Fall through to now().
            }
        }

        return Carbon::now();
    }

    /**
     * Log an event we did nothing with. Doubles as the payload-shape survey when
     * Zoom is first pointed at this endpoint — read these to learn what the real
     * queue events look like before tightening the matching above.
     */
    private function logUnhandled(string $event, array $body, string $reason): void
    {
        Log::info('zoom.webhook unhandled', [
            'event' => $event === '' ? '(none)' : $event,
            'reason' => $reason,
            'object' => Arr::only((array) Arr::get($body, 'payload.object', []), [
                'call_id',
                'caller',
                'callee',
                'forwarded_by',
                'direction',
                'call_type',
            ]),
        ]);
    }

    private function trim($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
