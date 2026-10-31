<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The Stripe webhook ledger: one row per event id, never a payload.
 *
 * Written by WebhookProcessor alone. Nothing is fillable.
 */
class BillingEvent extends Model
{
    public const OUTCOME_APPLIED = 'applied';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_IGNORED = 'ignored';

    public const OUTCOME_FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'livemode' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'object_ids' => 'array',
        'deliveries' => 'integer',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.events', 'billing_events');
    }
}
