<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * Stamp each line with the Zoom user its number actually belongs to.
 *
 * The link matters because Zoom addresses SMS by number but administers it by
 * user: knowing which Zoom user owns a line is what makes "why did that send
 * fail" answerable without opening the Zoom admin console.
 *
 * Matching is by NUMBER, not by name or email - the number is the only thing
 * both sides agree on, and both sides are normalised to E.164 before comparison
 * so Zoom's spelling of it does not matter.
 *
 * A no-op unless the Zoom transport is configured: there is nothing to sync
 * against a dev transport, and answering "nothing to do" is better than failing.
 *
 * Schedule it, e.g.:
 *
 *     $schedule->command('sms:sync-lines')->dailyAt('04:00');
 */
class SmsSyncLinesCommand extends Command
{
    protected $signature = 'sms:sync-lines {--dry-run : Report what would change and change nothing}';

    protected $description = 'Match SMS lines to their Zoom Phone users by number';

    public function handle(SmsService $sms): int
    {
        if ($sms->transportName() !== 'zoom') {
            $this->info('The Zoom transport is not configured; nothing to sync.');

            return self::SUCCESS;
        }

        try {
            $result = app(ZoomSmsClient::class)->listPhoneUsers();
        } catch (\Throwable $e) {
            $this->error('Could not reach Zoom: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (! $result['success']) {
            $this->error($result['error'] ?? 'Could not reach Zoom.');

            return self::FAILURE;
        }

        // number (E.164) => the Zoom user holding it.
        $byNumber = [];

        foreach ($result['users'] as $user) {
            foreach ($user['phone_numbers'] as $number) {
                $e164 = PhoneNumber::toE164((string) $number);

                if ($e164 !== null) {
                    $byNumber[$e164] = $user;
                }
            }
        }

        $updated = 0;
        $unmatched = 0;

        foreach (SmsLine::all() as $line) {
            $zoomUser = $byNumber[(string) $line->phone_number] ?? null;

            if ($zoomUser === null) {
                $unmatched++;

                $this->warn(sprintf(
                    'No Zoom user holds %s (%s).',
                    $line->phone_number,
                    $line->label
                ));

                continue;
            }

            if ((string) $line->zoom_user_id === (string) $zoomUser['id']
                && (string) $line->zoom_user_email === (string) $zoomUser['email']) {
                continue;
            }

            $this->line(sprintf(
                '%s -> %s <%s>',
                $line->phone_number,
                $zoomUser['display_name'] !== '' ? $zoomUser['display_name'] : $zoomUser['id'],
                $zoomUser['email']
            ));

            if (! $this->option('dry-run')) {
                $line->forceFill([
                    'zoom_user_id' => $zoomUser['id'],
                    'zoom_user_email' => $zoomUser['email'],
                ])->save();
            }

            $updated++;
        }

        $this->info(sprintf(
            '%s %d %s; %d unmatched.',
            $this->option('dry-run') ? 'Would update' : 'Updated',
            $updated,
            $updated === 1 ? 'line' : 'lines',
            $unmatched
        ));

        return self::SUCCESS;
    }
}
