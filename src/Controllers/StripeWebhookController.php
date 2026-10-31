<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Stripe\Event;
use Visnsstudio\VisnsPackages\Services\Billing\WebhookProcessor;

/**
 * `POST api/billing/stripe/webhook`, behind `billing.stripe-signature`.
 *
 * 200 for everything applied, ignored or already seen; 500 when a handler
 * failed, so Stripe delivers it again.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookProcessor $processor)
    {
        $event = $request->attributes->get('stripe_event');

        if (! $event instanceof Event) {
            return response()->json(['ok' => false], 400);
        }

        $result = $processor->process($event);

        return response()->json(['ok' => $result['status'] < 300, 'outcome' => $result['outcome']], $result['status']);
    }
}
