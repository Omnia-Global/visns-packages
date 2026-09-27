<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecNote extends Model
{
    protected $table = 'sec_notes';

    protected $fillable = ['widget_id', 'body'];
}
