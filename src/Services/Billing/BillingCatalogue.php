<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Pushes the catalogue to Stripe: the CRM is the authority, Stripe follows.
 *
 * One Product per feature, one monthly Price and an optional annual Price on
 * it. A Price's amount can never be edited, so a changed amount creates a NEW
 * Price (its lookup key transferred) and archives the old one; subscribers on
 * the old Price keep paying it until they change plan. Writes the Stripe ids
 * and `synced_at` and nothing else.
 */
class BillingCatalogue
{
    public function __construct(private StripeClientFactory $stripe)
    {
    }

    /** @return array<string, string> what was done, for the screen: product, monthly, annual */
    public function sync(BillingFeature $feature): array
    {
        $client = $this->stripe->make();
        $livemode = $this->stripe->livemode();
        $done = [];

        // Ids from the other Stripe mode mean nothing to this account.
        $sameMode = $feature->stripe_livemode === $livemode;
        $productId = $sameMode ? $feature->stripe_product_id : null;
        $monthlyId = $sameMode ? $feature->stripe_price_monthly_id : null;
        $annualId = $sameMode ? $feature->stripe_price_annual_id : null;

        $productParams = array_filter([
            'name' => $feature->name,
            'description' => trim((string) $feature->blurb) ?: null,
            'active' => true,
        ], fn ($v) => $v !== null);

        if ($productId) {
            $client->products->update($productId, $productParams + ['metadata' => ['billing_feature_key' => $feature->key]]);
            $done['product'] = 'updated';
        } else {
            $product = $client->products->create(
                $productParams + ['metadata' => ['billing_feature_key' => $feature->key, 'billing_feature_id' => (string) $feature->id]],
                ['idempotency_key' => 'billing-product-' . $feature->id . '-' . ($livemode ? 'live' : 'test')]
            );
            $productId = $product->id;
            $done['product'] = 'created';
        }

        [$monthlyId, $done['monthly']] = $this->price($client, $feature, $productId, BillingFeature::INTERVAL_MONTH, $monthlyId);
        [$annualId, $done['annual']] = $this->price($client, $feature, $productId, BillingFeature::INTERVAL_YEAR, $annualId);

        $feature->forceFill([
            'stripe_product_id' => $productId,
            'stripe_price_monthly_id' => $monthlyId,
            'stripe_price_annual_id' => $annualId,
            'stripe_livemode' => $livemode,
            'synced_at' => now(),
        ])->save();

        Log::info('billing.catalogue_synced', ['feature_id' => $feature->id, 'product' => $productId] + $done);

        return $done;
    }

    /**
     * Make one interval's Price match the feature.
     *
     * @return array{0: ?string, 1: string} the price id to keep, and what was done
     */
    private function price(StripeClient $client, BillingFeature $feature, string $productId, string $interval, ?string $currentId): array
    {
        $amount = $feature->amountFor($interval);
        $currency = strtolower((string) ($feature->currency ?: ModuleConfig::get('billing.currency', 'aud')));

        $current = null;

        if ($currentId) {
            try {
                $current = $client->prices->retrieve($currentId);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Deleted in the Dashboard, or never in this account.
                $current = null;
            }
        }

        if ($amount === null) {
            // No longer offered: archive what was there.
            if ($current && $current->active) {
                $client->prices->update($current->id, ['active' => false]);

                return [null, 'archived'];
            }

            return [null, 'none'];
        }

        if ($current
            && $current->active
            && (int) $current->unit_amount === (int) $amount
            && strtolower((string) $current->currency) === $currency
            && ($current->recurring->interval ?? null) === $interval
            && $current->product === $productId) {
            return [$current->id, 'unchanged'];
        }

        $price = $client->prices->create([
            'product' => $productId,
            'currency' => $currency,
            'unit_amount' => $amount,
            'recurring' => ['interval' => $interval],
            // GST is a separate, exclusive Tax Rate added at Checkout.
            'tax_behavior' => 'exclusive',
            'lookup_key' => $this->lookupKey($feature, $interval),
            'transfer_lookup_key' => true,
            'metadata' => ['billing_feature_key' => $feature->key],
        ], [
            'idempotency_key' => 'billing-price-' . $feature->id . '-' . $interval . '-' . $currency . '-' . $amount . '-' . ($current?->id ?? 'none'),
        ]);

        if ($current && $current->active) {
            $client->prices->update($current->id, ['active' => false]);

            return [$price->id, 'replaced'];
        }

        return [$price->id, 'created'];
    }

    private function lookupKey(BillingFeature $feature, string $interval): string
    {
        $prefix = trim((string) ModuleConfig::get('billing.lookup_key_prefix', 'visns'), '_');

        return mb_substr(($prefix !== '' ? $prefix . '_' : '') . $feature->key . '_' . ($interval === BillingFeature::INTERVAL_YEAR ? 'annual' : 'monthly'), 0, 200);
    }
}
