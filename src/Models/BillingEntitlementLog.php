<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One row each time a billable party gains or loses a feature.
 *
 * Append-only, written by Entitlements::record() alone.
 */
class BillingEntitlementLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'billable_id' => 'integer',
        'feature_id' => 'integer',
        'at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.entitlement_log', 'billing_entitlement_log');
    }
}
