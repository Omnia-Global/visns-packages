<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Billing;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Controllers\BillingPortalController;
use Visnsstudio\VisnsPackages\Models\BillingFeature;

/**
 * What a host's client portal does with the billing controller: extend it,
 * point the URL hooks at its own pages, mount it behind its own guard.
 */
class PortalBillingController extends BillingPortalController
{
    protected function successUrl(Request $request, BillingFeature $feature): string
    {
        return rtrim((string) config('app.url'), '/') . '/client/billing?feature=' . $feature->key . '&checkout={CHECKOUT_SESSION_ID}';
    }

    protected function cancelUrl(Request $request, BillingFeature $feature): string
    {
        return rtrim((string) config('app.url'), '/') . '/client/billing';
    }

    protected function actorId(Request $request): ?int
    {
        return 77;
    }
}
