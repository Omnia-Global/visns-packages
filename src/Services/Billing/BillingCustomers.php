<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;

/**
 * The one writer of the billable → Stripe Customer mapping.
 *
 * One Stripe Customer per billable party per Stripe mode, never per person: a
 * contact who manages billing for two clients pays as two customers.
 */
class BillingCustomers
{
    public function __construct(
        private StripeClientFactory $stripe,
        private Billables $billables,
    ) {
    }

    /** The mapping in the current mode, or null when none was made yet. */
    public function find(Model $billable): ?BillingCustomer
    {
        return $this->billables->scope(BillingCustomer::query(), $billable)
            ->where('livemode', $this->stripe->livemode())
            ->first();
    }

    /** The Stripe Customer for a party, created on first use. */
    public function ensure(Model $billable): BillingCustomer
    {
        if ($existing = $this->find($billable)) {
            return $existing;
        }

        [$type, $id] = $this->billables->morph($billable);
        $livemode = $this->stripe->livemode();
        $details = $this->billables->resolver()->describe($billable);
        $name = $this->clean($details['name'] ?? null, 191);
        $email = $this->clean($details['email'] ?? null, 191);

        $params = array_filter([
            'name' => $name,
            'email' => $email,
            'metadata' => ['billable_type' => $type, 'billable_id' => (string) $id],
        ], fn ($value) => $value !== null);

        // Two simultaneous first checkouts must not make two customers.
        $idempotency = 'billing-customer-' . preg_replace('/[^A-Za-z0-9]+/', '-', $type) . '-' . $id . '-' . ($livemode ? 'live' : 'test');

        $customer = $this->stripe->make()->customers->create($params, ['idempotency_key' => $idempotency]);

        $row = new BillingCustomer();
        $row->forceFill([
            'billable_type' => $type,
            'billable_id' => $id,
            'stripe_customer_id' => $customer->id,
            'livemode' => $livemode,
            'email' => $email,
            'name' => $name,
        ]);

        try {
            $row->save();
        } catch (QueryException $e) {
            // The other request won the race with the same customer id.
            return $this->find($billable) ?? throw $e;
        }

        Log::info('billing.customer_created', ['billable_id' => $id, 'stripe_customer_id' => $customer->id, 'livemode' => $livemode]);

        return $row;
    }

    /** The party a Stripe Customer id maps to, as a stored morph pair. */
    public function byStripeId(?string $stripeCustomerId): ?BillingCustomer
    {
        if (! $stripeCustomerId) {
            return null;
        }

        return BillingCustomer::query()->where('stripe_customer_id', $stripeCustomerId)->first();
    }

    private function clean($value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
