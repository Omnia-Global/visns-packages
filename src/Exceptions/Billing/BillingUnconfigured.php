<?php

namespace Visnsstudio\VisnsPackages\Exceptions\Billing;

use RuntimeException;

/**
 * Stripe is not configured (no restricted key, a refused key, or no billable
 * resolver). Answered `200 {ok:false, reason:'unconfigured'}`.
 */
class BillingUnconfigured extends RuntimeException
{
}
