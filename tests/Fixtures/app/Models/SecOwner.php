<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Test-only related row; `is_admin` is deliberately NOT fillable. */
class SecOwner extends Model
{
    protected $table = 'sec_owners';

    protected $fillable = ['name'];

    public function widgets()
    {
        return $this->hasMany(SecWidget::class, 'owner_id');
    }
}
