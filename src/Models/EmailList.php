<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * A list of people a campaign can go to, mirrored into one Resend segment.
 *
 * `kind` is `crm` (the host's contact source, re-read when the list is
 * refreshed) or `import` (a CSV, fixed at upload). `resend_segment_id` and the
 * sync stamps are written by `EmailListSync` alone.
 */
class EmailList extends Model
{
    use SoftDeletes;

    public const KIND_CRM = 'crm';

    public const KIND_IMPORT = 'import';

    protected $fillable = ['name', 'description', 'kind', 'filters', 'user_id'];

    protected $casts = [
        'filters' => 'array',
        'synced_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('email_campaigns.tables.lists', 'email_lists');
    }

    public function members(): HasMany
    {
        return $this->hasMany(EmailListMember::class, 'list_id');
    }

    /** Members a campaign sends to: synced, and not unsubscribed. */
    public function activeMembers(): HasMany
    {
        return $this->members()->whereIn('status', [EmailListMember::STATUS_PENDING, EmailListMember::STATUS_SYNCED])
            ->whereNull('unsubscribed_at');
    }

    /** Is anything still waiting to reach Resend? */
    public function isSyncing(): bool
    {
        return $this->resend_segment_id === null
            || $this->members()->whereIn('status', [EmailListMember::STATUS_PENDING, EmailListMember::STATUS_REMOVING])->exists();
    }
}
