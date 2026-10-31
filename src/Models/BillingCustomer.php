<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Which Stripe Customer a billable party is, per Stripe mode.
 *
 * The one writer is BillingCustomers::ensure() (and `customer.updated` for the
 * two mirrored fields). Nothing is fillable.
 */
class BillingCustomer extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'billable_id' => 'integer',
        'livemode' => 'boolean',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.customers', 'billing_customers');
    }
}
