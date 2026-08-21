<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * A canned message body.
 */
class SmsTemplate extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.templates', 'sms_templates');
    }
}
