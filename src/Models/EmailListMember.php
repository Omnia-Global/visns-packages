<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One person on one list. `status` is the sync state against Resend:
 * pending -> synced; removing -> taken out of the segment, then deleted;
 * failed carries its reason in `error`.
 */
class EmailListMember extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_REMOVING = 'removing';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'list_id', 'email', 'first_name', 'last_name', 'company', 'contact_key',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('email_campaigns.tables.list_members', 'email_list_members');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower(trim((string) $value));
    }
}
