<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\SchemaColumns;

/**
 * A canned message body.
 *
 * `client_id` NULL is the house's; an id is one client's own (4.19.0), for a
 * host application that gives its clients lines of their own - see SmsLine.
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

    /**
     * The house's templates. Every template is the house's until the 4.19.0
     * migration has run - see Support\SchemaColumns.
     */
    public function scopeHouse(Builder $query): Builder
    {
        if (! SchemaColumns::has($this->getTable(), 'client_id')) {
            return $query;
        }

        return $query->whereNull($this->qualifyColumn('client_id'));
    }

    /**
     * One client's own templates.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        if (! SchemaColumns::has($this->getTable(), 'client_id')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('client_id'), $clientId);
    }
}
