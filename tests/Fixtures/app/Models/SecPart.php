<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecPart extends Model
{
    protected $table = 'sec_parts';

    protected $fillable = ['widget_id', 'name'];
}
