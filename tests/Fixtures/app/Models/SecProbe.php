<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Test-only model for RelationGuard's unit tests: one method per case. */
class SecProbe extends Model
{
    use SoftDeletes;

    public static int $calls = 0;

    protected $table = 'sec_probes';

    /** Listed, so allowed by the allowlist. */
    protected $filterableRelations = ['listedByProperty'];

    public function typed(): HasMany
    {
        static::$calls++;

        return $this->hasMany(SecPart::class, 'widget_id');
    }

    public function nullableTyped(): ?\Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        static::$calls++;

        return $this->belongsTo(SecOwner::class, 'owner_id');
    }

    public function untyped()
    {
        static::$calls++;
        $relation = $this->morphMany(SecPart::class, 'partable');

        return $relation;
    }

    public function listedByProperty()
    {
        static::$calls++;

        return null;
    }

    public function sideEffect()
    {
        static::$calls++;
    }

    public function commentedOut()
    {
        static::$calls++;
        // return $this->hasMany(SecPart::class);
        /* $this->belongsTo(SecOwner::class) */
        return null;
    }

    public function wrongType(): array
    {
        static::$calls++;

        return [$this->hasMany(SecPart::class)];
    }

    public function needsArgument($x)
    {
        static::$calls++;

        return $this->hasMany(SecPart::class);
    }

    public static function staticOne()
    {
        static::$calls++;

        return null;
    }

    protected function hidden()
    {
        static::$calls++;

        return $this->hasMany(SecPart::class);
    }

    public function loadableRelations()
    {
        return ['listedByLoadable:id,name', 'typed.owner'];
    }

    public function listedByLoadable()
    {
        static::$calls++;

        return null;
    }
}
