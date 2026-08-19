<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * The one place the call queue broadcast channel name is built.
 *
 * The three broadcast events and the snapshot endpoint all have to agree on this
 * string — the frontend never hardcodes it, it subscribes to whatever the
 * snapshot endpoint named — so resolving it in four places would be four chances
 * to drift.
 *
 * `append_env_suffix` exists because a deployment sharing one Pusher app between
 * environments needs the environment in the channel name: without it, dev-side
 * broadcasts (including test fixtures) appear in production browsers.
 */
class CallQueueChannel
{
    /**
     * The private channel name the pop listens on.
     */
    public static function name(): string
    {
        $channel = (string) ModuleConfig::get('call_queue.channel', 'call-queue-monitor');

        if (ModuleConfig::get('call_queue.append_env_suffix', false)) {
            $channel .= '.' . config('app.env');
        }

        return $channel;
    }
}
