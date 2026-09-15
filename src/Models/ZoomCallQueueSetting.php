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
    private const CACHE_KEY_QUEUE_IDS = 'visns_call_queue_ids_by_extension_and_name';

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

    /**
     * Two ways of finding a queue id locally, for a webhook that did not send one.
     *
     * ==========================================================================
     *  ZOOM NAMES THE QUEUE ON A RINGING EVENT AND DOES NOT IDENTIFY IT.
     * ==========================================================================
     *
     * Verified on production: a `phone.callee_ringing` for a queue-distributed
     * leg carries `forwarded_by: {name: "Test Dev Call Queue", extension_type:
     * "callQueue", extension_number: "805"}` — no `id` and no `extension_id`. So
     * the webhook had no key for the pickup-code map and no key for the exclusion
     * check, and a configured queue popped without a Pick up button.
     *
     * The queue LISTING carries the id, the name and the extension number
     * together, so the settings row can remember the last two and this map turns
     * either of them back into the first.
     *
     * NO ZOOM API CALL ON THE WEBHOOK PATH, and that is the point of the cache
     * rather than a nicety: Zoom retries a webhook it does not get a prompt 200
     * for and disables the subscription after enough failures, so a ringing event
     * must never wait on an HTTP round trip to somebody else's service. This is
     * one small SELECT every ten minutes.
     *
     * EXTENSION NUMBER FIRST, NAME SECOND. An extension number is unique in a
     * Zoom account and is what the payload actually carries; a name is neither —
     * two queues may be called the same thing, and the name in the payload is a
     * display string somebody can edit in the Zoom admin UI at any time. The
     * name lookup exists because every row that predates the `extension_number`
     * column has no number yet, and it is better than nothing until the settings
     * page is next opened.
     *
     * KEYS ARE COMPARED AS THE CALLER FINDS THEM: extension numbers trimmed and
     * exact (they are identifiers, and `805` is not `0805`), names trimmed and
     * LOWER-CASED, because a display name's case is not meaningful and MySQL
     * would fold it while SQLite would not.
     *
     * THE `direct` PSEUDO-ROW IS EXCLUDED FROM BOTH MAPS. It is not a Zoom queue
     * and has neither a real extension number nor a name Zoom will ever send; a
     * queue genuinely called "Direct calls" must not resolve to it.
     *
     * @return array{extensions: array<string, string>, names: array<string, string>}
     */
    public static function idsByExtensionAndName(): array
    {
        return Cache::remember(
            self::CACHE_KEY_QUEUE_IDS,
            self::queueIdCacheTtl(),
            function () {
                $extensions = [];
                $names = [];

                foreach (
                    self::query()->get(['queue_id', 'queue_name', 'extension_number'])
                    as $row
                ) {
                    $queueId = trim((string) $row->queue_id);

                    if ($queueId === '' || $queueId === self::DIRECT_QUEUE_ID) {
                        continue;
                    }

                    $extension = trim((string) $row->extension_number);

                    if ($extension !== '') {
                        $extensions[$extension] = $queueId;
                    }

                    $name = strtolower(trim((string) $row->queue_name));

                    if ($name !== '') {
                        /*
                         * FIRST ROW WINS on a duplicated name. Two queues sharing
                         * a display name is a real state in Zoom, and there is no
                         * honest way to choose between them from a name alone —
                         * so this is deterministic rather than right, and the
                         * extension lookup above is what makes it rarely matter.
                         */
                        $names[$name] ??= $queueId;
                    }
                }

                return ['extensions' => $extensions, 'names' => $names];
            }
        );
    }

    /**
     * How long the id map is held.
     *
     * TEN MINUTES, an order of magnitude longer than `settings_cache_ttl`, and
     * for a different reason: that one is short so a pickup code somebody just
     * typed is visible almost at once, while this answers "which queue is this"
     * — a fact that changes when a queue is created or renamed, which is a few
     * times a year. `flushCache()` forgets it on every settings save anyway, so
     * the TTL is housekeeping rather than correctness.
     */
    private static function queueIdCacheTtl(): int
    {
        return (int) ModuleConfig::get('call_queue.queue_id_cache_ttl', 600);
    }

    /** Called on every settings save — without it a change waits out the TTL. */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY_PICKUP_CODES);
        Cache::forget(self::CACHE_KEY_EXCLUDED_IDS);
        Cache::forget(self::CACHE_KEY_QUEUE_IDS);
    }
}
