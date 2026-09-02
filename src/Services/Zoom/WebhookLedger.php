<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;

/**
 * The durable record of what one Zoom webhook delivery did.
 *
 * "The call pop only shows up some of the time" was, until this class existed,
 * unanswerable: a delivery that never arrived, one the signature turned away,
 * one whose queue did not match, and one whose broadcast failed all look
 * exactly the same from a browser that did not pop — and the endpoint answers
 * 200 to every one of them, because anything else gets the Zoom subscription
 * disabled. So each delivery leaves a row saying which of those it was.
 *
 * Usage is a three-step shape the controller reads top to bottom:
 *
 *     $ledger = WebhookLedger::open($event, $object);
 *     try     { ... $ledger->outcome('ringing_recorded'); }
 *     catch   { $ledger->failed($e); }
 *     finally { $ledger->write(); }
 *
 * The instance holds this delivery's state and nothing else — no container
 * binding, no statics — because under Octane a shared recorder would leak one
 * request's call into the next one's row.
 *
 * NOTHING here may throw. The write is wrapped, the ledger being broken is
 * strictly less bad than the pop being broken, and a ledger that could take the
 * webhook down would be a diagnostic that caused the fault it was installed to
 * find.
 */
class WebhookLedger
{
    /** Started, but no verdict recorded — the delivery is still in flight. */
    private const OUTCOME_PENDING = 'pending';

    /** Floor between two `rejected` rows for the same reason. */
    private const REJECTED_ROW_EVERY_SECONDS = 5;

    private array $attributes;

    /** microtime(true) at entry, for duration_ms. */
    private float $startedAt;

    private bool $written = false;

    private function __construct(array $attributes)
    {
        $this->attributes = $attributes;
        $this->startedAt = microtime(true);
    }

    /**
     * Begin recording a delivery.
     *
     * @param  array  $object  `payload.object` from the delivery, for the call
     *                         id and the routing shape. Never stored whole.
     */
    public static function open(string $event, array $object = []): self
    {
        return new self([
            'event' => $event === '' ? '(none)' : substr($event, 0, 80),
            'call_id' => self::text(
                Arr::get($object, 'call_id') ?? Arr::get($object, 'callId'),
                120
            ),
            'outcome' => self::OUTCOME_PENDING,
            'meta' => self::routingMeta($object),
            'received_at' => Carbon::now(),
        ]);
    }

    /**
     * A delivery the signature middleware turned away.
     *
     * Called from the application's Zoom middleware, which is the only place
     * that knows a delivery arrived at all — the controller never runs for
     * these. No payload is recorded: an unauthenticated body is not evidence of
     * anything except its own rejection.
     */
    public static function rejected(string $reason, array $meta = []): void
    {
        // The webhook sits outside the api rate limiter (Zoom's events all
        // share a source IP), so an unsigned flood must not be able to grow
        // this table a row per request. One row per reason every few seconds
        // still shows a drifted secret plainly; it just cannot be weaponised.
        try {
            $gate = 'zoom.webhook.rejected:' . md5($reason);

            if (! Cache::add($gate, 1, self::REJECTED_ROW_EVERY_SECONDS)) {
                return;
            }
        } catch (\Throwable $e) {
            // No cache is no reason to lose the row.
        }

        (new self([
            'event' => 'rejected',
            'outcome' => substr($reason, 0, 40),
            'meta' => $meta === [] ? null : $meta,
            'received_at' => Carbon::now(),
        ]))->write();
    }

    /**
     * The verdict for this delivery. Last one wins, so a branch may narrow an
     * earlier guess.
     */
    public function outcome(string $outcome): self
    {
        $this->attributes['outcome'] = substr($outcome, 0, 40);

        return $this;
    }

    /**
     * The call this delivery is about, when it was only discovered mid-flight.
     */
    public function callId(string $callId): self
    {
        if ($callId !== '') {
            $this->attributes['call_id'] = substr($callId, 0, 120);
        }

        return $this;
    }

    /**
     * The queue the call matched, once resolveQueue() has spoken.
     */
    public function queue(?string $id, ?string $name): self
    {
        $this->attributes['queue_id'] = self::text($id, 120);
        $this->attributes['queue_name'] = self::text($name, 160);

        return $this;
    }

