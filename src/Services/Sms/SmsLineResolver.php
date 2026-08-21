<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Which line does a text go out on when nobody chose one?
 *
 * Two callers need that answer and they must not disagree about it:
 * SmsSystemSender (login codes, portal OTPs) and SmsService::sendToNumber() (a
 * client-facing message the application originates, e.g. an appointment
 * reminder). A code arriving from one number and the reminder from another
 * would look, to the client, like two different businesses.
 *
 * The order is deliberate:
 *
 *   1. `messaging.system_line`, when the practice has named a number. An
 *      explicit setting always wins, and a setting that names a number nobody
 *      owns is a misconfiguration worth a log line - not silently ignored.
 *   2. The first active line that has a `zoom_user_id`. Zoom refuses a send
 *      without one (see ZoomSmsClient::sendBody), so a line missing it cannot
 *      actually deliver and preferring it would fail every code.
 *   3. Any active line. Right for the dev/log transport, where there is no Zoom
 *      account and therefore no zoom_user_id anywhere.
 *
 * Ordered by id at every step so the answer is stable: a resolution that
 * depended on insertion order in the database would move a practice's sending
 * number the day a line was edited.
 */
class SmsLineResolver
{
    /**
     * The line application-originated texts go out on, or null when the
     * application has no usable line at all.
     */
    public static function resolve(): ?SmsLine
    {
        $configured = ModuleConfig::get('messaging.system_line');

        if (is_string($configured) && trim($configured) !== '') {
            $line = SmsLine::findByNumber($configured);

            if ($line !== null) {
                return $line;
            }

            // Worth saying out loud: somebody set this on purpose and it is not
            // taking effect. Falling through is still better than refusing to
            // send a login code.
            Log::warning('sms.system_line names a number with no line', [
                'system_line' => $configured,
            ]);
        }

        $withZoomUser = SmsLine::query()
            ->where('active', true)
            ->whereNotNull('zoom_user_id')
            ->orderBy('id')
            ->first();

        if ($withZoomUser !== null) {
            return $withZoomUser;
        }

        return SmsLine::query()
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }
}
