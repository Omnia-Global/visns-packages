<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Models\ReportBuilder;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\LegacyDefinitionConverter;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\QueryCompiler;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticException;

/**
 * Upgrade saved legacy (v1, table-and-join) report definitions to the v2
 * semantic format, where the application's semantic model can express them.
 *
 * Each conversion is compiled and run (limit 1) through the same compiler the
 * execute endpoint uses before anything is written, so a definition the engine
 * would reject is reported as failed and left untouched. Every row that is
 * rewritten is first appended, as it was, to a JSON-lines backup file.
 *
 * Always look first:
 *
 *     php artisan report-builder:upgrade-legacy --dry-run
 *
 * See docs/report-semantics.md, section 11, for the mapping rules.
 */
class ReportBuilderUpgradeLegacyCommand extends Command
{
    protected $signature = 'report-builder:upgrade-legacy
                            {--dry-run : Show what would change and the resulting v2 JSON without writing}
                            {--id=* : Only these report ids (repeatable)}
                            {--backup-path= : JSON-lines file the original rows are appended to (default storage/app/report-builder-legacy-backup-<YmdHis>.jsonl)}';

    protected $description = 'Upgrade legacy (v1) report-builder definitions to the v2 semantic format where possible';

    public function handle(LegacyDefinitionConverter $converter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = array_values(array_filter(
            array_map('intval', (array) $this->option('id')),
            static fn ($id) => $id > 0
        ));

        // Resolved from the container, exactly as the controller does, so the
        // validation run uses the application's own model and connection.
        $compiler = app(QueryCompiler::class);
        $model = $compiler->model();

        $query = ReportBuilder::query()->orderBy('id');

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $reports = $query->get();

        if ($reports->isEmpty()) {
            $this->info('No report-builder rows found' . (empty($ids) ? '.' : ' for the given ids.'));
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing will be written.');
        }

        $backupPath = null;
        $counts = ['converted' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($reports as $report) {
            $detail = $this->decodedDetail($report);

            $this->newLine();
            $this->line("<options=bold>#{$report->id} {$report->label}</>");

            if (!LegacyDefinitionConverter::isLegacyDefinition($detail)) {
                $this->line('  skipped (not a v1 definition - already v2 or unrecognised)');
                $counts['skipped']++;
                continue;
            }

            $result = $converter->convert($detail, $model);

            if (!$result->isConvertible()) {
                $this->line("  <fg=yellow>skipped ({$result->reason})</>");
                $this->printNotes($result->notes);
                $counts['skipped']++;
                continue;
            }

            $definition = $result->definition;
            $validationError = $this->validate($compiler, $definition);

            if ($validationError !== null) {
                $this->line("  <fg=red>failed (the v2 engine rejected the converted definition: {$validationError})</>");
                $this->printNotes($result->notes);
                $this->printDefinition($definition);
                $counts['failed']++;
                continue;
            }

            if ($dryRun) {
                $this->line('  <fg=green>converted</> (dry run, not written)');
                $this->printNotes($result->notes);
                $this->printDefinition($definition);
                $counts['converted']++;
                continue;
            }

            if ($backupPath === null) {
                $backupPath = $this->prepareBackupFile();
                $this->info("Backing up original rows to {$backupPath}");
            }

            $this->appendBackup($backupPath, $report);

            $report->detail = $definition;
            $report->save();

            $this->line('  <fg=green>converted</>');
            $this->printNotes($result->notes);
            $counts['converted']++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d converted, %d skipped, %d failed.',
            $dryRun ? 'Dry run' : 'Done',
            $counts['converted'],
            $counts['skipped'],
            $counts['failed']
        ));

        if ($backupPath !== null) {
            $this->info("Originals backed up to {$backupPath}");
        }

        return self::SUCCESS;
    }

    /**
     * `detail` as an array, tolerating a double-encoded string.
     */
    private function decodedDetail(ReportBuilder $report)
    {
        $detail = $report->detail;

        if (is_string($detail)) {
            $decoded = json_decode($detail, true);
            $detail = is_array($decoded) ? $decoded : $detail;
        }

        return $detail;
    }

    /**
     * Compile and run the definition (limit 1). Returns the reason it was
     * rejected, or null when it runs.
     */
    private function validate(QueryCompiler $compiler, array $definition): ?string
    {
        try {
            $compiler->execute($definition, [], 1);
        } catch (SemanticException $e) {
            return $e->getMessage();
        } catch (\Illuminate\Database\QueryException $e) {
            return 'database error: ' . $e->getMessage();
        }

        return null;
    }

    private function prepareBackupFile(): string
    {
        $path = $this->option('backup-path')
            ?: storage_path('app/report-builder-legacy-backup-' . date('YmdHis') . '.jsonl');

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create backup directory [{$directory}].");
        }

        if (!is_writable($directory) || (file_exists($path) && !is_writable($path))) {
            throw new \RuntimeException("Backup file [{$path}] is not writable.");
        }

        return $path;
    }

    /**
     * Append the row as it is now. Throws (aborting before the row is
     * rewritten) if the line cannot be written.
     */
    private function appendBackup(string $path, ReportBuilder $report): void
    {
        $raw = $report->getRawOriginal('detail');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        $line = json_encode([
            'id' => $report->id,
            'label' => $report->label,
            'detail' => $decoded ?? $raw,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Could not write to backup file [{$path}].");
        }
    }

    private function printNotes(array $notes): void
    {
        foreach ($notes as $note) {
            $this->line("  - {$note}");
        }
    }

    private function printDefinition(array $definition): void
    {
        $json = json_encode(
            $definition,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        foreach (explode("\n", (string) $json) as $line) {
            $this->line('    ' . $line);
        }
    }
}
