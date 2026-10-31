<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One sellable feature in the billing catalogue.
 *
 * Staff set the name and the ex-GST amounts here; BillingCatalogue::sync()
 * pushes them to Stripe as one Product with a monthly and an optional annual
 * Price. The Stripe ids and `synced_at` are written by that sync alone and are
 * not fillable. `key` is fixed at create: the host's feature checks name it.
 */
class BillingFeature extends Model
{
    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public const INTERVALS = [self::INTERVAL_MONTH, self::INTERVAL_YEAR];

    protected $fillable = [
        'name', 'blurb', 'monthly_amount_cents', 'annual_amount_cents', 'available', 'sort_order',
    ];

    protected $casts = [
        'monthly_amount_cents' => 'integer',
        'annual_amount_cents' => 'integer',
        'available' => 'boolean',
        'sort_order' => 'integer',
        'stripe_livemode' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.features', 'billing_features');
    }

    /** The ex-GST amount for an interval, or null when it is not offered. */
    public function amountFor(string $interval): ?int
    {
        return $interval === self::INTERVAL_YEAR ? $this->annual_amount_cents : $this->monthly_amount_cents;
    }

    /** The Stripe Price for an interval, or null before a sync. */
    public function priceIdFor(string $interval): ?string
    {
        return $interval === self::INTERVAL_YEAR ? $this->stripe_price_annual_id : $this->stripe_price_monthly_id;
    }

    /** @return string[] the intervals a buyer can choose today */
    public function intervals(): array
    {
        return array_values(array_filter(
            self::INTERVALS,
            fn ($interval) => $this->amountFor($interval) !== null
        ));
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
