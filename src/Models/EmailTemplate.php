<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/** A saved block layout a new campaign can start from. */
class EmailTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'content', 'user_id'];

    protected $casts = ['content' => 'array'];

    public function getTable()
    {
        return ModuleConfig::get('email_campaigns.tables.templates', 'email_templates');
    }
}
