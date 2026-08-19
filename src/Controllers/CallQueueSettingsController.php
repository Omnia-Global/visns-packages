<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomCallQueueService;

/**
 * Settings -> Call Queues: the pickup code and pop exclusion for every Zoom call
 * queue in the account.
 *
 * The queue list is always live from Zoom
 * (Visnsstudio\VisnsPackages\Services\Zoom\ZoomCallQueueService) so a queue added
 * or renamed in Zoom admin shows up here without anyone editing the application;
 * the pickup code and the exclusion flag are the application's, stored per queue
 * id in the call queue settings table.
 *
 * A saved code is pushed to Zoom before it is stored, so the application can
 * never advertise a code Zoom refused. See ZoomCallQueueService's docblock for
 * exactly how much of that push Zoom currently honours.
 *
 * Gated by the "Call Queue Settings" permission.
 */
class CallQueueSettingsController extends \App\Http\Controllers\Controller
{
    public function __construct(private ZoomCallQueueService $zoom)
    {
    }

    /**
     * Every queue Zoom knows about, merged with its local settings.
     *
     * Zoom being down is not an error here: the page still has to work for the
     * exclusion toggles, so the locally-known queues are returned with
     * `zoom_unreachable` set and the UI warns instead of failing.
     */
    public function index()
    {
        $local = ZoomCallQueueSetting::all()->keyBy('queue_id');
        $result = $this->zoom->listQueues();

        if (! $result['success']) {
            return response()->json([
                'queues' => $local
                    ->map(fn(ZoomCallQueueSetting $row) => $this->row($row->queue_id, [], $row))
                    ->values(),
                'zoom_unreachable' => true,
                'error' => $result['error'] ?? 'Failed to reach the Zoom API',
            ]);
        }

        $queues = [];
        $seen = [];

        foreach ($result['queues'] as $queue) {
            $queueId = trim((string) Arr::get($queue, 'id', ''));

            if ($queueId === '') {
                continue;
            }

            $seen[] = $queueId;
            $setting = $local->get($queueId);

            $this->cacheQueueName($queueId, trim((string) Arr::get($queue, 'name', '')), $setting);

            $queues[] = $this->row($queueId, $queue, $setting);
        }

        // A queue configured here but no longer in the account still has to be
        // visible — otherwise its stale exclusion silently keeps a queue that
        // was recreated under a new id from popping, with nothing on screen to
        // explain why.
        foreach ($local as $queueId => $setting) {
            if (! in_array($queueId, $seen, true)) {
                $queues[] = $this->row($queueId, [], $setting) + ['missing_in_zoom' => true];
            }
        }

        return response()->json([
            'queues' => $queues,
            'zoom_unreachable' => false,
        ]);
    }

    /**
     * Save one queue's settings.
     *
     * A changed pickup code goes to Zoom first and is only stored if Zoom took
     * it; clearing a code turns the policy off in Zoom the same way. The
     * exclusion flag is the application's alone and never touches Zoom.
     */
    public function update(Request $request, string $queueId)
    {
        $queueId = trim($queueId);

        $data = $request->validate([
            'pickup_code' => ['sometimes', 'nullable', 'string'],
            'excluded' => ['sometimes', 'boolean'],
            'queue_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $setting = ZoomCallQueueSetting::firstOrNew(['queue_id' => $queueId]);

        if (array_key_exists('pickup_code', $data)) {
            $code = $this->normaliseCode($data['pickup_code']);
            $current = $this->normaliseCode($setting->pickup_code);

            if ($code !== $current) {
                if ($code !== null) {
                    $this->assertValidCode($code);

                    $push = $this->zoom->setPickupCode($queueId, $code);
                } else {
                    $push = $this->zoom->disablePickupCode($queueId);
                }

                if (! ($push['success'] ?? false)) {
                    // Nothing is stored: the pop must never advertise a code
                    // Zoom would not take.
                    return response()->json([
                        'message' => $push['error'] ?? 'Zoom rejected the change',
                    ], 422);
                }

                $setting->pickup_code = $code;
            }
        }

        if (array_key_exists('excluded', $data)) {
            $setting->excluded = (bool) $data['excluded'];
        }

        if (array_key_exists('queue_name', $data) && trim((string) $data['queue_name']) !== '') {
            $setting->queue_name = trim((string) $data['queue_name']);
        }

        $setting->save();

        ZoomCallQueueSetting::flushCache();

        return response()->json([
            'queue' => $this->row($queueId, [], $setting),
        ]);
    }

    /**
     * One row of the settings table: what Zoom says about the queue plus what
     * the application stores against it.
     *
     * @param array<string, mixed> $queue Zoom's queue payload, empty when unavailable.
     */
    private function row(string $queueId, array $queue, ?ZoomCallQueueSetting $setting): array
    {
        $numbers = (array) Arr::get($queue, 'phone_numbers', []);
        $number = trim((string) Arr::get($numbers, '0.number', ''));

        $name = trim((string) Arr::get($queue, 'name', ''));

        return [
            'queue_id' => $queueId,
            'name' => $name !== ''
                ? $name
                : (trim((string) ($setting->queue_name ?? '')) ?: null),
            'extension_number' => Arr::get($queue, 'extension_number'),
            'number' => $number !== '' ? $number : null,
            'status' => trim((string) Arr::get($queue, 'status', '')) ?: null,
            'pickup_code' => $this->normaliseCode($setting->pickup_code ?? null),
            'excluded' => (bool) ($setting->excluded ?? false),
        ];
    }

    /**
     * Keep the local name cache in step with Zoom, so the table is still
     * readable on the day the API is down. Only writes when something changed.
     */
    private function cacheQueueName(string $queueId, string $name, ?ZoomCallQueueSetting $setting): void
    {
        if ($name === '' || $setting === null || $setting->queue_name === $name) {
            return;
        }

        $setting->queue_name = $name;
        $setting->save();
    }

    /** Bare digits, or null for "no code". */
    private function normaliseCode($value): ?string
    {
        $code = ltrim(trim((string) ($value ?? '')), '*');

        return $code === '' ? null : $code;
    }

    /**
     * Zoom's own pickup code rules, enforced here so a bad code is rejected
     * before it reaches the API (and because Zoom does not validate this field
     * over the API at all — see ZoomCallQueueService).
     *
     * Four digits, not starting with 0, not four of the same digit, and not a
     * run of consecutive digits in either direction.
     *
     * @throws ValidationException
     */
    private function assertValidCode(string $code): void
    {
        $fail = function (string $message): void {
            throw ValidationException::withMessages(['pickup_code' => $message]);
        };

        if (! preg_match('/^\d{4}$/', $code)) {
            $fail('The pickup code must be exactly 4 digits.');
        }

        if ($code[0] === '0') {
            $fail('The pickup code cannot start with 0.');
        }

        if (preg_match('/^(\d)\1{3}$/', $code)) {
            $fail('The pickup code cannot repeat the same digit (e.g. 1111).');
        }

        $ascending = true;
        $descending = true;

        for ($i = 1; $i < 4; $i++) {
            $step = (int) $code[$i] - (int) $code[$i - 1];

            $ascending = $ascending && $step === 1;
            $descending = $descending && $step === -1;
        }

        if ($ascending || $descending) {
            $fail('The pickup code cannot be a sequence (e.g. 1234 or 4321).');
        }
    }
}
