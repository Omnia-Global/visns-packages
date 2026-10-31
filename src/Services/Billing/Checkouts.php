<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingRefusal;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Starts a hosted Checkout for one feature, and answers the success-page poll.
 *
 * The session is `mode: subscription` with one Price, our checkout id as
 * `client_reference_id`, the GST rate as the subscription's default tax rate
 * and NO `payment_method_types`: which methods are offered (card, BECS direct
 * debit) is set in the Dashboard, not here.
 */
class Checkouts
{
    public function __construct(
        private StripeClientFactory $stripe,
        private Billables $billables,
        private BillingCustomers $customers,
        private Entitlements $entitlements,
        private Fulfilment $fulfilment,
    ) {
    }

    /**
     * @return array{url: string, checkout: BillingCheckout}
     *
     * @throws BillingRefusal
     */
    public function start(Model $billable, BillingFeature $feature, string $interval, string $successUrl, string $cancelUrl, ?int $createdBy = null): array
    {
        if (! in_array($interval, BillingFeature::INTERVALS, true)) {
            throw new BillingRefusal('Choose monthly or annual billing.');
        }

        if (! $feature->available) {
            throw new BillingRefusal("{$feature->name} is not available yet.");
        }

        if ($feature->amountFor($interval) === null) {
            throw new BillingRefusal("{$feature->name} is not offered " . ($interval === BillingFeature::INTERVAL_YEAR ? 'annually.' : 'monthly.'));
        }

        $livemode = $this->stripe->livemode();
        $priceId = $feature->priceIdFor($interval);

        if (! $priceId || $feature->stripe_livemode !== $livemode) {
            throw new BillingRefusal("{$feature->name} is not ready to buy yet.");
        }

        if ($this->entitlements->whyFeature($billable, $feature)['entitled']) {
            throw BillingRefusal::conflict("{$feature->name} is already included.");
        }

        if ($resumed = $this->resumeOpen($billable, $feature, $interval)) {
            return $resumed;
        }

        $customer = $this->customers->ensure($billable);
        [$type, $id] = $this->billables->morph($billable);

        $checkout = new BillingCheckout();
        $checkout->forceFill([
            'billable_type' => $type,
            'billable_id' => $id,
            'feature_id' => $feature->id,
            'interval' => $interval,
            'status' => BillingCheckout::STATUS_OPEN,
            'livemode' => $livemode,
            'created_by' => $createdBy,
        ])->save();

        $subscriptionData = [
            // A fallback for the webhook only; the object graph comes first.
            'metadata' => [
                'billing_checkout_id' => (string) $checkout->id,
                'billing_feature_key' => (string) $feature->key,
                'billable_type' => $type,
                'billable_id' => (string) $id,
            ],
        ];

        if ($taxRate = $this->stripe->taxRateId()) {
            $subscriptionData['default_tax_rates'] = [$taxRate];
        }

        $params = [
            'mode' => 'subscription',
            'customer' => $customer->stripe_customer_id,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $this->withSessionPlaceholder($successUrl),
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $checkout->id,
            'subscription_data' => $subscriptionData,
            'integration_identifier' => $this->integrationIdentifier(),
        ];

        if (ModuleConfig::get('billing.collect_tax_id', true)) {
            // With an existing customer, Stripe requires the name to be
            // writable when it collects a tax id.
            $params['tax_id_collection'] = ['enabled' => true];
            $params['customer_update'] = ['name' => 'auto', 'address' => 'auto'];
        }

        try {
            $session = $this->stripe->make()->checkout->sessions->create($params, [
                'idempotency_key' => 'billing-checkout-' . $checkout->id,
            ]);
        } catch (\Throwable $e) {
            $checkout->forceFill(['status' => BillingCheckout::STATUS_FAILED])->save();

            throw $e;
        }

        $checkout->forceFill(['stripe_session_id' => $session->id])->save();

        // Never the URL: it is a bearer link to pay.
        Log::info('billing.checkout_started', ['checkout_id' => $checkout->id, 'feature_id' => $feature->id, 'billable_id' => $id, 'interval' => $interval]);

        return ['url' => (string) $session->url, 'checkout' => $checkout];
    }

    /**
     * The success-page poll: our row for a session id, narrowed to the party
     * first (another party's session is the same 404 as an invented one),
     * fulfilled on the spot when Stripe says it is paid.
     *
     * @throws BillingRefusal 404
     */
    public function status(Model $billable, string $sessionId): BillingCheckout
    {
        $checkout = $this->billables->scope(BillingCheckout::query(), $billable)
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $checkout) {
            throw BillingRefusal::notFound();
        }

        if ($checkout->status === BillingCheckout::STATUS_OPEN) {
            $checkout = $this->fulfilment->fromCheckout($sessionId) ?? $checkout;
        }

        return $checkout->refresh();
    }

    /** The fixed identifier every session carries: a label and 8 letters. */
    public function integrationIdentifier(): string
    {
        $configured = trim((string) ModuleConfig::get('billing.integration_identifier', ''));

        return $configured !== '' ? mb_substr($configured, 0, 100) : 'visns_billing_' . Str::lower(Str::random(8));
    }

    /**
     * An open checkout for the same feature, picked up instead of refused.
     *
     * A buyer who pressed Back from Checkout left a live session behind; the
     * same interval resumes it, another interval expires it first. A session
     * complete but unpaid (a direct debit clearing) is a 409: a second one
     * would charge twice.
     *
     * @return array{url: string, checkout: BillingCheckout}|null
     */
    private function resumeOpen(Model $billable, BillingFeature $feature, string $interval): ?array
    {
        $hours = max(1, (int) ModuleConfig::get('billing.open_checkout_hours', 24));

        $open = $this->billables->scope(BillingCheckout::query(), $billable)
            ->where('feature_id', $feature->id)
            ->where('livemode', $this->stripe->livemode())
            ->where('status', BillingCheckout::STATUS_OPEN)
            ->whereNotNull('stripe_session_id')
            ->where('created_at', '>', now()->subHours($hours))
            ->orderByDesc('id')
            ->get();

        foreach ($open as $checkout) {
            $session = $this->stripe->make()->checkout->sessions->retrieve($checkout->stripe_session_id);

            if ($session->status === 'complete') {
                $this->fulfilment->fromCheckout($session->id);

                if ($this->entitlements->whyFeature($billable, $feature)['entitled']) {
                    throw BillingRefusal::conflict("{$feature->name} is already included.");
                }

                throw BillingRefusal::conflict("A payment for {$feature->name} is still being confirmed.");
            }

            if ($session->status === 'open' && $checkout->interval === $interval && $session->url) {
                return ['url' => (string) $session->url, 'checkout' => $checkout];
            }

            if ($session->status === 'open') {
                $this->stripe->make()->checkout->sessions->expire($session->id);
            }

            $checkout->forceFill(['status' => BillingCheckout::STATUS_EXPIRED])->save();
        }

        return null;
    }

    private function withSessionPlaceholder(string $url): string
    {
        if (str_contains($url, '{CHECKOUT_SESSION_ID}')) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'checkout={CHECKOUT_SESSION_ID}';
    }
}
