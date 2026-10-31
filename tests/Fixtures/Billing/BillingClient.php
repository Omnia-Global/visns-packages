<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Billing;

use Illuminate\Database\Eloquent\Model;

/** The host's client model, as far as billing needs one. */
class BillingClient extends Model
{
    protected $table = 'billing_test_clients';

    protected $guarded = [];

    public function getMorphClass()
    {
        return 'client';
    }
}
