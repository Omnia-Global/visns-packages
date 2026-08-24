<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Models\VaultEntry;

/**
 * Drop the client name off the front of imported entry titles.
 *
 * The importers had to build a title that was unique across a whole practice,
 * so they prefixed the client: "Karrinyup Dental Centre — MiVBS". That was the
 * right call at import time and the wrong thing to keep, because the list now
 * shows the linked client beside the title - every row reads the client's name
 * twice, and the part that actually distinguishes one credential from another
 * is pushed off the end of the column. This strips the prefix and leaves the
 * link alone.
 *
 * WHICH NAME COUNTS. `client_label` is the authoritative field here: it is the
 * denormalised copy the list renders, and it is what the importer had in hand
 * when it built the title, so it is the string most likely to match. But a
 * client can have been renamed since the import, in which case the stored label
 * and the live client record disagree and only one of them is the prefix that
 * is really sitting in the title. So when `visns-packages.vault.client.model`
 * names a model, this also reads the live name for each entry's `client_id` and
 * tries both, preferring whichever match is longer - a longer match means more
 * of the title was genuinely the client's name rather than a coincidental
 * shared word. With no client model configured, the stored label is all there
 * is, and that is enough.
 *
 * WHAT COUNTS AS A PREFIX. The title has to *begin* with the name, followed by
 * an em dash, an en dash or a plain hyphen. Anything else is left alone: an
 * entry titled "Karrinyup Dental Centre reception PC" is not a prefixed title,
 * it is a title that happens to start with the client's name, and rewriting it
 * would destroy meaning rather than repeat it. If stripping the prefix would
 * leave nothing behind - the title was only ever the client's name - the row is
 * skipped, because an entry with no title is worse than an entry with a
 * redundant one.
 *
 * Always look first:
 *
 *     php artisan vault:strip-client-titles --dry-run
 */
class VaultStripClientTitlesCommand extends Command
{
    protected $signature = 'vault:strip-client-titles
        {--dry-run : Show what would change and write nothing}
        {--force : Skip the confirmation}
        {--chunk=200 : Rows to load at a time}';

    protected $description = 'Remove the duplicated client-name prefix from vault entry titles';

    /** How many rows the dry run is willing to print before it summarises the rest. */
    private const PREVIEW_LIMIT = 200;

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $planned = [];
        $skipped = 0;
        $unprefixed = 0;
        $seen = 0;

        // withTrashed: a soft-deleted entry can be restored by an administrator,
        // and one that was skipped here would come back months later still
        // carrying the doubled-up title - after the run that was supposed to
        // have fixed the whole vault.
        VaultEntry::withTrashed()
            ->whereNotNull('client_label')
            ->where('client_label', '!=', '')
            ->orderBy('id')
            ->chunkById($chunk, function ($entries) use (&$planned, &$skipped, &$unprefixed, &$seen) {
                $liveNames = $this->liveClientNames($entries);

                foreach ($entries as $entry) {
                    $seen++;

                    $title = (string) $entry->title;

                    // Both candidate names, longest first: if a client was
                    // renamed from "Karrinyup Dental" to "Karrinyup Dental
                    // Centre", trying the longer one first strips the whole
                    // prefix instead of leaving " Centre —" behind.
                    $labels = array_filter([
                        (string) $entry->client_label,
                        (string) ($liveNames[$entry->client_id] ?? ''),
                    ], fn($label) => trim($label) !== '');

                    $labels = array_unique($labels);

                    usort($labels, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

                    $stripped = null;

                    foreach ($labels as $label) {
                        $result = $this->strip($title, $label);

                        if ($result !== null) {
                            $stripped = $result;
                            break;
                        }
                    }

                    if ($stripped === null) {
                        $unprefixed++;

                        continue;
                    }

                    if ($stripped === '') {
                        // The title was the client's name and nothing else.
                        // There is no better title to put here, and a blank one
                        // would make the row unfindable in a list sorted by
                        // title.
                        $skipped++;

                        continue;
                    }

                    $planned[] = [
                        'id' => $entry->id,
                        'client' => (string) $entry->client_label,
                        'before' => $title,
                        'after' => $stripped,
                    ];
                }
            });

        if ($seen === 0) {
            $this->info('No vault entries have a client attached, so there are no titles to strip.');

            return self::SUCCESS;
        }

        $count = count($planned);

        if ($dryRun) {
            $this->preview($planned);
            $this->summarise($count, $skipped, $unprefixed, true);

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->summarise($count, $skipped, $unprefixed, false);

            return self::SUCCESS;
        }

        // The scan above ran to completion before anything is written, which
        // means the vault is read once to plan and then touched again to write.
        // That is deliberate: an operator cannot meaningfully agree to a rewrite
        // without being told how many titles it covers, and a number that is
        // only known halfway through is no use in the question.
        if (! $this->option('force')) {
            $confirmed = $this->confirm(sprintf(
                'Rewrite %d vault %s?',
                $count,
                $count === 1 ? 'title' : 'titles'
            ));

            if (! $confirmed) {
                // Declining is a decision, not a failure - nothing went wrong
                // and nothing needs retrying.
                $this->line('Nothing written.');

                return self::SUCCESS;
            }
        }

        $written = 0;

        foreach ($planned as $change) {
            $entry = VaultEntry::withTrashed()->find($change['id']);

            if ($entry === null) {
                // Deleted for real between the scan and the write. Nothing to
                // fix, and nothing worth stopping for.
                continue;
            }

            // Through the model, not the query builder: an application that has
            // subclassed VaultEntry with owen-it/laravel-auditing gets its audit
            // row from `save()` and nothing else. This is the opposite choice
            // from VaultReencryptCommand, which goes round the model on purpose
            // - re-encrypting a secret is not an edit and must not appear in a
            // history as one. Renaming a title IS an edit, and should look like
            // one to whoever reads the history later.
            $entry->title = $change['after'];
            $entry->save();

            $written++;
        }

        $this->summarise($written, $skipped, $unprefixed, false);

        return self::SUCCESS;
    }

