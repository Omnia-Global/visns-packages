<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailListSync;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Carry email lists to Resend, and settle campaigns still sending.
 *
 * Meant for the scheduler, every minute, `withoutOverlapping()`: one tick is
 * paced and time-boxed (`email_campaigns.sync`), so a list of thousands goes
 * over in minutes without starving the host's own mail of Resend's rate limit.
 * Registered always; it says plainly when the module is off.
 */
class EmailCampaignsSyncCommand extends Command
{
    protected $signature = 'email-campaigns:sync {--list= : Only this list id}';

    protected $description = 'Copy email campaign lists to Resend and settle campaigns still sending';

    public function handle(EmailListSync $sync): int
    {
        if (! ModuleConfig::get('email_campaigns.enabled', false)) {
            $this->line('Email campaigns are turned off (visns-packages.email_campaigns.enabled).');

            return self::SUCCESS;
        }

        $report = $sync->tick($this->option('list') ? (int) $this->option('list') : null);

        $this->line(sprintf(
            '%d synced, %d removed, %d failed across %d list(s); %d campaign(s) settled%s',
            $report['synced'],
            $report['removed'],
            $report['failed'],
            $report['lists'],
            $report['campaigns'],
            $report['throttled'] ? '; Resend asked us to slow down, carrying on next minute' : ''
        ));

        return self::SUCCESS;
    }
}
