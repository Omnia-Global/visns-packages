<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingRefusal;

/**
 * A link into Stripe's hosted Customer Portal, where a buyer changes plan,
 * updates a card or a direct debit, reads invoices or cancels.
 */
class CustomerPortal
{
    public function __construct(
        private StripeClientFactory $stripe,
        private BillingCustomers $customers,
    ) {
    }

    /** @throws BillingRefusal when the party has never been a Stripe customer */
    public function url(Model $billable, string $returnUrl): string
    {
        $customer = $this->customers->find($billable);

        if (! $customer) {
            throw new BillingRefusal('There is no billing account yet. Subscribe to a feature first.');
        }

        $params = ['customer' => $customer->stripe_customer_id, 'return_url' => $returnUrl];

        if ($configuration = $this->stripe->portalConfigurationId()) {
            $params['configuration'] = $configuration;
        }

        $session = $this->stripe->make()->billingPortal->sessions->create($params);

        Log::info('billing.portal_opened', ['billable_id' => $customer->billable_id]);

        return (string) $session->url;
    }
}
