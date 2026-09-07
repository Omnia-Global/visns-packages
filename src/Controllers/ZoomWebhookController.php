<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Events\CallQueueAnswered;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueMissed;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Services\Sms\SmsWebhookHandler;
use Visnsstudio\VisnsPackages\Services\Zoom\PhonePresenceRecorder;
use Visnsstudio\VisnsPackages\Services\Zoom\WebhookLedger;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Receiver for Zoom Phone event subscriptions.
 *
 * Two kinds of ringing call are of interest. A QUEUE call — any queue in the
 * account counts, bar the ones excluded in Settings -> Call Queues. And a
 * DIRECT call, ringing a staff member's own extension or a common-area handset:
 * a direct dial, an internal call, or a transfer. Everything else (auto
 * receptionists, shared line groups, outbound legs) is acknowledged and
 * dropped. Zoom retries and eventually disables endpoints that error or answer
 * slowly, so this controller always returns 200 and never lets an exception
 * escape.
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

    /**
     * Extension types that are a person's phone rather than a routing object.
     *
     * The default; `call_queue.direct_calls.extension_types` overrides it.
     * Zoom spells common-area handsets both ways depending on the endpoint,
     * hence both.
     */
    private const DIRECT_EXTENSION_TYPES = [
        'user',
        'commonarea',
        'common_area',
    ];

    /** Events that mean "a call started ringing somewhere". */
    private const RINGING_EVENTS = [
        'phone.callee_ringing',
    ];

    /** Events that mean "the call was picked up". */
    private const ANSWERED_EVENTS = [
        'phone.callee_answered',
    ];

    /**
     * Events that mean "the CALLER hung up" — the whole call is over.
     *
     * Nobody's phone is ringing after this, whatever the legs say, so it closes
     * the pop straight away.
     */
    private const CALLER_ENDED_EVENTS = [
        'phone.caller_ended',
    ];

    /**
     * Events that mean "ONE leg stopped ringing".
     *
     * `phone.callee_ended` is per LEG, exactly as `phone.callee_missed` is. A
     * queue rings every member, and each member's extension rings a desk phone
     * AND the Zoom app, so a single call routinely produces four, six, ten of
     * these. Treating the first one as "the call is over" — which is what
     * sharing an ENDED_EVENTS list with `phone.caller_ended` did — closed the
     * pop on every screen while the other handsets rang on; on one production
     * call it beat the answer by a moment.
     *
     * So it settles a leg and closes only once NO leg is ringing any more,
     * which is the rule the office asked for: "if it stops ringing, it should
     * close for everyone." See handleEndedLeg().
     */
    private const CALLEE_ENDED_EVENTS = [
        'phone.callee_ended',
    ];

    /**
     * Events that mean "ONE leg stopped ringing" — declined, or timed out.
     *
     * `phone.callee_missed` used to sit in the ended list, which was wrong in
     * both directions. Zoom sends it PER LEG: a queue rings four handsets on
     * one call_id, so the first person to wave the call away closed everybody
     * else's pop while their phones were still ringing; and a direct call
     * arrives on two legs (desk phone and mobile app), so it did it to itself
     * the moment the quicker of the two gave up.
     *
     * So a miss is recorded, not acted on: the row is stamped, the leg is
     * settled, and ZoomLiveQueueCall::scopeLive() decides whether the call is
     * still ringing anywhere. Deliberately NOT closed the moment every leg has
     * missed — Zoom's queue overflow re-rings the same call_id a second later,
     * and the grace window is what stops the card flickering off and back on.
     */
    private const MISSED_EVENTS = [
        'phone.callee_missed',
    ];

    /**
     * SMS events, handled by the messaging module.
     *
     * They arrive here rather than on an endpoint of their own because Zoom
     * subscribes ONE URL per marketplace app: the call queue events and the SMS
     * events are delivered to the same place, already carrying the same
     * signature. Splitting them would have meant a second app, a second secret
     * and a second thing to keep enabled.
     *
     * The work is delegated to SmsWebhookHandler; this controller stays a
     * dispatcher.
     */
    private const SMS_EVENTS = [
        'phone.sms_received',
        'phone.sms_sent',
        'phone.sms_sent_failed',
    ];

    public function handle(Request $request)
    {
        $body = $request->json()->all();

        if (! is_array($body)) {
            $body = [];
        }

        $event = (string) Arr::get($body, 'event', '');

        /*
        | The durable record of this delivery.
        |
        | Opened before anything can go wrong and written in the finally below,
        | so every delivery leaves a row — including the ones that threw. It is
        | the only way to tell a pop that never happened because Zoom never sent
        | the event from one that never happened because the broadcast failed;
        | both look identical from a browser, and both answer Zoom 200.
        */
        $ledger = WebhookLedger::open(
            $event,
            (array) Arr::get($body, 'payload.object', [])
        );

        try {
            // Zoom's endpoint ownership challenge, sent on save and periodically
            // thereafter. Must be answered inline, not queued.
            if ($event === 'endpoint.url_validation') {
                $ledger->outcome('url_validation');

                return $this->urlValidation($body);
            }

            /*
            | The Zoom Phone roster ("who is on a call right now") is folded in
            | FIRST, before the queue logic decides whether this call is one it
            | cares about.
            |
            | The two features read the same events and disagree about almost
            | everything else. The queue pop wants calls ringing in a QUEUE and
            | drops the row the moment somebody answers; the roster wants every
            | staff extension, including direct dials and outbound calls, and
            | keeps its row until the call ends. Running the recorder here — on
            | its own, never inside handleRinging() — is what stops the queue's
            | "not a queue call, ignore it" early return from also throwing away
            | the roster's most common event.
            |
            | It never changes the response: the recorder swallows its own
            | failures, and this endpoint's contract with Zoom is 200 and the
            | queue's status word.
            */
            $this->recordPresence($event, $body);

            if (in_array($event, self::RINGING_EVENTS, true)) {
                return $this->handleRinging($event, $body, $ledger);
            }

            if (in_array($event, self::ANSWERED_EVENTS, true)) {
                return $this->handleClosingEvent($event, $body, 'answered', $ledger);
            }

            if (in_array($event, self::CALLER_ENDED_EVENTS, true)) {
                return $this->handleClosingEvent($event, $body, 'ended', $ledger);
            }

            if (in_array($event, self::CALLEE_ENDED_EVENTS, true)) {
                return $this->handleEndedLeg($event, $body, $ledger);
            }

            if (in_array($event, self::MISSED_EVENTS, true)) {
                return $this->handleMissedLeg($event, $body, $ledger);
            }

            if (in_array($event, self::SMS_EVENTS, true)) {
                $ledger->outcome('sms');

                return $this->handleSms($event, $body);
            }

            // A presence-only event (an outbound leg, say) is handled, not
            // unhandled — logging it as a mystery would bury the real ones.
            if (in_array($event, PhonePresenceRecorder::events(), true)) {
                $ledger->outcome('presence_only');

                return response()->json(['status' => 'ok']);
            }

            $ledger->outcome('unhandled');

            $this->logUnhandled($event, $body, 'event not handled');
        } catch (\Throwable $e) {
            // A malformed payload must never 500 back to Zoom — that gets the
            // subscription disabled.
            $ledger->failed($e);

            Log::error('zoom.webhook failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // One insert per delivery, whichever way the request left. The
            // ledger swallows its own failures, so this cannot become the
            // reason a webhook 500s.
            $ledger->write();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Fold a call event into the Zoom Phone roster.
     *
     * Silent when the presence feature is switched off, and silent when it
     * fails: the recorder catches its own exceptions so a roster problem can
     * never cost the call queue its pop.
     */
    private function recordPresence(string $event, array $body): void
    {
        if (! in_array($event, PhonePresenceRecorder::events(), true)) {
            return;
        }

        app(PhonePresenceRecorder::class)->record(
            $event,
            (array) Arr::get($body, 'payload.object', [])
        );
    }

    /**
     * Answer Zoom's URL validation challenge.
     */
    private function urlValidation(array $body)
    {
        $plainToken = (string) Arr::get($body, 'payload.plainToken', '');
        $secret = (string) \Visnsstudio\VisnsPackages\Support\ZoomWebhookSecret::resolve();

        return response()->json([
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, $secret),
        ]);
    }

    /**
     * A call is ringing. Record + broadcast it when it is ringing somewhere the
     * office wants to see: a call queue, or a person's own extension.
     */
    private function handleRinging(string $event, array $body, WebhookLedger $ledger)
    {
        $object = (array) Arr::get($body, 'payload.object', []);
        $match = $this->resolveQueue($object);

        if ($match['excluded']) {
            // A queue the operator deliberately opted out of. Silent by design:
            // it is not a payload we failed to understand. Checked before the
            // direct branch on purpose — a call forwarded through an excluded
            // queue is still that queue's call, whichever handset it lands on.
            $ledger->outcome('ringing_excluded_queue');

            return response()->json(['status' => 'ignored']);
        }

        $queue = $match['queue'];
        $direct = $queue === null ? $this->resolveDirect($object) : null;

        if ($queue === null && $direct === null) {
            // Not a queue, and not a handset either — an auto receptionist, a
            // shared line group, something Zoom has not been asked about. Log
            // the routing shape (extension types/ids only, no caller identity)
            // so the matcher can be tuned from real events without putting PII
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

            // The ledger row keeps the same routing shape, durably: a debug line
            // is only there while somebody remembered to turn debug on.
            $ledger->outcome('ringing_unmatched');

            return response()->json(['status' => 'ignored']);
        }

        if ($direct !== null && ! ZoomCallQueueSetting::directPopsEnabled()) {
            // Direct pops switched off in Settings -> Call Queues, on the
            // `direct` pseudo-queue row. Its own outcome word rather than
            // `ringing_excluded_queue`: on the diagnostics screen "the office
            // turned direct pops off" and "somebody excluded a queue" are
            // different answers to "why did that not pop".
            $ledger->outcome('ringing_excluded_direct');

            return response()->json(['status' => 'ignored']);
        }

        $callId = $this->callId($object);

        if ($callId === '') {
            $ledger->outcome('ringing_no_call_id');

            $this->logUnhandled($event, $body, 'ringing event without a call_id');

            return response()->json(['status' => 'ignored']);
        }

        $callerNumber = $this->trim(Arr::get($object, 'caller.phone_number'));

        $ledger->callId($callId)
            ->queue(
                $queue === null ? ZoomCallQueueSetting::DIRECT_QUEUE_ID : $queue['id'],
                $queue === null ? 'Direct' : $queue['name']
            )
            ->caller($callerNumber);

        $attributes = [
            'queue_id' => $queue === null ? null : $queue['id'],
            'queue_name' => $queue === null ? null : $queue['name'],
            'kind' => $queue === null ? 'direct' : 'queue',
            'caller_number' => $callerNumber,
            'caller_name' => $this->trim(Arr::get($object, 'caller.name')),
            'status' => 'ringing',
            'started_at' => $this->startedAt($object),
            // Stamped on EVERY ringing event, not just the first: it is what
            // tells scopeLive() that a call somebody declined a moment ago is
            // still ringing another handset. See ZoomLiveQueueCall::scopeLive().
            'last_ringing_at' => Carbon::now(),
            'client_preview' => $this->clientPreview($callerNumber),
            'raw_payload' => $object,
        ];

        if ($direct !== null) {
            // Who it is ringing. A direct call has no queue to name it, so the
            // callee IS the card's subject.
            $attributes = array_merge($attributes, $direct);
        }

        /*
        | Both of a call's legs arrive in the same instant, and each of them
        | may be the one that creates the row. `updateOrCreate` reads then
        | inserts; when two workers both read "nothing there", the second
        | insert trips the unique index on call_id. That must not lose the
        | leg (a lost leg is a count that reaches zero early — the very bug
        | the legs column fixes) and must not 500 back to Zoom, so a losing
        | insert simply re-reads the row the winner made and updates it.
        */
        try {
            $call = ZoomLiveQueueCall::updateOrCreate(
                ['call_id' => $callId],
                $attributes
            );
        } catch (UniqueConstraintViolationException $e) {
            $call = ZoomLiveQueueCall::where('call_id', $callId)->first();

            if ($call === null) {
                throw $e;
            }

            $call->fill($attributes)->save();
        }

        /*
        | Fold this LEG into the row.
        |
        | Separate from the updateOrCreate above, and under a row lock, because
        | it is a read-modify-write of a JSON column rather than an overwrite:
        | production is PHP-FPM, one worker per request, and a queue fans one
        | call out to every member at once — two legs' webhooks landing in the
        | same millisecond would otherwise each read the same map and the second
        | save would drop the first leg. A dropped leg means the count reaches
        | zero early, which is the very bug this column exists to fix.
        */
        $this->withLockedRow($call, static function (ZoomLiveQueueCall $row) use ($object) {
            $row->recordRingingLeg((array) Arr::get($object, 'callee', []));
            $row->save();
        });

        $ringing = $this->eventClass('ringing', CallQueueRinging::class);

        $ledger->outcome(
            $queue === null ? 'ringing_recorded_direct' : 'ringing_recorded'
        );

        // Timed and recorded, then rethrown untouched. The publish is a
        // synchronous HTTP call to Reverb on this thread — the one step that
        // can fail while the row, the queue match and the 200 all look right,
        // which is exactly the "it only pops sometimes" complaint.
        $ledger->broadcast(static function () use ($ringing, $call) {
            event(new $ringing($call));
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * One leg of a call stopped ringing (declined, or timed out).
     *
     * Recorded, never acted on. Zoom sends `phone.callee_missed` per leg, and a
     * call is routinely ringing several: a queue rings every member's handset
     * on one call_id, and a direct call rings the desk phone and the mobile app.
     * Deleting the row here — which is what happened while this event lived in
     * ENDED_EVENTS — closed the pop on every watching screen the instant one
     * person waved the call away, while the phones were still ringing.
     *
     * So the miss is stamped and the row kept; ZoomLiveQueueCall::scopeLive()
     * decides whether anything is still ringing, and the browsers are told what
     * happened rather than being told the call is over.
     */
    private function handleMissedLeg(string $event, array $body, WebhookLedger $ledger)
    {
        $object = (array) Arr::get($body, 'payload.object', []);
        $callId = $this->callId($object);

        if ($callId === '') {
            $ledger->outcome('missed_no_call_id');

            $this->logUnhandled($event, $body, 'missed event without a call_id');

            return response()->json(['status' => 'ignored']);
        }

        $call = ZoomLiveQueueCall::where('call_id', $callId)->first();

        if ($call === null) {
            // A leg of a call we are not tracking (an excluded queue, an
            // extension type nobody pops for) — a broadcast would only make
            // other tabs flicker.
            $ledger->outcome('missed_no_match');

            return response()->json(['status' => 'ignored']);
        }

        $ledger->callId($callId)
            ->queue($call->queue_id, $call->queue_name)
            ->caller($call->caller_number);

        // The leg is settled AND the row stamped, because the two answer
        // different questions: the leg count says whether anything is still
        // ringing, and `last_missed_at` drives the grace window that keeps the
        // card up while Zoom's queue overflow re-offers the same call_id.
        // Locked for the same reason the ringing path is — see withLockedRow().
        $this->withLockedRow($call, static function (ZoomLiveQueueCall $row) use ($object) {
            $row->settleLeg(
                (array) Arr::get($object, 'callee', []),
                ZoomLiveQueueCall::LEG_MISSED
            );

            $row->forceFill(['last_missed_at' => Carbon::now()])->save();
        });

        $missed = $this->eventClass('missed', CallQueueMissed::class);

        $ledger->outcome('missed');

        $ledger->broadcast(static function () use ($missed, $callId) {
            event(new $missed($callId));
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * One leg of a call stopped ringing (`phone.callee_ended`).
     *
     * The rule, in the office's words: "if it stops ringing, it should close for
     * everyone." So this settles the leg and then asks the row how many are
     * left. Still ringing somewhere -> the pop is untouched and NOTHING is
     * broadcast; a leg ending while other handsets ring is invisible by design,
     * and a broadcast would only make every tab flicker. Nothing left ringing ->
     * the ordinary closing path runs, so the event class, the deletion and the
     * ledger's word for it are exactly what they were.
     *
     * A row with no legs recorded falls through to the old behaviour and closes
     * on the first ended event. That is deliberate: the only way to have no legs
     * is a row created by the release before this one (or one whose ringing
     * event we never saw), and "we do not know" must not mean "keep the card up
     * until the stale sweep".
     */
    private function handleEndedLeg(string $event, array $body, WebhookLedger $ledger)
    {
        $object = (array) Arr::get($body, 'payload.object', []);
        $callId = $this->callId($object);

        if ($callId === '') {
            $ledger->outcome('closed_no_call_id');

            $this->logUnhandled($event, $body, 'closing event without a call_id');

            return response()->json(['status' => 'ignored']);
        }

        $call = ZoomLiveQueueCall::where('call_id', $callId)->first();

        if ($call === null) {
            // Not one of ours, or already closed by the leg before this one.
            $ledger->outcome('closed_no_match');

            return response()->json(['status' => 'ignored']);
        }

        $stillRinging = $this->withLockedRow(
            $call,
            static function (ZoomLiveQueueCall $row) use ($object): bool {
                if (! $row->hasRecordedLegs()) {
                    return false;
                }

                // The return value is ignored on purpose. A delivery Zoom
                // retried finds its leg already settled and settles nothing —
                // which must NOT be read as "no leg matched, so close", or a
                // duplicate would close a call that is still ringing elsewhere.
                // The count below is the only thing that decides.
                $row->settleLeg(
                    (array) Arr::get($object, 'callee', []),
                    ZoomLiveQueueCall::LEG_ENDED
                );

                $row->save();

                return $row->ringingLegCount() > 0;
            },
            // Deleted between the read and the lock: nothing is ringing.
            false
        );

        if ($stillRinging) {
            $ledger->callId($callId)
                ->queue($call->queue_id, $call->queue_name)
                ->caller($call->caller_number);

            // Its own word, so the diagnostics screen shows plainly that a call
            // shed a leg without closing — that is the fix working, not a
            // dropped event.
            $ledger->outcome('ended_leg');

            return response()->json(['status' => 'ok']);
        }

        return $this->handleClosingEvent($event, $body, 'ended', $ledger);
    }

    /**
     * Mutate one live-call row under a row lock.
     *
     * Every leg-bookkeeping write is a read-modify-write of a JSON column, and
     * production runs a PHP-FPM worker per request while a queue fans one call
     * out to every member at once. Without the lock two legs arriving in the
     * same millisecond would each read the same map and the second save would
     * silently drop the first leg — and a lost leg makes the count reach zero
     * early, which is precisely the bug the column was added to fix.
     *
     * @param  callable(ZoomLiveQueueCall): mixed  $mutate
     * @param  mixed  $whenGone  Returned when the row was deleted before the
     *                           lock could be taken.
     * @return mixed
     */
    private function withLockedRow(ZoomLiveQueueCall $call, callable $mutate, $whenGone = null)
    {
        return DB::transaction(function () use ($call, $mutate, $whenGone) {
            $locked = ZoomLiveQueueCall::whereKey($call->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return $whenGone;
            }

            return $mutate($locked);
        });
    }

    /**
     * An SMS event.
     *
     * Answered 200 whatever happens, like everything else here. When the
     * messaging module is switched off the event is acknowledged and dropped -
     * an application running the call queue alone still has the SMS
     * subscriptions pointed at this URL if somebody ticked them in the Zoom
     * marketplace app, and that must not become an error.
     */
    private function handleSms(string $event, array $body)
    {
        if (! ModuleConfig::get('messaging.enabled', false)) {
            return response()->json(['status' => 'ignored']);
        }

        $status = app(SmsWebhookHandler::class)->handle($event, $body);

        return response()->json(['status' => $status]);
    }

    /**
     * Which class to dispatch for one of the call-queue events.
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
    private function handleClosingEvent(string $event, array $body, string $kind, WebhookLedger $ledger)
    {
        $object = (array) Arr::get($body, 'payload.object', []);
        $callId = $this->callId($object);

        if ($callId === '') {
            $ledger->outcome('closed_no_call_id');

            $this->logUnhandled($event, $body, 'closing event without a call_id');

            return response()->json(['status' => 'ignored']);
        }

        $call = ZoomLiveQueueCall::where('call_id', $callId)->first();

        if ($call === null) {
            // Not one of ours (or already cleared) — stay quiet, a stray
            // broadcast would only make other tabs flicker.
            $ledger->outcome('closed_no_match');

            return response()->json(['status' => 'ignored']);
        }

        $ledger->queue($call->queue_id, $call->queue_name)
            ->caller($call->caller_number);

        $call->delete();

        $class = $kind === 'answered'
            ? $this->eventClass('answered', CallQueueAnswered::class)
            : $this->eventClass('ended', CallQueueEnded::class);

        // 'answered' and 'closed' are kept apart: a queue where every call is
        // recorded and then closed without ever being answered is a different
        // fault from one where nothing is recorded at all.
        $ledger->outcome($kind === 'answered' ? 'answered' : 'closed');

        $ledger->broadcast(static function () use ($class, $callId) {
            event(new $class($callId));
        });

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
     * Is this a call ringing somebody's own phone, and if so, whose?
     *
     * Only asked once resolveQueue() has come back empty — a queue call that
     * reaches a member's handset names the member under `callee` too, and the
     * subject of that pop is the queue, not the handset.
     *
     * Production made the case for this plainly: every `ringing_unmatched` row
     * in the ledger had `callee.extension_type = "user"` and no `forwarded_by`.
     * They were direct dials, internal calls and transfers — the calls the
     * office most wants a card for, because nobody else's phone is ringing to
     * cover them — and the pop had been dropping every one of them.
     *
     * `call_queue.direct_calls.enabled` is the master switch: off means the
     * feature is not installed here at all, and these calls go back to being
     * `ringing_unmatched`, exactly as before. The operator's own switch is the
     * `direct` settings row, checked by the caller, so that "the office turned
     * it off" reads differently in the ledger from "this build does not do it".
     *
     * @return array<string, ?string>|null  The callee columns for the row, or
     *                                      null when this is not a direct call.
     */
    private function resolveDirect(array $object): ?array
    {
        if (! (bool) ModuleConfig::get('call_queue.direct_calls.enabled', true)) {
            return null;
        }

        $callee = Arr::get($object, 'callee');

        if (! is_array($callee)) {
            return null;
        }

        $type = strtolower($this->trim(Arr::get($callee, 'extension_type')));

        $accepted = array_map(
            fn($value) => strtolower($this->trim($value)),
            (array) ModuleConfig::get(
                'call_queue.direct_calls.extension_types',
                self::DIRECT_EXTENSION_TYPES
            )
        );

        if ($type === '' || ! in_array($type, $accepted, true)) {
            return null;
        }

        $forwardedBy = Arr::get($object, 'forwarded_by');

        return [
            'callee_name' => $this->nullable(Arr::get($callee, 'name')),
            'callee_extension_id' => $this->nullable(
                Arr::get($callee, 'extension_id') ?? Arr::get($callee, 'id')
            ),
            'callee_extension_number' => $this->nullable(
                Arr::get($callee, 'extension_number')
            ),
            // Kept verbatim rather than normalised: it is what the ledger and
            // the log show, and a shape Zoom changes is easier to spot when it
            // has not been tidied on the way in.
            'callee_extension_type' => $this->nullable(
                Arr::get($callee, 'extension_type')
            ),
            // "Transferred by Steve" — the single most useful line on a direct
            // pop, and the only reason forwarded_by is read for a call that has
            // no queue in it.
            'forwarded_by_name' => is_array($forwardedBy)
                ? $this->nullable(Arr::get($forwardedBy, 'name'))
                : null,
        ];
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

    /** trim(), but an empty result is null — columns, not response strings. */
    private function nullable($value): ?string
    {
        $value = $this->trim($value);

        return $value === '' ? null : $value;
    }
}
