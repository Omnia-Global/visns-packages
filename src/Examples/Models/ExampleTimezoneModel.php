<?php

namespace Visnsstudio\VisnsPackages\Examples\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Traits\TimezoneAware;

class ExampleTimezoneModel extends Model
{
    use TimezoneAware;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'published_at' => 'datetime',
        'due_date' => 'date',
    ];

    /**
     * Optional: Define specific fields that should be timezone-aware.
     * If not defined, all datetime/date fields from $casts will be used.
     *
     * @var array
     */
    protected $timezoneAwareFields = [
        'created_at',
        'updated_at',
        'published_at',
        'due_date',
    ];
}
