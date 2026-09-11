<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignSender;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One minute's worth of bulk texting.
 *
 * THE PACKAGE REGISTERS NO SCHEDULE, so this does nothing at all until the
 * consuming application schedules it - which is the right way round: a package
 * that scheduled work in somebody else's application would be sending texts
 * from a deployment that had only meant to upgrade a dependency.
 *
 *     // app/Console/Kernel.php, or routes/console.php
 *     $schedule->command('sms:send-campaigns')
 *              ->everyMinute()
 *              ->withoutOverlapping();
 *
 * `withoutOverlapping()` is belt to the sender's own braces: it holds
 * `Cache::lock('sms:send-campaigns')` for the length of a run precisely so that
 * a deployment which forgets the scheduler option still cannot text anybody
 * twice.
 *
 * Registered unconditionally, like the module's other three commands, and for
 * the same reason: a command that did not exist on a deployment with the
 * sub-module turned off would answer "command not found" to somebody trying to
 * find out why nothing is sending. It checks the switch itself and says so.
 */
class SmsSendCampaignsCommand extends Command
{
    protected $signature = 'sms:send-campaigns
        {--budget= : Messages to send this run; defaults to messaging.bulk.per_minute}';

    protected $description = 'Send the next batch of queued bulk SMS campaign messages';

    public function handle(SmsCampaignSender $sender): int
    {
        if (! (bool) ModuleConfig::get('messaging.enabled', false)) {
            $this->line('Messaging is disabled; nothing to send.');

            return self::SUCCESS;
        }

        if (! (bool) ModuleConfig::get('messaging.bulk.enabled', false)) {
            $this->line('Bulk campaigns are disabled (messaging.bulk.enabled); nothing to send.');

            return self::SUCCESS;
        }

        $budget = $this->budget();

        if ($budget < 1) {
            // Refused rather than clamped: "--budget=0" reads as "send nothing"
            // to whoever typed it, and quietly sending thirty would be a
            // surprise nobody could explain.
            $this->error('--budget must be at least 1.');

            return self::FAILURE;
        }

        $counts = $sender->run($budget);

        if ($counts['locked'] ?? false) {
            // Not a failure. Another run of the same command still holds the
            // lock, which on a minute schedule means the last one is taking
            // longer than a minute - worth saying, worth nothing more.
            $this->line('Another run is still going; nothing done.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Campaigns %d · sent %d · failed %d · skipped %d · waiting to retry %d (budget %d).',
            (int) ($counts['campaigns'] ?? 0),
            (int) ($counts['sent'] ?? 0),
            (int) ($counts['failed'] ?? 0),
            (int) ($counts['skipped'] ?? 0),
            (int) ($counts['retry_wait'] ?? 0),
            $budget
        ));

        return self::SUCCESS;
    }

    /**
     * The run's budget: the option, else config, else the shipped default.
     */
    private function budget(): int
    {
        $option = $this->option('budget');

        if ($option !== null && trim((string) $option) !== '') {
            return (int) $option;
        }

        return (int) ModuleConfig::get('messaging.bulk.per_minute', 30);
    }
}
