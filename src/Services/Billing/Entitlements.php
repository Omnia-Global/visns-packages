<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Events\BillingEntitlementChanged;
use Visnsstudio\VisnsPackages\Models\BillingComp;
use Visnsstudio\VisnsPackages\Models\BillingEntitlementLog;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;

/**
 * The one reading of "does this party have this feature".
 *
 * Entitled while EITHER
 *  - a mirrored subscription in the current Stripe mode is active, trialing or
 *    past_due, its period has not ended and its first payment settled
 *    (BillingSubscription::entitles()); OR
 *  - a comp has started and not ended (BillingComp::isCurrent()).
 *
 * past_due keeps access because Stripe is still retrying; canceled and unpaid
 * end it. Nothing else in the package or the host decides this.
 */
class Entitlements
{
    public function __construct(
        private Billables $billables,
        private StripeClientFactory $stripe,
    ) {
    }

    public function has(Model $billable, string $featureKey): bool
    {
        return $this->why($billable, $featureKey)['entitled'];
    }

    /**
     * Why a party has a feature or does not, for the screen.
     *
     * @return array{entitled: bool, source: ?string, status: ?string, interval: ?string, until: ?string, cancel_at: ?string, comp_reason: ?string, comp_ends_at: ?string}
     */
    public function why(Model $billable, string $featureKey): array
    {
        $feature = BillingFeature::query()->where('key', $featureKey)->first();

        return $feature ? $this->whyFeature($billable, $feature) : $this->answer(false);
    }

    /** @return array<string, array> feature key => why(), every catalogue feature */
    public function forBillable(Model $billable): array
    {
        $out = [];

        foreach (BillingFeature::query()->ordered()->get() as $feature) {
            $out[$feature->key] = $this->whyFeature($billable, $feature);
        }

        return $out;
    }

    public function whyFeature(Model $billable, BillingFeature $feature): array
    {
        [$type, $id] = $this->billables->morph($billable);

        return $this->whyFor($type, $id, $feature);
    }

    /** The same reading on a stored morph pair, with no host model loaded. */
    public function whyFor(string $billableType, int $billableId, BillingFeature $feature): array
    {
        $subscriptions = BillingSubscription::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('feature_id', $feature->id)
            ->where('livemode', $this->stripe->livemode())
            ->orderByDesc('id')
            ->get();

        $paying = $subscriptions->first(fn (BillingSubscription $s) => $s->entitles());

        if ($paying) {
            return $this->answer(true, 'subscription', $paying);
        }

        $comp = BillingComp::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('feature_id', $feature->id)
            ->current()
            ->orderByDesc('id')
            ->first();

        if ($comp) {
            return $this->answer(true, 'comp', null, $comp);
        }

        // Not entitled: still say what the newest subscription is doing, so
        // the screen can tell "never subscribed" from "payment failed".
        return $this->answer(false, null, $subscriptions->first());
    }

    /**
     * Write an entitlement log row when a party's state for a feature differs
     * from the last row. Called by every writer of a subscription or a comp.
     */
    public function record(string $billableType, int $billableId, BillingFeature $feature, string $reason): void
    {
        $entitled = $this->whyFor($billableType, $billableId, $feature)['entitled'];

        $last = BillingEntitlementLog::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('feature_id', $feature->id)
            ->orderByDesc('id')
            ->first();

        $state = $entitled ? 'on' : 'off';

        // No row yet reads as "off": a first refusal is not a change.
        if (($last?->state ?? 'off') === $state) {
            return;
        }

        (new BillingEntitlementLog())->forceFill([
            'billable_type' => $billableType,
            'billable_id' => $billableId,
            'feature_id' => $feature->id,
            'state' => $state,
            'reason' => mb_substr($reason, 0, 64),
            'at' => now(),
        ])->save();

        BillingEntitlementChanged::dispatch($billableType, $billableId, (string) $feature->key, $entitled, $reason);
    }

    private function answer(bool $entitled, ?string $source = null, ?BillingSubscription $subscription = null, ?BillingComp $comp = null): array
    {
        return [
            'entitled' => $entitled,
            'source' => $source,
            'status' => $comp ? 'comp' : $subscription?->status,
            'interval' => $subscription?->interval,
            'until' => $subscription?->current_period_end?->toIso8601String(),
            'cancel_at' => $subscription?->cancel_at?->toIso8601String(),
            'comp_reason' => $comp?->reason,
            'comp_ends_at' => $comp?->ends_at?->toIso8601String(),
        ];
    }
}
