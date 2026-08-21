<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * The one place the messaging broadcast channel name is built.
 *
 * Per LINE rather than one channel for the whole module: a practice with a
 * client-facing number and an adviser's own number should not have the second
 * one's conversations arriving in the browser of somebody only attached to the
 * first. The channel is private and authorised against the line pivot.
 *
 * `append_env_suffix` exists for the same reason it does on the call queue: a
 * deployment sharing one Pusher app between environments needs the environment
 * in the channel name, or dev broadcasts land in production browsers.
 */
class SmsChannel
{
    /**
     * The private channel one line's events are broadcast on.
     *
     * @param  int|string  $lineId
     */
    public static function name($lineId): string
    {
        $prefix = (string) ModuleConfig::get('messaging.channel', 'sms-line');

        $channel = $prefix . '.' . $lineId;

        if (ModuleConfig::get('messaging.append_env_suffix', false)) {
            $channel .= '.' . config('app.env');
        }

        return $channel;
    }

    /**
     * The pattern Broadcast::channel() registers, with `{lineId}` where the id
     * goes. The env suffix has to be inside the pattern too, or an
     * environment-scoped channel would never match its own authorisation route.
     */
    public static function pattern(): string
    {
        $prefix = (string) ModuleConfig::get('messaging.channel', 'sms-line');

        $pattern = $prefix . '.{lineId}';

        if (ModuleConfig::get('messaging.append_env_suffix', false)) {
            $pattern .= '.' . config('app.env');
        }

        return $pattern;
    }
}
