<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Stripe\Subscription;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;

/**
 * The one writer of `billing_subscriptions`.
 *
 * Always from a FRESH Stripe Subscription (a retrieve, or one expanded on a
 * retrieved Checkout Session), never from an event's snapshot: Stripe does not
 * deliver events in order, so the newest state is read and upserted, which
 * makes every handler order-independent and idempotent.
 *
 * Who and what are found through Stripe's object graph: our row for the
 * subscription id, else the Checkout we started, else the Stripe Customer →
 * our mapping, and the Price → Product → our catalogue. The subscription's
 * metadata is read only when the graph cannot answer.
 */
class SubscriptionSync
{
    public function __construct(
        private StripeClientFactory $stripe,
        private Billables $billables,
        private BillingCustomers $customers,
        private Entitlements $entitlements,
    ) {
    }

    /** Retrieve a subscription by id and mirror it. */
    public function byId(string $subscriptionId, bool $settled = false): ?BillingSubscription
    {
        $subscription = $this->stripe->make()->subscriptions->retrieve($subscriptionId);

        return $this->apply($subscription, $settled);
    }

    /**
     * Mirror one subscription.
     *
     * `$settled` says its first payment has gone through (a paid Checkout, or
     * a paid invoice with money on it). It is stamped once and never cleared.
     *
     * @return BillingSubscription|null null when the graph cannot say whose it is
     */
    public function apply(Subscription $subscription, bool $settled = false, ?BillingCheckout $hint = null): ?BillingSubscription
    {
        $data = $subscription->toArray();
        $id = (string) ($data['id'] ?? '');

        if ($id === '') {
            return null;
        }

        $row = BillingSubscription::query()->where('stripe_subscription_id', $id)->first();
        $customerId = is_array($data['customer'] ?? null) ? ($data['customer']['id'] ?? null) : ($data['customer'] ?? null);
        $item = $data['items']['data'][0] ?? [];
        $price = $item['price'] ?? [];
        $priceId = is_array($price) ? ($price['id'] ?? null) : $price;
        $productId = is_array($price) ? (is_array($price['product'] ?? null) ? ($price['product']['id'] ?? null) : ($price['product'] ?? null)) : null;

        [$type, $billableId] = $this->whose($row, $hint, $customerId, $data['metadata'] ?? []);
        $feature = $this->which($row, $hint, $priceId, $productId, $data['metadata'] ?? []);

        if ($type === null || $feature === null) {
            Log::warning('billing.subscription_unmapped', [
                'stripe_subscription_id' => $id,
                'stripe_customer_id' => $customerId,
                'had_billable' => $type !== null,
                'had_feature' => $feature !== null,
            ]);

            return null;
        }

        // Basil and later carry the period on the item; older shapes on the subscription.
        $periodEnd = $item['current_period_end'] ?? $data['current_period_end'] ?? null;
        $cancelAt = $data['cancel_at'] ?? (! empty($data['cancel_at_period_end']) ? $periodEnd : null);
        $latestInvoice = $data['latest_invoice'] ?? null;

        $row ??= new BillingSubscription();
        $before = $row->exists ? $row->status : null;

        $row->forceFill([
            'billable_type' => $type,
            'billable_id' => $billableId,
            'feature_id' => $feature->id,
            'stripe_subscription_id' => $id,
            'stripe_customer_id' => $customerId,
            'stripe_price_id' => $priceId,
            'interval' => $price['recurring']['interval'] ?? $row->interval,
            'status' => (string) ($data['status'] ?? 'incomplete'),
            'livemode' => (bool) ($data['livemode'] ?? false),
            'current_period_end' => $this->time($periodEnd),
            'cancel_at' => $this->time($cancelAt),
            'canceled_at' => $this->time($data['canceled_at'] ?? null),
            'latest_invoice_id' => is_array($latestInvoice) ? ($latestInvoice['id'] ?? null) : $latestInvoice,
            'last_event_at' => now(),
        ]);

        if ($settled && $row->first_paid_at === null) {
            $row->first_paid_at = now();
        }

        try {
            $row->save();
        } catch (QueryException $e) {
            // The webhook and the success-page poll mirrored it at once.
            $winner = BillingSubscription::query()->where('stripe_subscription_id', $id)->first();

            if (! $winner) {
                throw $e;
            }

            return $this->apply($subscription, $settled, $hint);
        }

        if ($before !== $row->status) {
            Log::info('billing.subscription_status', ['subscription_id' => $row->id, 'from' => $before, 'to' => $row->status]);
        }

        $this->entitlements->record($type, $billableId, $feature, 'subscription:' . $row->status);

        return $row;
    }

    /** @return array{0: ?string, 1: ?int} */
    private function whose(?BillingSubscription $row, ?BillingCheckout $hint, ?string $customerId, array $metadata): array
    {
        if ($row) {
            return [$row->billable_type, (int) $row->billable_id];
        }

        if ($hint) {
            return [$hint->billable_type, (int) $hint->billable_id];
        }

        if ($mapping = $this->customers->byStripeId($customerId)) {
            return [$mapping->billable_type, (int) $mapping->billable_id];
        }

        // Metadata is the fallback, and only for a party the host recognises.
        $type = $metadata['billable_type'] ?? null;
        $id = $metadata['billable_id'] ?? null;

        if ($type && is_numeric($id) && $this->billables->hasResolver() && $this->billables->find($type, $id)) {
            Log::warning('billing.resolved_by_metadata', ['stripe_customer_id' => $customerId]);

            return [(string) $type, (int) $id];
        }

        return [null, null];
    }

    private function which(?BillingSubscription $row, ?BillingCheckout $hint, ?string $priceId, ?string $productId, array $metadata): ?BillingFeature
    {
        if ($row && $row->feature_id && ($feature = BillingFeature::query()->find($row->feature_id))) {
            return $feature;
        }

        if ($hint && ($feature = BillingFeature::query()->find($hint->feature_id))) {
            return $feature;
        }

        if ($priceId) {
            $feature = BillingFeature::query()
                ->where('stripe_price_monthly_id', $priceId)
                ->orWhere('stripe_price_annual_id', $priceId)
                ->first();

            if ($feature) {
                return $feature;
            }
        }

        // An archived Price is still on its Product.
        if ($productId && ($feature = BillingFeature::query()->where('stripe_product_id', $productId)->first())) {
            return $feature;
        }

        if (! empty($metadata['billing_feature_key'])) {
            return BillingFeature::query()->where('key', (string) $metadata['billing_feature_key'])->first();
        }

        return null;
    }

    private function time($epoch): ?\Illuminate\Support\Carbon
    {
        return is_numeric($epoch) && (int) $epoch > 0 ? \Illuminate\Support\Carbon::createFromTimestamp((int) $epoch) : null;
    }
}
