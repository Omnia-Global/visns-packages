<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Visnsstudio\VisnsPackages\Models\VaultEntry;

/**
 * Rewrite every stored secret under the current APP_KEY.
 *
 * This is the second half of a key rotation. The first half is Laravel's:
 * put the old key in `APP_PREVIOUS_KEYS` and the new one in `APP_KEY`, at which
 * point the application can still *decrypt* everything but writes only under the
 * new key. Run this, and the old key stops being able to open anything - which
 * is the entire point of having rotated it.
 *
 * Order matters and there is no way for this command to check it: run it while
 * `APP_PREVIOUS_KEYS` still holds the old key. Remove the old key first and the
 * rows are unreadable, by this command and by everyone.
 *
 * The write goes through the query builder with values encrypted by hand rather
 * than through `$entry->save()`. Eloquent's dirty check has a specific opinion
 * about encrypted attributes - a value that decrypts to the same plaintext is
 * "unchanged" - and a rotation is exactly the case where identical plaintext
 * still has to be written. Going round the model also leaves `updated_at`
 * alone: re-encrypting is not an edit and must not look like one in a
 * rotation report.
 */
class VaultReencryptCommand extends Command
{
    protected $signature = 'vault:reencrypt {--chunk=200 : Rows to load at a time}';

    protected $description = 'Re-encrypt every vault secret under the current APP_KEY';

    /** The columns the model holds under an `encrypted` cast. */
    private const SECRETS = ['password', 'totp_secret', 'notes'];

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $table = (new VaultEntry())->getTable();
        $rewritten = 0;
        $failed = 0;

        // withTrashed: a soft-deleted entry is still readable by an
        // administrator who restores it, so leaving it on the old key would
        // quietly turn a restore into a decryption failure months later.
        VaultEntry::withTrashed()
            ->orderBy('id')
            ->chunkById($chunk, function ($entries) use ($table, &$rewritten, &$failed) {
                foreach ($entries as $entry) {
                    $updates = [];

                    try {
                        foreach (self::SECRETS as $column) {
                            // Reading goes through the cast, which is what
                            // consults APP_PREVIOUS_KEYS.
                            $plain = $entry->{$column};

                            $updates[$column] = $plain === null || $plain === ''
                                ? null
                                : Crypt::encryptString($plain);
                        }
                    } catch (\Throwable $e) {
                        // One unreadable row must not abandon the rest -
                        // stopping halfway leaves the vault split across two
                        // keys, which is worse than either.
                        $failed++;

                        $this->warn(sprintf(
                            'Entry #%d could not be decrypted (%s); left as it is.',
                            $entry->id,
                            $e->getMessage()
                        ));

                        continue;
                    }

                    DB::table($table)->where('id', $entry->id)->update($updates);

                    $rewritten++;
                }
            });

        $this->info(sprintf('Re-encrypted %d vault %s.', $rewritten, $rewritten === 1 ? 'entry' : 'entries'));

        if ($failed > 0) {
            $this->error(sprintf(
                '%d %s could not be decrypted with the current keys - check APP_PREVIOUS_KEYS before removing the old key.',
                $failed,
                $failed === 1 ? 'entry' : 'entries'
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
