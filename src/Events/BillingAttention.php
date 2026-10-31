<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stripe reported something a person should look at: a refund, a dispute or
 * an early fraud warning. Nothing is revoked automatically; the host decides
 * who is told (a bell to the billing staff, say).
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
    ) {
    }
}
