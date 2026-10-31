<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingRefusal;
use Visnsstudio\VisnsPackages\Models\BillingComp;
use Visnsstudio\VisnsPackages\Models\BillingFeature;

/**
 * The one writer of `billing_comps`: a feature given without charge.
 *
 * Append-only. Ending a comp stamps `ends_at`; nothing deletes a row, so the
 * table is the record of who was given what, by whom and why.
 */
class Comps
{
    public function __construct(
        private Billables $billables,
        private Entitlements $entitlements,
    ) {
    }

    /** @throws BillingRefusal */
    public function grant(
        Model $billable,
        BillingFeature $feature,
        string $reason,
        ?CarbonInterface $endsAt = null,
        ?int $grantedBy = null,
        string $source = BillingComp::SOURCE_STAFF,
    ): BillingComp {
        $reason = trim($reason);

        if ($reason === '') {
            throw new BillingRefusal('Say why this is given without charge.');
        }

        if ($endsAt !== null && ! $endsAt->isFuture()) {
            throw new BillingRefusal('The end date must be in the future.');
        }

        $why = $this->entitlements->whyFeature($billable, $feature);

        if ($why['source'] === 'subscription') {
            throw BillingRefusal::conflict("{$feature->name} is already paid for through Stripe.");
        }

        if ($why['source'] === 'comp') {
            throw BillingRefusal::conflict("{$feature->name} is already given without charge. End that first.");
        }

        [$type, $id] = $this->billables->morph($billable);

        $comp = new BillingComp();
        $comp->forceFill([
            'billable_type' => $type,
            'billable_id' => $id,
            'feature_id' => $feature->id,
            'reason' => mb_substr($reason, 0, 500),
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'granted_by' => $grantedBy,
            'source' => in_array($source, [BillingComp::SOURCE_STAFF, BillingComp::SOURCE_MIGRATED], true) ? $source : BillingComp::SOURCE_STAFF,
        ])->save();

        Log::info('billing.comp_granted', ['comp_id' => $comp->id, 'feature_id' => $feature->id, 'billable_id' => $id, 'by' => $grantedBy]);

        $this->entitlements->record($type, $id, $feature, 'comp:granted');

        return $comp;
    }

    /** @throws BillingRefusal */
    public function end(BillingComp $comp, ?int $endedBy = null): BillingComp
    {
        if ($comp->ends_at !== null && ! $comp->ends_at->isFuture()) {
            throw BillingRefusal::conflict('That comp has already ended.');
        }

        $comp->forceFill(['ends_at' => now(), 'ended_by' => $endedBy])->save();

        Log::info('billing.comp_ended', ['comp_id' => $comp->id, 'feature_id' => $comp->feature_id, 'by' => $endedBy]);

        if ($feature = $comp->feature) {
            $this->entitlements->record($comp->billable_type, (int) $comp->billable_id, $feature, 'comp:ended');
        }

        return $comp;
    }
}
