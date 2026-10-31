<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Our mirror of one Stripe Subscription (one per feature).
 *
 * Written ONLY by SubscriptionSync, from a fresh retrieve, when a webhook or
 * the success-page poll says something changed. Nothing is fillable.
 */
class BillingSubscription extends Model
{
    /** The statuses that carry access. past_due: Stripe is still retrying. */
    public const ENTITLED_STATUSES = ['active', 'trialing', 'past_due'];

    protected $guarded = ['*'];

    protected $casts = [
        'billable_id' => 'integer',
        'feature_id' => 'integer',
        'livemode' => 'boolean',
        'current_period_end' => 'datetime',
        'cancel_at' => 'datetime',
        'canceled_at' => 'datetime',
        'first_paid_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.subscriptions', 'billing_subscriptions');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(BillingFeature::class, 'feature_id');
    }

    /** Does this row carry access right now? The subscription half of the rule. */
    public function entitles(): bool
    {
        return in_array($this->status, self::ENTITLED_STATUSES, true)
            && $this->first_paid_at !== null
            && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }
}
