<?php

namespace Visnsstudio\VisnsPackages\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Services\Zoom\WebhookLedger;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\ZoomWebhookSecret;

/**
 * Verifies the signature Zoom puts on every webhook delivery.
 *
 * Zoom signs the raw request body:
 *   x-zm-signature = "v0=" . hash_hmac('sha256', "v0:{timestamp}:{body}", $secret)
 * where {timestamp} is the x-zm-request-timestamp header.
 *
 * Fails closed: an unset secret (which is the state until the Zoom app exists)
 * rejects everything with 401, so the endpoint is inert rather than open.
 *
 * Several secrets since 4.19.0 (Support\ZoomWebhookSecret::candidates): each is
 * tried with hash_equals, and the one that matched is put on the request as
 * `zoom_webhook_secret` so the URL-validation answer can be signed with it.
 */
class VerifyZoomWebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        $secrets = $this->secrets();

        if ($secrets === []) {
            return $this->reject($request, 'webhook secret not configured');
        }

        $signature = $request->header('x-zm-signature');
        $timestamp = $request->header('x-zm-request-timestamp');

        if (! is_string($signature) || $signature === '') {
            return $this->reject($request, 'missing signature header');
        }

        if (! is_string($timestamp) || ! ctype_digit(ltrim($timestamp, '-'))) {
            return $this->reject($request, 'missing or malformed timestamp');
        }

        // Replay guard. Zoom sends seconds; anything wildly out of range (e.g.
        // milliseconds) fails here rather than being silently accepted.
        if (abs(time() - (int) $timestamp) > $this->maxClockSkewSeconds()) {
            return $this->reject($request, 'timestamp outside allowed window');
        }

        $matched = null;

        // Every candidate is tried, not just until the first match, so the
        // time this takes says nothing about which secret signed the request.
        foreach ($secrets as $secret) {
            $expected =
                'v0=' .
                hash_hmac(
                    'sha256',
                    'v0:' . $timestamp . ':' . $request->getContent(),
                    $secret
                );

            if (hash_equals($expected, $signature) && $matched === null) {
                $matched = $secret;
            }
        }

        if ($matched === null) {
            return $this->reject($request, 'signature mismatch');
        }

        $request->attributes->set('zoom_webhook_secret', $matched);

        if ($this->isReplay($signature)) {
            return $this->acknowledgeReplay($request);
        }

        return $next($request);
    }

    /**
     * Replay protection: has this exact signed delivery been processed already?
     *
     * The timestamp window alone lets a captured delivery be sent again for
     * five minutes. The signature covers the timestamp and the whole body, so
     * it identifies one delivery; it is remembered (hashed) for the window and
     * a second arrival inside it is a replay. Cache::add is atomic, so two
     * copies racing each other cannot both pass. The store must be one every
     * web worker shares (file, redis, database) - an `array` store would make
     * this a per-request no-op.
     */
    private function isReplay(string $signature): bool
    {
        $window = max(1, $this->maxClockSkewSeconds());

        // Remembered for the whole span in which the same timestamp would still
        // pass the window check: up to `window` seconds either side of now.
        return ! Cache::add(
            'visns:zoom-webhook:seen:' . hash('sha256', $signature),
            1,
            now()->addSeconds($window * 2)
        );
    }

    /**
     * A replay is answered 200 and not processed. Not an error status: Zoom
     * disables a subscription that keeps getting them, and a genuine duplicate
     * delivery from Zoom itself is not a fault.
     */
    private function acknowledgeReplay(Request $request)
    {
        Log::info('Zoom webhook: duplicate delivery acknowledged and skipped', [
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return response()->json(['status' => 'duplicate'], 200);
    }

    /**
     * @return array<int, string>
     */
    private function secrets(): array
    {
        return ZoomWebhookSecret::candidates();
    }

    /** Reject deliveries whose timestamp is further than this from now. */
    private function maxClockSkewSeconds(): int
    {
        return (int) ModuleConfig::get('call_queue.max_clock_skew_seconds', 300);
    }

    private function reject(Request $request, string $reason)
    {
        Log::warning('Zoom webhook: unauthorized request', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        /*
        | And durably, in the webhook ledger.
        |
        | This is the only place that knows a rejected delivery arrived at all -
        | the controller never runs for these - so without a row here a secret
        | that has drifted out of step with the Zoom app is indistinguishable
        | from Zoom having gone quiet. The payload is deliberately not recorded:
        | an unauthenticated body is evidence of nothing but its own rejection.
        */
        WebhookLedger::rejected($reason, ['ip' => $request->ip()]);

        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
