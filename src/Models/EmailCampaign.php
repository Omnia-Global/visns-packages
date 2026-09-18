<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One email campaign: a Resend Broadcast once it is sent or scheduled.
 *
 * `html` is the rendered snapshot taken at send and is the record of what went
 * out; `content` is the editor's blocks. The send stamps, the broadcast id and
 * `status` are written by `EmailCampaignSender` alone and are not fillable.
 */
class EmailCampaign extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name', 'subject', 'preview_text', 'from_name', 'from_email', 'reply_to', 'list_id', 'content', 'user_id',
    ];

    protected $casts = [
        'content' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('email_campaigns.tables.campaigns', 'email_campaigns');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ModuleConfig::userModel('email_campaigns'), 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailCampaignEvent::class, 'campaign_id');
    }

    /** Can the blocks and settings still change? */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CANCELLED, self::STATUS_FAILED], true);
    }
}
