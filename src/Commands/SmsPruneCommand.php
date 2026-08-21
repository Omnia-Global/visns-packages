<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Models\SmsThread;

/**
 * Tidy the inbox by archiving quiet threads.
 *
 * IT DELETES NOTHING, and that is the whole design of this command. SMS to and
 * from clients is a client communication: an AFSL licensee has to be able to
 * produce it years later, so a "prune" that removed messages would be a
 * compliance incident wearing a maintenance command's clothes. What it does is
 * move threads with nothing recent in them out of the default list, which is the
 * actual complaint ("the inbox is full of finished conversations").
 *
 * Unarchiving is one click, and a new message on an archived thread is expected
 * to bring it back - the UI does that, not this command.
 *
 * Schedule it, e.g. in the application's console kernel:
 *
 *     $schedule->command('sms:prune')->weeklyOn(1, '03:30');
 */
class SmsPruneCommand extends Command
{
    protected $signature = 'sms:prune
        {--days=180 : Archive threads with no message newer than this many days}
        {--dry-run : Report what would be archived and change nothing}';

    protected $description = 'Archive SMS threads that have gone quiet (never deletes messages)';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            // Refused rather than clamped: "--days=0" reads like "archive
            // everything" to whoever typed it.
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = SmsThread::query()
            ->whereNull('archived_at')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_message_at', '<', $cutoff)
                    // A thread that never had a message is judged by when it was
                    // created, or it would sit in the list forever.
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('last_message_at')
                            ->where('created_at', '<', $cutoff);
                    });
            });

        if ($this->option('dry-run')) {
            $count = (int) $query->count();

            $this->info(sprintf(
                'Would archive %d %s with nothing newer than %s. No messages are ever deleted.',
                $count,
                $count === 1 ? 'thread' : 'threads',
                $cutoff->toDateTimeString()
            ));

            return self::SUCCESS;
        }

        $archived = (int) $query->update(['archived_at' => now()]);

        $this->info(sprintf(
            'Archived %d %s with nothing newer than %s. No messages were deleted.',
            $archived,
            $archived === 1 ? 'thread' : 'threads',
            $cutoff->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
