<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Test-only record carrying a `slug`, for the `{slug}` URL token (4.18.0). */
class SecClient extends Model
{
    protected $table = 'sec_clients';

    protected $fillable = ['name', 'slug'];
}
