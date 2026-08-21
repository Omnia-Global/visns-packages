<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Models\VaultAccessLog;

/**
 * Drop access log rows older than the retention window.
 *
 * The log grows by a row every time anybody opens an entry or fetches a code, so
 * it is the one table in this module that needs a broom. A year is the default
 * because that is the shortest window that still answers "who had this password
 * before we rotated it" for an annual review; shorten it deliberately, not by
 * accident.
 *
 * Schedule it, e.g. in the application's console kernel:
 *
 *     $schedule->command('vault:prune-log')->weeklyOn(1, '03:15');
 */
class VaultPruneLogCommand extends Command
{
    protected $signature = 'vault:prune-log {--days=365 : Keep entries newer than this many days}';

    protected $description = 'Delete vault access log rows older than the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            // Refused rather than clamped: "--days=0" reads like "delete
            // everything" to whoever typed it, and this command must not be the
            // one that makes that easy.
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = VaultAccessLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Deleted %d vault access log %s older than %s.',
            $deleted,
            $deleted === 1 ? 'row' : 'rows',
            $cutoff->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
