<?php

namespace Visnsstudio\VisnsPackages\Contracts;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Models\BillingFeature;

/**
 * The host application's answer to "how many", named in
 * `visns-packages.billing.hooks.quantity`. Optional: without one every
 * subscription is a quantity of 1 (a flat price).
 *
 * A feature priced per unit (per number, per seat) bills `unit price ×
 * quantity`. The host counts the units it already holds (its own rows), the
 * package carries the count to Stripe: at Checkout, and through
 * Services\Billing\Quantities::sync() whenever the host says the count may
 * have moved.
 */
interface BillingQuantityResolver
{
    /**
     * How many units this party should be billed for this feature, or null
     * for a flat feature (billed as 1). Anything below 1 is billed as 1: a
     * live subscription always carries at least one unit.
     */
    public function quantity(Model $billable, BillingFeature $feature): ?int;
}