    /**
     * The live client name for every client_id in this chunk.
     *
     * One query per chunk rather than one per row - a vault of a few thousand
     * entries would otherwise spend the whole run on single-row lookups. An
     * application with no client model configured gets an empty map and the
     * stored label does all the work.
     *
     * @param  iterable<int, VaultEntry>  $entries
     * @return array<int|string, string>
     */
    private function liveClientNames($entries): array
    {
        $model = config('visns-packages.vault.client.model');
        $column = (string) config('visns-packages.vault.client.label_column', 'name');

        if (! is_string($model) || $model === '' || ! class_exists($model)) {
            return [];
        }

        $ids = [];

        foreach ($entries as $entry) {
            if ($entry->client_id !== null && $entry->client_id !== '') {
                $ids[] = $entry->client_id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        try {
            $instance = new $model();

            return $model::query()
                ->whereIn($instance->getKeyName(), $ids)
                ->pluck($column, $instance->getKeyName())
                ->map(fn($name) => (string) $name)
                ->all();
        } catch (\Throwable $e) {
            // A misconfigured label column or a client table this application
            // does not actually have must not take the whole run down; the
            // stored labels are still perfectly usable on their own.
            $this->warn(sprintf('Could not read live client names (%s); using the stored labels only.', $e->getMessage()));

            return [];
        }
    }

    /**
     * The title with `$label` and its separator removed from the front, or null
     * if the title was not prefixed with that label at all.
     *
     * Returns '' when the whole title was the label - the caller decides what
     * that means, and the answer is "leave it alone".
     */
    private function strip(string $title, string $label): ?string
    {
        $title = trim($title);
        $label = trim($label);

        if ($title === '' || $label === '') {
            return null;
        }

        // preg_quote, always: a client called "Smith & Co. (WA)" is a perfectly
        // ordinary name and a perfectly awful regex.
        $quoted = preg_quote($label, '/');

        // \s does not cover U+00A0, and imported titles are full of them -
        // whatever produced the source data used non-breaking spaces around the
        // dashes. Spelling the class out is more honest than hoping /u makes \s
        // do something it does not promise.
        $space = '[\s\x{00A0}]*';

        // Em dash, en dash, plain hyphen. /u so the dashes and any multi-byte
        // characters in the label are matched as characters; /i so a title cased
        // differently from the stored label still matches.
        //
        // End-of-string counts as a separator as well, so a title that is the
        // client's name and nothing else reports an empty remainder and lands in
        // the skipped bucket - rather than being called "no prefix", which would
        // read in the summary as though there were nothing wrong with it.
        $dash = $space . '[\x{2014}\x{2013}-]' . $space;
        $pattern = '/^' . $quoted . '(?:' . $dash . '|' . $space . '$)/iu';

        $result = preg_replace($pattern, '', $title, 1, $replacements);

        if ($result === null || $replacements === 0) {
            return null;
        }

        return trim($result, " \t\n\r\0\x0B\u{00A0}");
    }

    /**
     * @param  array<int, array{id: mixed, client: string, before: string, after: string}>  $planned
     */
    private function preview(array $planned): void
    {
        if ($planned === []) {
            return;
        }

        $shown = array_slice($planned, 0, self::PREVIEW_LIMIT);

        $this->table(
            ['#', 'Client', 'Before', 'After'],
            array_map(
                fn(array $change) => [$change['id'], $change['client'], $change['before'], $change['after']],
                $shown
            )
        );

        $remaining = count($planned) - count($shown);

        if ($remaining > 0) {
            $this->line(sprintf(
                '... and %d more %s not shown.',
                $remaining,
                $remaining === 1 ? 'title' : 'titles'
            ));
        }
    }

    private function summarise(int $changed, int $skipped, int $unprefixed, bool $dryRun): void
    {
        $this->info(sprintf(
            '%s %d %s.',
            $dryRun ? 'Would rewrite' : 'Rewrote',
            $changed,
            $changed === 1 ? 'title' : 'titles'
        ));

        if ($skipped > 0) {
            $this->info(sprintf(
                'Skipped %d %s whose title was only the client name.',
                $skipped,
                $skipped === 1 ? 'entry' : 'entries'
            ));
        }

        $this->info(sprintf(
            '%d %s no client prefix.',
            $unprefixed,
            $unprefixed === 1 ? 'entry has' : 'entries have'
        ));
    }
}
