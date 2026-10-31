<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A billable party gained or lost a feature. Dispatched after the
 * entitlement log row is written, so a host can provision a feature that needs
 * a person to set it up, or clear a cache.
 */
class BillingEntitlementChanged
{
    use Dispatchable;

    public function __construct(
        public string $billableType,
        public int $billableId,
        public string $featureKey,
        public bool $entitled,
        public string $reason,
    ) {
    }
}
