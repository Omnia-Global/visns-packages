<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Carbon\Carbon;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Snapshot of the live call queue state, for the call queue pop to hydrate from
 * on page load (broadcasts only cover what happens after the tab is listening).
 */
class CallQueueController extends \App\Http\Controllers\Controller
{
    public function live()
    {
        /*
        | How long a row may sit in the table before it is treated as abandoned.
        | Zoom does not guarantee a closing event for every call, so without this
        | a dropped webhook would leave a card ringing forever.
        */
        $staleAfterMinutes = (int) ModuleConfig::get('call_queue.stale_after_minutes', 15);

        ZoomLiveQueueCall::where(
            'created_at',
            '<',
            Carbon::now()->subMinutes($staleAfterMinutes)
        )->delete();

        $calls = ZoomLiveQueueCall::where('status', 'ringing')
            ->orderBy('started_at')
            ->get()
            ->map(fn(ZoomLiveQueueCall $call) => $call->toPopPayload())
            ->values();

        return response()->json([
            'calls' => $calls,
            'pickup_codes' => $this->pickupCodes(),
            // The Echo channel the pop subscribes to; the frontend never
            // hardcodes it, it uses whatever is named here.
            'channel' => CallQueueChannel::name(),
        ]);
    }

    private function trim($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Pickup codes keyed by Zoom call queue id — the pop renders these, so it
     * never has to hardcode a dial string. A queue with no entry here still
     * pops, just without a Pick up button.
     *
     * Maintained in Settings -> Call Queues; the model caches the lookup, since
     * this runs on every page load of every listening tab.
     *
     * @return array<string, string>
     */
    private function pickupCodes(): array
    {
        $codes = [];

        foreach (ZoomCallQueueSetting::pickupCodes() as $queueId => $code) {
            $queueId = $this->trim($queueId);
            $code = $this->trim($code);

            if ($queueId !== '' && $code !== '') {
                $codes[$queueId] = $code;
            }
        }

        return $codes;
    }
}
