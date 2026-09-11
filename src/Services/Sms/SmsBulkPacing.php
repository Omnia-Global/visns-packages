<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * How fast a bulk campaign may go, and what to say while it is not going.
 *
 * ## The problem this exists for
 *
 * Zoom publishes rate limits for its Phone API - 20 requests a second on a Pro
 * account for a Light endpoint, 10 for a Medium one, and a 429 that says
 * whether it was the per-second or the daily limit that was hit. **Those are
 * not the constraint.** Sending at ten texts a second would sit inside every
 * published limit and still be the wrong thing to do, for two reasons neither
 * of which is documented anywhere:
 *
 *  - **Zoom Phone applies a per-user daily SMS cap** that it does not publish.
 *    Community reports put it somewhere between 20 and 100 texts per user per
 *    day. The first campaign this practice sends is the experiment that finds
 *    out where it really is.
 *  - **Carriers filter on shape.** A burst of near-identical messages from one
 *    mobile number is what a spam run looks like from the network's side, and
 *    the number gets filtered rather than the messages getting refused - so
 *    there is no error to read and no way to tell it happened.
 *
 * So the strategy is not "use the API budget efficiently". It is **to look like
 * a person texting**: one message at a time, a couple of seconds apart, a few
 * dozen an hour, and stop for the day at a sensible number.
 *
 * ## The three mechanisms, and which question each answers
 *
 * | | answers |
 * | --- | --- |
 * | `send_interval_ms` | how close together two texts may go out |
 * | a per-line **cooldown** | Zoom has said *slow down* - for how long |
 * | `per_day` | how many texts this line may send today at all |
 *
 * They are deliberately separate. The interval is a shape; the cooldown is an
 * instruction from the provider; the allowance is a ceiling of our own. A
 * deployment can turn the third off (`per_day = 0`) without touching the other
 * two, and the cooldown exists whatever either is set to.
 *
 * ## Nothing here pauses a campaign
 *
 * Every wait this class describes is temporary and self-clearing, which is why
 * none of them sets `SmsCampaign::STATUS_PAUSED`. A paused campaign needs a
 * human to press Resume, and nobody should have to come in the next morning and
 * press Resume on four campaigns because the line used its allowance at
 * half past four. The campaign stays `sending`, the recipients stay `pending`,
 * and the run after the wait picks up exactly where this one stopped.
 *
 * ## One sentence, built once
 *
 * `waitingFor()` is the ONLY place a waiting state is put into words. The
 * sender logs it, the payload sends it and the screen prints it - so a campaign
 * cannot be described three slightly different ways depending on where somebody
 * happens to be looking.
 */
class SmsBulkPacing
{
    /** Cache key prefix for a line's cooldown. One key per line. */
    public const COOLDOWN_PREFIX = 'messaging:bulk:cooldown:';

    /**
     * What a 429 buys when Zoom does not say how long to wait.
     *
     * A minute is the smallest wait that is plainly a wait: shorter than the
     * scheduler's own tick would mean the next run tries again immediately,
     * which is the behaviour the cooldown exists to replace.
     */
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    /**
     * The longest a cooldown may last, whatever `Retry-After` says.
     *
     * A provider that asks for an hour has almost certainly answered a header
     * that was not about this send; and a campaign silently not sending for an
     * hour is indistinguishable, on any screen, from one that is broken. Fifteen
     * minutes is long enough to be a real wait and short enough that somebody
     * watching sees it end.
     */
    public const MAX_COOLDOWN_SECONDS = 900;

    /**
     * Headroom left at the end of a run's minute, in milliseconds.
     *
     * The scheduler fires every minute and `withoutOverlapping()` means a run
     * still going when the next tick arrives causes that tick to be SKIPPED
     * entirely - so a run that overshoots does not merely finish late, it costs
     * the whole of the next minute. The budget is therefore sized against 50
     * seconds rather than 60, which leaves ten for the sends themselves to take
     * as long as they take.
     */
    public const RUN_BUDGET_MS = 50000;

    /** Guard on the ETA's day loop - a year is past the point of usefulness. */
    private const MAX_ETA_DAYS = 365;

    /**
     * Today's send count per line, for the life of THIS instance only.
     *
     * The list endpoint serialises every campaign and would otherwise run one
     * COUNT per sending campaign; the sender wants a fresh number and gets one
     * by holding its own instance for the length of a run and forgetting it
     * afterwards. Deliberately not a static and deliberately not a cache: a
     * memo that outlived the request would, under Octane, go on answering
     * yesterday's question - and this one decides whether anybody gets texted.
     *
     * @var array<int, int>
     */
    private array $usedToday = [];

    /* ---------------------------------------------------------------------
     | The interval
     | ------------------------------------------------------------------- */

    /**
     * Milliseconds to wait after one send attempt before making the next.
     *
     * Zero or less turns the pacing off, which is what the test suite runs with
     * and what a deployment sending to a handful of people can reasonably do.
     */
    public function intervalMs(): int
    {
        return max(0, (int) ModuleConfig::get('messaging.bulk.send_interval_ms', 2000));
    }

