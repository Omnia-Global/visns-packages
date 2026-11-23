<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Contracts\BillingQuantityResolver;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Per-unit billing: the one reading of a quantity and the one writer of it
 * at Stripe.
 *
 * The count is the host's (`billing.hooks.quantity`, a BillingQuantityResolver);
 * without a hook, or for a feature the hook calls flat (null), it is 1.
 * Clamped to 1..MAX: a live subscription always bills at least one unit.
 *
 * sync() makes the party's live Stripe subscription for the feature carry
 * that count. It reads the subscription FRESH from Stripe (never our mirror),
 * changes the item's quantity only when it differs, with prorations (Stripe
 * credits or charges the rest of the period on the next invoice), and NEVER
 * THROWS: Stripe down, no subscription, or a canceled one is an answer, so a
 * caller in the middle of saving the host's own rows is never broken by it.
 */
class Quantities
{
    public const MAX = 10000;

    public function __construct(
        private StripeClientFactory $stripe,
        private Billables $billables,
    ) {
    }

    /** The units this party is billed for this feature (1 for a flat one). */
    public function for(Model $billable, BillingFeature $feature): int
    {
        $resolver = $this->resolver();

        if (! $resolver) {
            return 1;
        }

        $quantity = $resolver->quantity($billable, $feature);

        return $quantity === null ? 1 : max(1, min(self::MAX, $quantity));
    }

    /** Does the host count units for this feature (true) or bill it flat? */
    public function perUnit(Model $billable, BillingFeature $feature): bool
    {
        return $this->resolver()?->quantity($billable, $feature) !== null;
    }

    /**
     * Make the live Stripe subscription carry the host's count.
     *
     * @return array{ok: bool, changed: bool, quantity: int, from: ?int, reason: ?string}
     *   reason: none (nothing live to change), flat, unconfigured, stripe (a
     *   Stripe error, logged without detail beyond its class)
     */
    public function sync(Model $billable, BillingFeature $feature, bool $dryRun = false): array
    {
        $answer = fn (bool $ok, bool $changed, int $quantity, ?int $from, ?string $reason) => compact('ok', 'changed', 'quantity', 'from', 'reason');

        if (! $this->perUnit($billable, $feature)) {
            return $answer(true, false, 1, null, 'flat');
        }

        $quantity = $this->for($billable, $feature);

        if (! $this->stripe->configured()) {
            return $answer(true, false, $quantity, null, 'unconfigured');
        }

        $row = $this->billables->scope(BillingSubscription::query(), $billable)
            ->where('feature_id', $feature->id)
            ->where('livemode', $this->stripe->livemode())
            ->whereNotIn('status', ['canceled', 'incomplete_expired'])
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return $answer(true, false, $quantity, null, 'none');
        }

        try {
            $client = $this->stripe->make();
            $subscription = $client->subscriptions->retrieve($row->stripe_subscription_id)->toArray();

            if (in_array($subscription['status'] ?? null, ['canceled', 'incomplete_expired'], true)) {
                return $answer(true, false, $quantity, null, 'none');
            }

            $item = $subscription['items']['data'][0] ?? null;

            if (! is_array($item) || empty($item['id'])) {
                return $answer(false, false, $quantity, null, 'stripe');
            }

            $from = isset($item['quantity']) ? (int) $item['quantity'] : 1;

            if ($from === $quantity) {
                return $answer(true, false, $quantity, $from, null);
            }

            if (! $dryRun) {
                $client->subscriptionItems->update($item['id'], [
                    'quantity' => $quantity,
                    'proration_behavior' => 'create_prorations',
                ], [
                    'idempotency_key' => 'billing-quantity-' . $row->id . '-' . $from . '-' . $quantity . '-' . now()->format('YmdHi'),
                ]);
            }

            Log::info('billing.quantity_changed', [
                'subscription_id' => $row->id,
                'feature_id' => $feature->id,
                'from' => $from,
                'to' => $quantity,
                'dry_run' => $dryRun,
            ]);

            return $answer(true, ! $dryRun, $quantity, $from, null);
        } catch (\Throwable $e) {
            Log::warning('billing.quantity_failed', [
                'subscription_id' => $row->id,
                'feature_id' => $feature->id,
                'error' => class_basename($e),
            ]);

            return $answer(false, false, $quantity, null, 'stripe');
        }
    }

    private function resolver(): ?BillingQuantityResolver
    {
        $configured = ModuleConfig::get('billing.hooks.quantity');

        if (! $configured) {
            return null;
        }

        $resolver = is_string($configured) ? app($configured) : $configured;

        return $resolver instanceof BillingQuantityResolver ? $resolver : null;
    }
}
