<?php

namespace Visnsstudio\VisnsPackages\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;

/**
 * Every rule and every word about WHEN a scheduled campaign sends (4.24.0).
 *
 * One class, because the same time is read in four places - the start
 * endpoint parsing what a browser posted, the payload describing it, the list
 * telling a composer which times it may offer, and the sender explaining why a
 * campaign it found hours late was paused instead of sent - and four copies of
 * "which timezone, which format" is how a reminder goes out at 9 pm.
 *
 * ## Two clocks
 *
 * A person types and reads WALL-CLOCK time in `config('app.timezone')`: the
 * browser posts `Y-m-d\TH:i` (an `<input type="datetime-local">`) with no zone,
 * and every label goes back in that zone. The STORED value is in the zone
 * `now()` runs in (PHP's default), because that is what `scheduled_at <= now()`
 * compares against and what Eloquent writes without converting. On a normal
 * deployment the two are the same zone; where they are not (a test that sets
 * `app.timezone` after boot), converting at the edges is what keeps a 9:00 am
 * Perth reminder from sending at 9:00 am UTC.
 *
 * The browser never does date arithmetic: `window()` hands it the earliest and
 * latest times it may offer, already formatted for the input.
 */
class SmsCampaignSchedule
{
    /** What an `<input type="datetime-local">` posts, minutes only. */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    /** A time must be at least this far ahead to be accepted. */
    public const MIN_LEAD_MINUTES = 1;

    /**
     * What the composer offers as its earliest time. Further ahead than the
     * accepted minimum, so a person who takes a minute to press the button is
     * not refused for a time the screen itself suggested.
     */
    public const OFFER_LEAD_MINUTES = 5;

    /** The sentences a refused `send_at` answers with. */
    public const BAD_FORMAT = 'Choose a date and time to send at.';

    public const IN_THE_PAST = 'Choose a time in the future.';

    public static function timezone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /**
     * "Perth time" from "Australia/Perth"; "UTC time" from "UTC".
     */
    public static function zone(): string
    {
        $name = self::timezone();
        $slash = strrpos($name, '/');
        $city = $slash === false ? $name : substr($name, $slash + 1);

        return str_replace('_', ' ', $city) . ' time';
    }

    /** "Times are Perth time." */
    public static function zoneNote(): string
    {
        return 'Times are ' . self::zone() . '.';
    }

    public static function maxDays(): int
    {
        $days = (int) ModuleConfig::get('messaging.bulk.schedule_max_days', 90);

        return $days > 0 ? $days : 90;
    }

    public static function graceMinutes(): int
    {
        $minutes = (int) ModuleConfig::get('messaging.bulk.schedule_grace_minutes', 60);

        return $minutes >= 0 ? $minutes : 60;
    }

    /**
     * Has this deployment migrated `scheduled_at`? Asked rather than assumed,
     * so the package runs unchanged between an update and its migration: no
     * scheduling is offered, and nothing reads or writes the column.
     */
    public static function available(): bool
    {
        return SchemaColumns::has((new SmsCampaign())->getTable(), 'scheduled_at');
    }

    /**
     * The wall clock a browser posted, as a stored-zone Carbon - or null when
     * it is not exactly `Y-m-d\TH:i` naming a real minute.
     *
     * Strict: trailing data, seconds, a zone suffix and a rolled-over date
     * ("2026-02-30" quietly becoming 2 March) are all refused, the last by
     * formatting the result back and requiring it to match what was typed.
     * That round trip also refuses a minute a DST change skips.
     */
    public static function parse($value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return null;
        }

        try {
            $at = Carbon::createFromFormat('!' . self::INPUT_FORMAT, $value, self::timezone());
        } catch (\Throwable $e) {
            return null;
        }

        if (! $at instanceof Carbon || $at->format(self::INPUT_FORMAT) !== $value) {
            return null;
        }

        return $at->setTimezone(Carbon::now()->getTimezone());
    }

    /**
     * Why this time cannot be used, or null when it can.
     */
    public static function problem(CarbonInterface $at): ?string
    {
        if ($at->lt(Carbon::now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            return self::IN_THE_PAST;
        }

        if ($at->gt(Carbon::now()->addDays(self::maxDays()))) {
            return sprintf('Choose a time within the next %d days.', self::maxDays());
        }

        return null;
    }

    /** `Y-m-d\TH:i` in the app's zone, for an input's value/min/max. */
    public static function local(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(self::timezone())->format(self::INPUT_FORMAT);
    }

    /** "Tuesday 24 November 2026 at 9:00 am", in the app's zone. */
    public static function label(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(self::timezone())->format('l j F Y \a\t g:i a');
    }

    /**
     * The payload's `schedule` block, or null for an unscheduled campaign.
     *
     * @return array{at: string, local: string, label: string, zone: string}|null
     */
    public static function describe(?CarbonInterface $at): ?array
    {
        if ($at === null) {
            return null;
        }

        return [
            'at' => $at->toIso8601String(),
            'local' => self::local($at),
            'label' => self::label($at),
            'zone' => self::zone(),
        ];
    }

    /**
     * What a campaign found past its grace says on its `last_error`.
     */
    public static function missedSentence(CarbonInterface $at): string
    {
        return sprintf(
            'This campaign was scheduled for %s but could not start then. Press Resume to send it now, or Cancel.',
            self::label($at)
        );
    }

    /**
     * The list's `settings.schedule`: what a composer may offer.
     *
     * @return array{enabled: bool, zone: string, zone_note: string, min: string, max: string}
     */
    public static function window(): array
    {
        $now = Carbon::now();

        return [
            'enabled' => self::available(),
            'zone' => self::zone(),
            'zone_note' => self::zoneNote(),
            'min' => self::local($now->copy()->addMinutes(self::OFFER_LEAD_MINUTES)),
            'max' => self::local($now->copy()->addDays(self::maxDays())),
        ];
    }
}
