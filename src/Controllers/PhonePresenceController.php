<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Models\ZoomPhoneLiveCall;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomPhoneUserDirectory;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The Zoom Phone roster: every extension, and what it is doing right now.
 *
 * Two sources, joined here:
 *
 *   WHO   `GET /phone/users`, cached for ten minutes (ZoomPhoneUserDirectory).
 *         Only the API knows about an extension that has not had a call.
 *   WHAT  The live-call table, written by PhonePresenceRecorder from the signed
 *         Zoom webhook. Zoom has no per-extension "busy" endpoint to ask.
 *
 * The browser never talks to Zoom: it talks to this, and then listens on the
 * call queue channel for the changes that happen while it is open.
 */
class PhonePresenceController extends \App\Http\Controllers\Controller
{
    /** A leg Zoom never closed. Beyond this it is not "on a call", it is a bug. */
    private const DEFAULT_STALE_MINUTES = 240;

    /** A ringing leg that never answered or cleared. Phones stop ringing sooner. */
    private const DEFAULT_RINGING_STALE_MINUTES = 5;

    /**
     * Ids of the legs that found a home on the roster, filled in by
     * withPresence() as it runs. Whatever is left over is what
     * `unmatched_calls` reports.
     *
     * @var array<int, true>
     */
    private array $matched = [];

    public function index(Request $request, ZoomPhoneUserDirectory $directory)
    {
        $this->prune();

        $refresh = filter_var(
            $request->query('refresh', false),
            FILTER_VALIDATE_BOOLEAN
        );

        $roster = $directory->users($refresh);
        $legs = $this->legsByKey();

        $users = [];

        foreach ($roster['users'] as $user) {
            $users[] = $this->withPresence($user, $legs);
        }

        // Calls on extensions the roster does not know about: a common-area
        // handset, a room phone, or simply a staff member added since the cache
        // was filled. Showing them separately is more honest than dropping
        // them, which would make the popup say "everyone is free" during a call.
        $unmatched = array_values(array_map(
            fn (ZoomPhoneLiveCall $call) => [
                'name' => $call->user_name ?: 'Unknown extension',
                'extension_number' => $call->extension_number ?: null,
                'call' => $call->toPresencePayload(),
            ],
            $this->unmatchedLegs($legs)
        ));

        return response()->json([
            // False means "there are no Zoom credentials", which the popup says
            // out loud rather than showing an empty list.
            'configured' => $directory->configured(),
            'users' => $users,
            'unmatched_calls' => $unmatched,
            'on_call_count' => count(array_filter(
                $users,
                fn (array $user) => $user['call'] !== null
            )) + count($unmatched),
            // The channel and event the popup subscribes to. Never hardcoded in
            // the frontend — it uses whatever is named here.
            'channel' => CallQueueChannel::name(),
            'event' => '.phone.presence',
            'fetched_at' => Carbon::now()->toIso8601String(),
            'error' => $roster['success'] ? null : ($roster['error'] ?? 'Could not reach Zoom.'),
        ]);
    }

    /**
     * Attach the live call (if any) to one roster row.
     *
     * Matched on the identifiers Zoom's webhooks actually carry, in order: the
     * user id first, then the extension number. `/phone/users` and the webhook
     * payloads do not use the same names for these, which is the whole reason
     * the model publishes a key list rather than one id.
     */
    private function withPresence(array $user, array $legs): array
    {
        $call = null;

        foreach ([$user['id'] ?? '', $user['extension_number'] ?? ''] as $key) {
            $key = trim((string) $key);

            if ($key !== '' && isset($legs[$key])) {
                $call = $legs[$key];
                $this->matched[$call->id] = true;

                break;
            }
        }

        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'] ?? null,
            'extension_number' => $user['extension_number'] ?: null,
            'active' => (bool) ($user['active'] ?? true),
            'status' => $this->status($user, $call),
            'call' => $call === null ? null : $call->toPresencePayload(),
        ];
    }

    /**
     * The word under a name.
     *
     * "available" is an inference, not a fact Zoom told us: it means "no live
     * leg for this extension". That is right for a tenant whose webhook
     * subscription is healthy and wrong for one where it is not — which is why
     * the popup shows the whole roster's freshness alongside it rather than
     * letting a green dot stand on its own.
     */
    private function status(array $user, ?ZoomPhoneLiveCall $call): string
    {
        if ($call !== null) {
            return $call->status === 'ringing' ? 'ringing' : 'on_call';
        }

        return ($user['active'] ?? true) ? 'available' : 'inactive';
    }

    /**
     * Live legs, keyed by every identifier they can be matched on.
     *
     * A newer leg wins a key collision: an extension that somehow has two open
     * legs is showing the one that started most recently, which is the call the
     * person is actually on.
     *
     * @return array<string, ZoomPhoneLiveCall>
     */
    private function legsByKey(): array
    {
        $keyed = [];

        $legs = ZoomPhoneLiveCall::orderBy('started_at')->get();

        foreach ($legs as $leg) {
            foreach ($leg->rosterKeys() as $key) {
                $keyed[$key] = $leg;
            }
        }

        return $keyed;
    }

    /**
     * The legs that matched no roster row, de-duplicated back to one per leg.
     *
     * @param  array<string, ZoomPhoneLiveCall>  $legs
     * @return array<int, ZoomPhoneLiveCall>
     */
    private function unmatchedLegs(array $legs): array
    {
        $seen = [];
        $out = [];

        foreach ($legs as $leg) {
            if (isset($seen[$leg->id])) {
                continue;
            }

            $seen[$leg->id] = true;
            $out[] = $leg;
        }

        return array_values(array_filter(
            $out,
            fn (ZoomPhoneLiveCall $leg) => ! isset($this->matched[$leg->id])
        ));
    }

    /**
     * Drop legs Zoom never closed.
     *
     * Two windows, because they mean different things: a ringing leg that is
     * five minutes old was never going to be answered, while a connected call
     * can genuinely run for an hour. Without this, one dropped closing webhook
     * would leave somebody "on a call" until the next deploy.
     */
    private function prune(): void
    {
        $stale = (int) ModuleConfig::get(
            'call_queue.presence.stale_after_minutes',
            self::DEFAULT_STALE_MINUTES
        );

        $ringingStale = (int) ModuleConfig::get(
            'call_queue.presence.ringing_stale_after_minutes',
            self::DEFAULT_RINGING_STALE_MINUTES
        );

        if ($stale > 0) {
            ZoomPhoneLiveCall::where('started_at', '<', Carbon::now()->subMinutes($stale))
                ->delete();
        }

        if ($ringingStale > 0) {
            ZoomPhoneLiveCall::where('status', 'ringing')
                ->where('started_at', '<', Carbon::now()->subMinutes($ringingStale))
                ->delete();
        }
    }
}
