<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Per-queue call-pop settings: what staff dial to grab a call ringing on a Zoom
 * call queue, and whether that queue is allowed to pop at all.
 *
 * Owned by Settings -> Call Queues
 * (Visnsstudio\VisnsPackages\Controllers\CallQueueSettingsController). The queue
 * list itself is never stored here — it comes from the Zoom API — so a row exists
 * only for a queue somebody has actually configured. `queue_name` is a cache of
 * the name last seen on the API, refreshed whenever the settings page loads,
 * purely so the table is readable when Zoom is unreachable.
 *
 * Both runtime readers (the webhook's exclusion check and the pop's snapshot)
 * go through the cached static resolvers below rather than querying directly:
 * these run on every ringing webhook, and the data changes a few times a year.
 */
class ZoomCallQueueSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'excluded' => 'boolean',
    ];

    /**
     * Cache keys are package-prefixed so they cannot collide with an
     * application's own keys in a shared cache store.
     */
    private const CACHE_KEY_PICKUP_CODES = 'visns_call_queue_pickup_codes';
    private const CACHE_KEY_EXCLUDED_IDS = 'visns_call_queue_excluded_ids';

    /**
     * The table is configurable so an application that already owns one of these
     * tables keeps its name. Resolved in getTable() rather than the constructor
     * so static query builders see it too.
     */
    public function getTable()
    {
        return ModuleConfig::get('call_queue.tables.settings', 'zoom_call_queue_settings');
    }

    /**
     * Short enough that a save is visible almost immediately even if a cache bust
     * is missed.
     */
    private static function cacheTtl(): int
    {
        return (int) ModuleConfig::get('call_queue.settings_cache_ttl', 60);
    }

    /**
     * Pickup codes keyed by Zoom call queue id, in the form staff dial
     * (`*998781`). A queue with no code still pops, just without a Pick up
     * button.
     *
     * @return array<string, string>
     */
    public static function pickupCodes(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PICKUP_CODES,
            self::cacheTtl(),
            function () {
                // Stored bare; Zoom's dial prefix for queue pickup codes is
                // `*99` (fixed, shown in the Zoom admin UI next to the 4-digit
                // code field), so code 8781 is dialled *998781.
                $prefix = (string) ModuleConfig::get('call_queue.pickup_prefix', '*99');

                $codes = [];

                foreach (
                    self::query()
                        ->whereNotNull('pickup_code')
                        ->where('pickup_code', '!=', '')
                        ->get(['queue_id', 'pickup_code'])
                    as $row
                ) {
                    $queueId = trim((string) $row->queue_id);
                    $code = ltrim(trim((string) $row->pickup_code), '*');

                    if ($queueId !== '' && $code !== '') {
                        $codes[$queueId] = $prefix . $code;
                    }
                }

                return $codes;
            }
        );
    }

    /**
     * Queue ids the operator has opted out of popping entirely, lower-cased for
     * the caller's case-insensitive comparison.
     *
     * @return array<int, string>
     */
    public static function excludedIds(): array
    {
        return Cache::remember(
            self::CACHE_KEY_EXCLUDED_IDS,
            self::cacheTtl(),
            fn() => self::query()
                ->where('excluded', true)
                ->pluck('queue_id')
                ->map(fn($id) => strtolower(trim((string) $id)))
                ->filter()
                ->values()
                ->all()
        );
    }

    /**
     * The pseudo-queue id every direct call is configured under.
     *
     * Calls ringing somebody's own extension have no queue to hang a setting
     * off, but the operator still needs the same two switches — may they pop,
     * and what do staff dial to grab one. Rather than a second table with two
     * columns, they live in this one under a reserved id. Zoom will never issue
     * a queue id of 'direct': its own ids are opaque base64-ish strings.
     */
    public const DIRECT_QUEUE_ID = 'direct';

    /**
     * May calls ringing a staff member's own extension pop at all?
     *
     * The operator's switch, stored as the `excluded` flag on the `direct`
     * pseudo-queue row — so it reads through the same cache as every other
     * exclusion and needs no key of its own. Separate from
     * `call_queue.direct_calls.enabled`, which is the developer's master
     * switch: config off means the feature is not installed here, this off
     * means the office was offered it and turned it down.
     */
    public static function directPopsEnabled(): bool
    {
        return ! in_array(self::DIRECT_QUEUE_ID, self::excludedIds(), true);
    }

    /** Called on every settings save — without it a change waits out the TTL. */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY_PICKUP_CODES);
        Cache::forget(self::CACHE_KEY_EXCLUDED_IDS);
    }
}
