<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * A feature given without charge, by staff or by a data migration.
 *
 * Append-only: ending a comp stamps `ends_at`, nothing deletes one. Written by
 * Comps alone. Nothing is fillable.
 */
class BillingComp extends Model
{
    public const SOURCE_STAFF = 'staff';

    public const SOURCE_MIGRATED = 'migrated';

    protected $guarded = ['*'];

    protected $casts = [
        'billable_id' => 'integer',
        'feature_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'granted_by' => 'integer',
        'ended_by' => 'integer',
    ];

    public function getTable()
    {
        return ModuleConfig::get('billing.tables.comps', 'billing_comps');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(BillingFeature::class, 'feature_id');
    }

    /** Started and not ended. The comp half of the rule. */
    public function isCurrent(): bool
    {
        return $this->starts_at !== null
            && ! $this->starts_at->isFuture()
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function scopeCurrent($query)
    {
        $now = now();

        return $query->where('starts_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }
}
