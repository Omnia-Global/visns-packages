<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One hosted Checkout we started.
 *
 * Lets the success page poll our own table, and refuses a second Subscribe
 * while one is open. Written by Checkouts (create) and Fulfilment / the
 * checkout webhooks (state). Nothing is fillable.
 */
class BillingCheckout extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['*'];

    protected $casts = [
        'billable_id' => 'integer',
        'feature_id' => 'integer',
        'livemode' => 'boolean',
        'created_by' => 'integer',
        'fulfilled_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.checkouts', 'billing_checkouts');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(BillingFeature::class, 'feature_id');
    }
}
