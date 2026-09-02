<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Events\CallQueueDiagnosticPing;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;
use Visnsstudio\VisnsPackages\Services\Zoom\WebhookLedger;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\ZoomWebhookSecret;

/**
 * Snapshot of the live call queue state, for the call queue pop to hydrate from
 * on page load (broadcasts only cover what happens after the tab is listening).
 */
class CallQueueController extends \App\Http\Controllers\Controller
{
    public function live()
    {
        /*
        | How long a row may sit in the table before it is treated as abandoned.
        | Zoom does not guarantee a closing event for every call, so without this
        | a dropped webhook would leave a card ringing forever.
        */
        $staleAfterMinutes = (int) ModuleConfig::get('call_queue.stale_after_minutes', 15);

        ZoomLiveQueueCall::where(
            'created_at',
            '<',
            Carbon::now()->subMinutes($staleAfterMinutes)
        )->delete();

        /*
        | And the shorter window: a call every leg gave up on. A miss no longer
        | deletes its row (Zoom sends `phone.callee_missed` per leg, and a queue
        | rings four handsets on one call_id), so something has to clear the
        | ones nothing rang again — otherwise every declined call would sit here
        | until the far longer stale-ring sweep above caught it. Same
        | opportunistic place, same reasoning: the snapshot is the only thing
        | that reads these rows.
        */
        ZoomLiveQueueCall::deadAfterMiss()->delete();

        $calls = ZoomLiveQueueCall::live()
            ->orderBy('started_at')
            ->get()
            ->map(fn(ZoomLiveQueueCall $call) => $call->toPopPayload())
            ->values();

        return response()->json([
            'calls' => $calls,
            'pickup_codes' => $this->pickupCodes(),
            // The Echo channel the pop subscribes to; the frontend never
            // hardcodes it, it uses whatever is named here.
            'channel' => CallQueueChannel::name(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    |
    | "The pop only shows up some of the time" has four candidate causes and,
    | before this endpoint, no way to tell them apart from a browser: Zoom never
    | delivered the event, the signature turned it away, the queue did not
    | match, or the broadcast to Reverb failed. The webhook ledger records which
    | of those happened to every delivery; this reads it back alongside the
    | configuration those answers have to be read against.
    |
    | Behind the SETTINGS permission, not the monitor's: it names the broadcast
    | target and the shape of the event stream, which is administrator material
    | even though it carries no secret.
    */

    /**
     * Everything needed to explain a missing pop, in one response.
     */
    public function diagnostics()
    {
        $retainDays = $this->retainDays();

        /*
        | Pruned on read rather than on a schedule. The table is a rolling
        | window, not a record, and an application that never adopted the
        | package's console commands would otherwise grow it forever — the
        | endpoint is the only thing that reads these rows, so it is the natural
        | place to also drop the ones nobody will read again.
        */
        try {
            ZoomWebhookEvent::where(
                'received_at',
                '<',
                Carbon::now()->subDays($retainDays)
            )->delete();
        } catch (\Throwable $e) {
            // A diagnostics screen that 500s because its own housekeeping
            // failed would be worse than a slightly overgrown table.
        }

        return response()->json([
            'server' => $this->serverDiagnostics($retainDays),
            'summary' => $this->ledgerSummary(),
            'events' => ZoomWebhookEvent::orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn(ZoomWebhookEvent $event) => $event->toDiagnosticsPayload())
                ->values(),
        ]);
    }

    /**
     * Broadcast a test event on the pop's own channel.
     *
     * The one leg the ledger cannot vouch for is the last one: PHP publishing
     * successfully proves Reverb accepted the event, not that a browser is
     * subscribed and listening. This fires the real thing — same channel, same
     * private subscription, same synchronous path — and hands back a nonce the
     * screen watches for. Received means a real pop would have arrived too.
     */
    public function ping()
    {
        $nonce = (string) Str::uuid();
        $started = microtime(true);

        try {
            event(new CallQueueDiagnosticPing($nonce));

            return response()->json([
                'ok' => true,
                'nonce' => $nonce,
                'ms' => $this->elapsed($started),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            // Answered 200 with ok:false on purpose: the failure IS the result
            // the screen is asking for, and an error status would only render
            // as a broken request.
            return response()->json([
                'ok' => false,
                'nonce' => $nonce,
                'ms' => $this->elapsed($started),
                // Scrubbed: the broadcast client reports a failed publish by
                // quoting the whole request URL, credentials and all.
                'error' => WebhookLedger::redact($e->getMessage()),
            ]);
        }
    }

    /**
     * The configuration a missing pop has to be read against — where broadcasts
     * are published, on what channel, and whether the webhook can authenticate
     * at all. Credentials are never included; only where they point.
     */
    private function serverDiagnostics(int $retainDays): array
    {
        $driver = (string) config('broadcasting.default');
        $secret = ZoomWebhookSecret::resolve();

        return [
            'app_env' => (string) config('app.env'),
            'broadcast_driver' => $driver,
            'publish_target' => $this->publishTarget($driver),
            'channel' => CallQueueChannel::name(),
            'queue_connection' => (string) config('queue.default'),
            'log_level' => $this->logLevel(),
            // Whether, not what. An unset secret 401s every delivery, which is
            // the single most common way for the pop to stop entirely.
            'webhook_secret_configured' => is_string($secret) && $secret !== '',
            'excluded_queue_ids' => array_values(array_filter(array_map(
                fn($value) => $this->trim($value),
                ZoomCallQueueSetting::excludedIds()
            ))),
            'stale_after_minutes' => (int) ModuleConfig::get(
                'call_queue.stale_after_minutes',
                15
            ),
            'live_rows' => ZoomLiveQueueCall::count(),
            'retain_days' => $retainDays,
        ];
    }

    /**
     * Where the server publishes broadcasts, as a bare origin.
     *
     * Reverb and Pusher name it differently — Reverb carries an explicit
     * host/port/scheme, hosted Pusher only a cluster — and the value worth
     * seeing is the same either way: the address PHP is trying to reach, which
     * is NOT where the browser connects (see config/broadcasting.php).
     */
    private function publishTarget(string $driver): ?string
    {
        $options = (array) config(
            'broadcasting.connections.' . $driver . '.options',
            []
        );

        $host = $this->trim($options['host'] ?? null);

        if ($host === '' && ! empty($options['cluster'])) {
            $host = 'api-' . $this->trim($options['cluster']) . '.pusher.com';
        }

        if ($host === '') {
            return null;
        }

        $scheme = $this->trim($options['scheme'] ?? null);

        if ($scheme === '') {
            $scheme = ($options['useTLS'] ?? true) ? 'https' : 'http';
        }

        $port = $this->trim($options['port'] ?? null);

        return $scheme . '://' . $host . ($port === '' ? '' : ':' . $port);
    }

    /**
     * The effective log level, so a reader knows whether the debug lines this
     * pipeline writes would have been kept at all. A `stack` channel has no
     * level of its own; its first member's stands in.
     */
    private function logLevel(): ?string
    {
        $channel = (string) config('logging.default');
        $config = (array) config('logging.channels.' . $channel, []);

        if (isset($config['level'])) {
            return (string) $config['level'];
        }

        $first = $this->trim(($config['channels'] ?? [null])[0] ?? null);

        if ($first === '') {
            return null;
        }

        $level = config('logging.channels.' . $first . '.level');

        return is_string($level) ? $level : null;
    }

    /**
     * Outcome counts over two windows, because they answer different questions:
     * the day tells you what is happening now, the week tells you whether it is
     * new.
     */
    private function ledgerSummary(): array
    {
        $now = Carbon::now();

        $last = fn(?string $outcome) => optional(
            ZoomWebhookEvent::when(
                $outcome !== null,
                fn($query) => $query->where('outcome', $outcome)
            )
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->first()
        )->received_at;

        return [
            'last_24h' => $this->outcomeCounts($now->copy()->subDay()),
            'last_7d' => $this->outcomeCounts($now->copy()->subDays(7)),
            'last_event_at' => optional($last(null))->toIso8601String(),
            'last_ringing_recorded_at' => optional(
                $last('ringing_recorded')
            )->toIso8601String(),
            'broadcast_failures_24h' => ZoomWebhookEvent::where(
                'received_at',
                '>=',
                $now->copy()->subDay()
            )->where('broadcast', 'failed')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function outcomeCounts(Carbon $since): array
    {
        return ZoomWebhookEvent::where('received_at', '>=', $since)
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->map(fn($total) => (int) $total)
            ->all();
    }

    private function retainDays(): int
    {
        return max(1, (int) ModuleConfig::get(
            'call_queue.diagnostics.retain_days',
            7
        ));
    }

    private function elapsed(float $from): int
    {
        return (int) round((microtime(true) - $from) * 1000);
    }

    private function trim($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Pickup codes keyed by Zoom call queue id — the pop renders these, so it
     * never has to hardcode a dial string. A queue with no entry here still
     * pops, just without a Pick up button.
     *
     * Maintained in Settings -> Call Queues; the model caches the lookup, since
     * this runs on every page load of every listening tab.
     *
     * @return array<string, string>
     */
    private function pickupCodes(): array
    {
        $codes = [];

        foreach (ZoomCallQueueSetting::pickupCodes() as $queueId => $code) {
            $queueId = $this->trim($queueId);
            $code = $this->trim($code);

            if ($queueId !== '' && $code !== '') {
                $codes[$queueId] = $code;
            }
        }

        return $codes;
    }
}