    /**
     * How many messages a single run may attempt.
     *
     * `min(per_minute, floor(50_000 / send_interval_ms))` - the configured
     * budget, or as many as fit in the run's minute with the interval applied,
     * whichever is smaller. Never less than one: a budget of nought is a
     * campaign that never moves, and an interval long enough to imply that is a
     * misconfiguration better answered by sending one text a minute than by
     * sending none ever.
     */
    public function effectiveBudget(int $budget): int
    {
        $budget = max(0, $budget);

        $interval = $this->intervalMs();

        if ($interval <= 0 || $budget === 0) {
            return $budget;
        }

        return max(1, min($budget, (int) floor(self::RUN_BUDGET_MS / $interval)));
    }

    /* ---------------------------------------------------------------------
     | The cooldown
     | ------------------------------------------------------------------- */

    /**
     * Record that this line has been asked to slow down.
     *
     * @param  int|null  $retryAfter  Seconds, as the provider gave them. Null,
     *                                zero or negative all fall back to the
     *                                default: a 429 with no usable header is
     *                                still a 429.
     * @return \Illuminate\Support\Carbon  When sending may resume.
     */
    public function cool(int $lineId, ?int $retryAfter = null): Carbon
    {
        $seconds = $retryAfter !== null && $retryAfter > 0
            ? $retryAfter
            : self::DEFAULT_COOLDOWN_SECONDS;

        $seconds = min($seconds, self::MAX_COOLDOWN_SECONDS);

        $until = Carbon::now()->addSeconds($seconds);

        // Stored as an ISO string rather than a Carbon: a cache store that
        // serialises (file, redis, database) hands back a Carbon fine, and an
        // array store hands back the same object - but a string is the same
        // thing on every driver, which is the rule everywhere else here.
        Cache::put(self::COOLDOWN_PREFIX . $lineId, $until->toIso8601String(), $until);

        Log::warning('sms.campaign line cooling down', [
            'line_id' => $lineId,
            'seconds' => $seconds,
            'until' => $until->toIso8601String(),
            'retry_after' => $retryAfter,
        ]);

        return $until;
    }

    /**
     * When this line may send again, or null if it may send now.
     *
     * A stored time that has already passed reads as no cooldown - the cache
     * entry expires on its own, and a driver that is slow to reap must not keep
     * a line waiting a second longer than the clock says.
     */
    public function cooldownUntil(int $lineId): ?Carbon
    {
        $stored = Cache::get(self::COOLDOWN_PREFIX . $lineId);

        if ($stored === null) {
            return null;
        }

        try {
            $until = $stored instanceof CarbonInterface
                ? Carbon::instance($stored)
                : Carbon::parse((string) $stored);
        } catch (\Throwable $e) {
            // An unreadable value is not a reason to stop texting anybody.
            return null;
        }

        return $until->isFuture() ? $until : null;
    }

    /** Let a line send again immediately. Used by the tests and by nothing else. */
    public function clearCooldown(int $lineId): void
    {
        Cache::forget(self::COOLDOWN_PREFIX . $lineId);
    }

    /* ---------------------------------------------------------------------
     | The daily allowance
     | ------------------------------------------------------------------- */

    /**
     * The most this line may send in one day. Zero means unlimited.
     */
    public function perDay(): int
    {
        return max(0, (int) ModuleConfig::get('messaging.bulk.per_day', 100));
    }

