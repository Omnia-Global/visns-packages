<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Laravel\Scout\Searchable;
use MeiliSearch\Client;
use Illuminate\Support\Facades\File;

class MeilisearchConfigureCommand extends Command
{
    protected $signature = 'meilisearch:configure
                            {--show : Show current configuration}
                            {--apply : Apply index settings from config}
                            {--model= : Configure specific model only}';

    protected $description = 'Configure Meilisearch index settings';

    protected Client $meilisearch;

    public function __construct(Client $meilisearch)
    {
        parent::__construct();
        $this->meilisearch = $meilisearch;
    }

    public function handle(): int
    {
        $this->info('⚙️  Meilisearch Configuration');
        $this->info('============================');

        if ($this->option('show')) {
            return $this->showConfiguration();
        }

        if ($this->option('apply')) {
            return $this->applyConfiguration();
        }

        $this->error('Please specify --show or --apply option.');
        return 1;
    }

    protected function showConfiguration(): int
    {
        $modelName = $this->option('model');
        $indexSettings = config('scout.meilisearch.index-settings', []);

        if (empty($indexSettings)) {
            $this->warn('No index settings found in configuration.');
            return 0;
        }

        foreach ($indexSettings as $indexName => $settings) {
            if ($modelName && !str_contains($indexName, strtolower($modelName))) {
                continue;
            }

            $this->displayIndexConfiguration($indexName, $settings);
        }

        return 0;
    }

    protected function applyConfiguration(): int
    {
        $modelName = $this->option('model');
        $indexSettings = config('scout.meilisearch.index-settings', []);

        if (empty($indexSettings)) {
            $this->warn('No index settings found in configuration.');
            return 0;
        }

        foreach ($indexSettings as $indexName => $settings) {
            if ($modelName && !str_contains($indexName, strtolower($modelName))) {
                continue;
            }

            $this->applyIndexSettings($indexName, $settings);
        }

        $this->info('✅ Configuration applied successfully!');
        return 0;
    }

    protected function displayIndexConfiguration(string $indexName, array $settings): void
    {
        $this->newLine();
        $this->info("📋 Index: {$indexName}");
        $this->info(str_repeat('=', strlen($indexName) + 10));

        // Check if index exists
        try {
            $index = $this->meilisearch->index($indexName);
            $stats = $index->stats();
            $this->info("📊 Documents: {$stats['numberOfDocuments']}");
            $this->info("🔄 Processing: " . ($stats['isIndexing'] ? 'Yes' : 'No'));
        } catch (\Exception $e) {
            $this->warn("⚠️  Index does not exist yet");
        }

        // Display configured settings
        foreach ($settings as $settingName => $settingValue) {
            $this->displaySetting($settingName, $settingValue);
        }
    }

    protected function displaySetting(string $name, $value): void
    {
        $formattedName = ucwords(str_replace(['_', '-'], ' ', $name));
        
        if (is_array($value)) {
            $this->info("🔧 {$formattedName}:");
            if ($name === 'synonyms') {
                foreach ($value as $word => $synonyms) {
                    $this->line("   • {$word} → " . implode(', ', $synonyms));
                }
            } else {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $this->line("   • " . json_encode($item));
                    } else {
                        $this->line("   • {$item}");
                    }
                }
            }
        } else {
            $this->info("🔧 {$formattedName}: {$value}");
        }
    }

    protected function applyIndexSettings(string $indexName, array $settings): void
    {
        $this->info("⚙️  Configuring {$indexName}...");

        try {
            $index = $this->meilisearch->index($indexName);

            // Apply each setting
            foreach ($settings as $settingName => $settingValue) {
                $this->applySingleSetting($index, $settingName, $settingValue);
            }

            $this->info("✅ {$indexName} configured successfully");

        } catch (\Exception $e) {
            $this->error("❌ Failed to configure {$indexName}: " . $e->getMessage());
        }
    }

    protected function applySingleSetting($index, string $settingName, $settingValue): void
    {
        try {
            switch ($settingName) {
                case 'searchableAttributes':
                    $index->updateSearchableAttributes($settingValue);
                    break;
                case 'filterableAttributes':
                    $index->updateFilterableAttributes($settingValue);
                    break;
                case 'sortableAttributes':
                    $index->updateSortableAttributes($settingValue);
                    break;
                case 'rankingRules':
                    $index->updateRankingRules($settingValue);
                    break;
                case 'typoTolerance':
                    $index->updateTypoTolerance($settingValue);
                    break;
                case 'synonyms':
                    $index->updateSynonyms($settingValue);
                    break;
                default:
                    $this->warn("⚠️  Unknown setting: {$settingName}");
            }
            
            $this->line("   ✓ {$settingName}");
            
        } catch (\Exception $e) {
            $this->error("   ✗ {$settingName}: " . $e->getMessage());
        }
    }
}