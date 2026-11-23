<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Billing;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Contracts\BillingQuantityResolver;
use Visnsstudio\VisnsPackages\Models\BillingFeature;

/** Per-unit for `portal_messaging` only; a test sets the count. */
class StubQuantityResolver implements BillingQuantityResolver
{
    public static int $count = 0;

    public function quantity(Model $billable, BillingFeature $feature): ?int
    {
        return $feature->key === 'portal_messaging' ? self::$count : null;
    }
}