    /**
     * The caller's number. The only piece of caller identity kept here — it is
     * what makes a row findable when somebody says "the call at half past two
     * did not pop", and the live-call table already holds it.
     */
    public function caller(?string $number): self
    {
        $this->attributes['caller_number'] = self::text($number, 40);

        return $this;
    }

    /**
     * Run the broadcast, timing it and recording whether it landed.
     *
     * The publish is a synchronous HTTP call to Reverb on this very thread, so
     * it is both the slowest thing the webhook does and the one most likely to
     * fail silently from a browser's point of view. Failures are RETHROWN
     * untouched: the controller's outer catch still logs `zoom.webhook failed`
     * and still answers 200, exactly as before this class existed.
     */
    public function broadcast(callable $dispatch): void
    {
        $started = microtime(true);

        try {
            $dispatch();

            $this->attributes['broadcast'] = 'ok';
            $this->attributes['broadcast_ms'] = self::elapsed($started);
        } catch (\Throwable $e) {
            $this->attributes['broadcast'] = 'failed';
            $this->attributes['broadcast_ms'] = self::elapsed($started);
            $this->attributes['error'] = self::redact($e->getMessage());

            throw $e;
        }
    }

    /**
     * The delivery threw. Records the message and marks the outcome, unless a
     * branch already recorded a more specific one alongside its own failure.
     */
    public function failed(\Throwable $e): self
    {
        $this->attributes['outcome'] = 'failed';
        $this->attributes['error'] ??= self::redact($e->getMessage());

        return $this;
    }

    /**
     * Persist the row. Idempotent, and silent on failure — see the class note.
     */
    public function write(): void
    {
        if ($this->written) {
            return;
        }

        $this->written = true;

        $this->attributes['duration_ms'] ??= self::elapsed($this->startedAt);

        try {
            ZoomWebhookEvent::create($this->attributes);
        } catch (\Throwable $e) {
            // A missing table, a full disk, a column that drifted: the ledger
            // going quiet is the acceptable outcome, the webhook 500ing is not.
            Log::warning('zoom.webhook ledger write failed', [
                'event' => $this->attributes['event'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * An exception message with its credentials taken out.
     *
     * A failed publish is the single most valuable line in this table and also
     * the most dangerous: the Pusher/Reverb client reports the failure by
     * quoting the whole request URL, which carries `auth_key` and the request's
     * `auth_signature`. Those would otherwise be written into a diagnostics
     * table an administrator reads in a browser. The message keeps its shape -
     * host, port, curl error - and loses the credentials.
     *
     * Public because the diagnostics ping reports the same failure through the
     * same client and needs the same treatment.
     */
    public static function redact(string $message): string
    {
        $message = (string) preg_replace(
            '/\b(auth_key|auth_signature|secret|token|key)=[^&\s]+/i',
            '$1=REDACTED',
            $message
        );

        return substr($message, 0, 2000);
    }

    /**
     * Where the call was routed, and nothing else.
     *
     * Zoom's queue events are inconsistently shaped — sometimes the callee IS
     * the queue, sometimes the queue only appears under `forwarded_by` — and
     * "which shape was this one" is precisely the question an unmatched ringing
     * event raises. Names are deliberately dropped: `extension_type` plus the
     * ids is enough to tune the matcher, and a caller's name in a diagnostics
     * table is PII nobody asked for.
     */
    private static function routingMeta(array $object): ?array
    {
        $shape = static function ($node): ?array {
            if (! is_array($node)) {
                return null;
            }

            $fields = array_filter([
                'extension_type' => self::text(Arr::get($node, 'extension_type'), 40),
                'id' => self::text(
                    Arr::get($node, 'extension_id') ?? Arr::get($node, 'id'),
                    120
                ),
                'extension_number' => self::text(
                    Arr::get($node, 'extension_number'),
                    32
                ),
            ], static fn($value) => $value !== null);

            return $fields === [] ? null : $fields;
        };

        $meta = array_filter([
            'callee' => $shape(Arr::get($object, 'callee')),
            'forwarded_by' => $shape(Arr::get($object, 'forwarded_by')),
        ], static fn($value) => $value !== null);

        return $meta === [] ? null : $meta;
    }

    /**
     * A trimmed, length-capped string, or null when there was nothing there.
     */
    private static function text($value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, $limit);
    }

    private static function elapsed(float $from): int
    {
        return (int) round((microtime(true) - $from) * 1000);
    }
}
