<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

/**
 * Test-only dynamic entity for the request-input hardening tests (4.17.2).
 * `purge()` and `promoteOwner()` stand in for any public zero-argument method
 * a real model has: a request must never be able to call them by naming them
 * as a relation, a file field or a sort key.
 */
class SecWidget extends Model
{
    use HasRelationshipSorting;

    public static int $purged = 0;

    protected $table = 'sec_widgets';

    protected $fillable = ['name', 'owner_id', 'details'];

    protected $casts = ['details' => 'array'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(SecOwner::class, 'owner_id');
    }

    // Untyped on purpose: recognised by its body.
    public function parts()
    {
        return $this->hasMany(SecPart::class, 'widget_id');
    }

    public function note()
    {
        return $this->hasOne(SecNote::class, 'widget_id');
    }

    public function purge()
    {
        static::$purged++;
        SecWidget::query()->delete();

        return null;
    }

    public function promoteOwner()
    {
        SecOwner::query()->update(['is_admin' => true]);
    }

    public function loadableRelations()
    {
        return ['owner'];
    }

    public function validationRules($context = 'store', $requestData = null)
    {
        return [];
    }
}
