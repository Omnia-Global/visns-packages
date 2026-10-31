<?php

namespace Visnsstudio\VisnsPackages\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory;

/**
 * `billing.stripe-signature`: verifies Stripe's signature on the RAW body
 * with the SDK's `Webhook::constructEvent` and its 300-second tolerance, and
 * hands the verified Event to the controller as the `stripe_event` attribute.
 *
 * No secret configured: 401, everything refused. Bad or stale signature, or
 * a body that is not an event: 400. The exception's words are never echoed
 * or logged; the body's length is.
 */
class VerifyStripeSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next)
    {
        $secret = app(StripeClientFactory::class)->webhookSecret();

        if ($secret === null) {
            Log::warning('billing.webhook_unconfigured');

            return response()->json(['ok' => false], 401);
        }

        $body = (string) $request->getContent();

        try {
            $event = Webhook::constructEvent($body, (string) $request->header('Stripe-Signature', ''), $secret, self::TOLERANCE_SECONDS);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('billing.webhook_refused', ['reason' => class_basename($e), 'length' => strlen($body)]);

            return response()->json(['ok' => false], 400);
        }

        $request->attributes->set('stripe_event', $event);

        return $next($request);
    }
}
