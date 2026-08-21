<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The line/staff attachment.
 *
 * A pivot model rather than a bare table because the row carries data of its own
 * (`notify`) and because the visibility check reads it directly - having one
 * class own the table name keeps that read and the relationship in step.
 */
class SmsLineUser extends Pivot
{
    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'notify' => 'boolean',
    ];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.line_user', 'sms_line_user');
    }
}
