<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One bulk send.
 *
 * The five statuses are the whole state machine and the controller is the only
 * thing that moves between them by hand; the sender moves `sending` to
 * `completed` (nothing left to do) and to `paused` (nothing can be sent). Every
 * illegal transition is refused with a sentence rather than silently ignored -
 * a campaign that answered 200 to "start" and did not start would be the worst
 * available behaviour on a screen whose whole job is to say what is happening.
 *
 * The four counters are denormalised for the list screen (see the migration).
 * `syncCounts()` recomputes them from the recipients and is called at the end
 * of every sender run: a counter that nothing ever reconciles is a counter that
 * drifts, and this one is read as progress by somebody deciding whether to
 * pause.
 */
class SmsCampaign extends Model
{
    /** Built, not started. The only status a campaign may be deleted in. */
    public const STATUS_DRAFT = 'draft';

    /** The scheduled command is working through it. */
    public const STATUS_SENDING = 'sending';

    /** Stopped by a person, or by the sender because nothing can be sent. */
    public const STATUS_PAUSED = 'paused';

    /** No pending recipients left. */
    public const STATUS_COMPLETED = 'completed';

    /** Abandoned; the pending recipients were marked `skipped`. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The statuses a campaign can still leave under its own steam. Used for
     * isActive() and by nothing that authorises anything.
     */
    public const OPEN_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENDING,
        self::STATUS_PAUSED,
    ];

    protected $guarded = [];

    protected $casts = [
        'total' => 'integer',
        'sent' => 'integer',
        'failed' => 'integer',
        'skipped' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.campaigns', 'sms_campaigns');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function line()
    {
        return $this->belongsTo(SmsLine::class, 'line_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(ModuleConfig::userModel('messaging'), 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function recipients()
    {
        return $this->hasMany(SmsCampaignRecipient::class, 'campaign_id');
    }

    /**
     * Is this campaign still going to text anybody?
     *
     * Draft, sending and paused all answer yes - a paused campaign is one press
     * away from resuming, and a draft is one press away from starting. Completed
     * and cancelled answer no. Deliberately NOT "is it sending right now": that
     * question has a status of its own and reading `isActive()` as though it
     * meant that is how a paused campaign gets quietly deleted.
     */
    public function isActive(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * The five numbers the progress bar is drawn from.
     *
     * `pending` is derived rather than stored: it is whatever is left, and a
     * fifth counter would be a fifth thing that could disagree with the other
     * four. Floored at zero, because a stored counter that has drifted upwards
     * must not produce a negative on screen.
     *
     * @return array{total: int, pending: int, sent: int, failed: int, skipped: int}
     */
    public function counts(): array
    {
        $total = max(0, (int) $this->total);
        $done = (int) $this->sent + (int) $this->failed + (int) $this->skipped;

        return [
            'total' => $total,
            'pending' => max(0, $total - $done),
            'sent' => (int) $this->sent,
            'failed' => (int) $this->failed,
            'skipped' => (int) $this->skipped,
        ];
    }

    /**
     * Recompute the four counters from the recipients.
     *
     * ONE grouped query, whatever the size of the list - which is what makes it
     * affordable at the end of every sender run. `forceFill` + save rather than
     * four `increment()`s scattered through the loop: the loop updates its own
     * copy as it goes for the screen's benefit, and this is the reconciliation
     * that makes those updates safe to be approximate.
     *
     * Portable: `COUNT(*) ... GROUP BY status` is the same statement on MySQL
     * and SQLite, which is the rule everywhere in this package.
     */
    public function syncCounts(): self
    {
        $rows = $this->recipients()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $this->forceFill([
            'total' => (int) $rows->sum(),
            'sent' => (int) ($rows[SmsCampaignRecipient::STATUS_SENT] ?? 0),
            'failed' => (int) ($rows[SmsCampaignRecipient::STATUS_FAILED] ?? 0),
            'skipped' => (int) ($rows[SmsCampaignRecipient::STATUS_SKIPPED] ?? 0),
        ])->save();

        return $this;
    }

    /**
     * How many recipients are still waiting, straight from the table.
     *
     * Used by the sender, which must not trust a denormalised counter to decide
     * whether a campaign is finished - `counts()['pending']` is for a screen.
     */
    public function pendingCount(): int
    {
        return (int) $this->recipients()
            ->where('status', SmsCampaignRecipient::STATUS_PENDING)
            ->count();
    }

    /**
     * Every campaign the sender should be working on, oldest start first.
     *
     * Oldest first so a campaign started this morning finishes before one
     * started five minutes ago gets a look in: a list half sent for three hours
     * because somebody keeps starting new ones is the failure mode of every
     * fair-share queue that was written the other way round.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function dueQuery()
    {
        return static::query()
            ->where('status', self::STATUS_SENDING)
            ->orderByRaw('CASE WHEN started_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('started_at')
            ->orderBy('id');
    }
}
