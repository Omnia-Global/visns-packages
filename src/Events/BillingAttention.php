<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something in billing a person should look at: a refund (`refunded`), a
 * dispute (`disputed`), an early fraud warning (`fraud_warning`), or a webhook
 * handler that threw (`handler_failed`: Stripe will retry, but access may be
 * out of step until it succeeds). Nothing is revoked automatically; the host
 * decides who is told (an email or a bell to the billing staff, say).
 *
 * Ids only, never an amount, an email or a payload.
 */
class BillingAttention
{
    use Dispatchable;

    public function __construct(
        public string $kind,
        public string $stripeEventId,
        public ?string $stripeObjectId,
        public ?string $stripeSubscriptionId,
        public ?int $subscriptionId,
        public ?string $billableType,
        public ?int $billableId,
        public ?string $eventType = null,
    ) {
    }
}
