<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/** One Resend webhook event about one recipient of one campaign. */
class EmailCampaignEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('email_campaigns.tables.events', 'email_campaign_events');
    }
}