    /**
     * How many campaign messages this line has sent today.
     *
     * Counted off the RECIPIENTS rather than off a counter of our own, across
     * every campaign on the line, because the allowance is a fact about the
     * Zoom user behind the number and not about any one campaign: three
     * campaigns on one line share the day's hundred.
     *
     * "Today" is the application's own timezone, which is the timezone
     * `sent_at` was written in - and the timezone the person watching the
     * screen lives in, which is what makes "resumes after midnight" a sentence
     * they can act on.
     */
    public function usedToday(int $lineId): int
    {
        if (array_key_exists($lineId, $this->usedToday)) {
            return $this->usedToday[$lineId];
        }

        $campaignIds = SmsCampaign::query()
            ->where('line_id', $lineId)
            ->select('id');

        return $this->usedToday[$lineId] = (int) SmsCampaignRecipient::query()
            ->where('status', SmsCampaignRecipient::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->whereIn('campaign_id', $campaignIds)
            ->count();
    }

    /**
     * How many more this line may send today. PHP_INT_MAX when unlimited.
     */
    public function remainingToday(int $lineId): int
    {
        $perDay = $this->perDay();

        if ($perDay === 0) {
            return PHP_INT_MAX;
        }

        return max(0, $perDay - $this->usedToday($lineId));
    }

    /**
     * Tell this instance a send has just happened, so the rest of the run
     * counts it without asking the database again.
     */
    public function recordSend(int $lineId): void
    {
        $this->usedToday[$lineId] = $this->usedToday($lineId) + 1;
    }

    /** Forget the memo - for a caller that has slept through a midnight. */
    public function forget(): void
    {
        $this->usedToday = [];
    }

    /* ---------------------------------------------------------------------
     | Saying why
     | ------------------------------------------------------------------- */

    /**
     * Why this campaign is not sending at this instant, in one sentence.
     *
     * Null means nothing is holding it up - which is not the same as "it is
     * sending": a draft, a paused campaign and a completed one all answer null,
     * because their status already says what is going on and a second
     * explanation beside it would be a contradiction waiting to happen.
     *
     * @return array{reason: string, until: string|null, message: string}|null
     */
    public function waitingFor(SmsCampaign $campaign): ?array
    {
        if ($campaign->status !== SmsCampaign::STATUS_SENDING) {
            return null;
        }

        $lineId = (int) $campaign->line_id;

        if ($lineId <= 0) {
            return null;
        }

        $until = $this->cooldownUntil($lineId);

        if ($until !== null) {
            return [
                'reason' => 'cooldown',
                'until' => $until->toIso8601String(),
                'message' => sprintf(
                    'Zoom asked us to slow down; sending resumes at %s.',
                    $until->format('H:i')
                ),
            ];
        }

        if ($this->remainingToday($lineId) > 0) {
            return null;
        }

        return [
            'reason' => 'daily_allowance',
            'until' => Carbon::now()->addDay()->startOfDay()->toIso8601String(),
            'message' => sprintf(
                "Today's allowance of %d texts on this line is used; sending resumes after midnight.",
                $this->perDay()
            ),
        ];
    }

    /* ---------------------------------------------------------------------
     | How long the rest of it will take
     | ------------------------------------------------------------------- */

    /**
     * Minutes until this campaign's pending recipients are all attempted.
     *
     * An ESTIMATE, and it was already one before the pacing existed - the
     * per-minute budget is shared between every campaign that is sending, and a
     * retryable failure costs a run. What it now also knows is the two things
     * that can make the honest answer days rather than minutes:
     *
     *  - the interval, which can cut a 30-a-minute budget to 25;
     *  - the daily allowance, which stops the line entirely until midnight.
     *
     * A 500-recipient campaign on a 100-a-day line is about five days, and
     * saying "about 20 minutes" because that is how long 500 divided by 25
     * takes would be a promise nobody could keep.
     *
     * Null unless the campaign is actually sending: for a draft or a paused one
     * the honest answer is "until somebody presses start", and a number there
     * reads as a countdown that has stalled.
     */
    public function minutesRemaining(SmsCampaign $campaign, int $pending): ?int
    {
        if ($campaign->status !== SmsCampaign::STATUS_SENDING) {
            return null;
        }

        if ($pending <= 0) {
            return 0;
        }

        $perRun = $this->effectiveBudget(
            max(1, (int) ModuleConfig::get('messaging.bulk.per_minute', 30))
        );

        $perRun = max(1, $perRun);

        $lineId = (int) $campaign->line_id;
        $perDay = $this->perDay();

        if ($perDay === 0 || $lineId <= 0) {
            return (int) ceil($pending / $perRun);
        }

        $capacityToday = $this->remainingToday($lineId);

        $today = min($pending, $capacityToday);
        $left = $pending - $today;

        if ($left <= 0) {
            return (int) ceil($today / $perRun);
        }

        // Everything past today waits out the rest of the day first, whatever
        // is left of it - so the estimate starts at midnight rather than at the
        // moment the allowance ran out.
        $minutes = max(1, (int) ceil(
            Carbon::now()->diffInSeconds(Carbon::now()->addDay()->startOfDay()) / 60
        ));

        for ($day = 0; $left > 0 && $day < self::MAX_ETA_DAYS; $day++) {
            $chunk = min($left, $perDay);
            $left -= $chunk;

            // A day that ends with more to send costs the whole day; the last
            // one costs only as long as its own messages take.
            $minutes += $left > 0 ? 1440 : (int) ceil($chunk / $perRun);
        }

        return $minutes;
    }

    /**
     * The same number as a phrase, because "about 7200 minutes" is not an
     * answer anybody reads as five days.
     *
     * Built here rather than in the front end so the two estates that draw a
     * campaign cannot round it differently.
     */
    public function etaLabel(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes <= 0) {
            return 'any moment now';
        }

        if ($minutes < 90) {
            return sprintf('about %d minute%s', $minutes, $minutes === 1 ? '' : 's');
        }

        if ($minutes < 2880) {
            $hours = (int) round($minutes / 60);

            return sprintf('about %d hour%s', $hours, $hours === 1 ? '' : 's');
        }

        $days = (int) ceil($minutes / 1440);

        return sprintf('about %d day%s', $days, $days === 1 ? '' : 's');
    }
}
