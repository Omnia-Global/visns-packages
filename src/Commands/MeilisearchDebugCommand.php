<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use MeiliSearch\Client;

class MeilisearchDebugCommand extends Command
{
    protected $signature = 'meilisearch:debug
                            {--model= : Specific model to debug (e.g., User, Customer)}';

    protected $description = 'Debug Meilisearch connection and index status';

    protected ?Client $meilisearch;

    public function __construct(?Client $meilisearch = null)
    {
        parent::__construct();
        $this->meilisearch = $meilisearch;
    }

    public function handle(): int
    {
        if (!$this->isAvailable()) {
            $this->error('MeiliSearch is not configured. Please install laravel/scout and meilisearch/meilisearch-php packages and configure them.');
            return 1;
        }

        $this->info('🔍 Meilisearch Debug Information');
        $this->info('===============================');

        // Check Meilisearch connection
        $this->checkConnection();
        
        // Check index status
        $this->checkIndexStatus();
        
        // Check sample model data
        $this->checkSampleData();
        
        // Test raw search
        $this->testRawSearch();

        return 0;
    }

    protected function checkConnection(): void
    {
        $this->newLine();
        $this->info('🔌 Connection Test');
        $this->info('==================');

        try {
            $health = $this->meilisearch->health();
            $this->info("✅ Meilisearch is healthy: " . $health['status']);
            
            $version = $this->meilisearch->version();
            $this->info("📦 Version: " . $version['pkgVersion']);
            
        } catch (\Exception $e) {
            $this->error("❌ Connection failed: " . $e->getMessage());
            return;
        }
    }

    protected function checkIndexStatus(): void
    {
        $this->newLine();
        $this->info('📊 Index Status');
        $this->info('===============');

        $model = $this->getDebugModel();
        if (!$model) {
            $this->warn('⚠️  No searchable model found for debugging');
            return;
        }

        try {
            $indexName = $model->searchableAs();
            $index = $this->meilisearch->index($indexName);
            $stats = $index->stats();
            
            $this->info("📋 Index: {$indexName}");
            $this->info("📈 Documents: " . $stats['numberOfDocuments']);
            $this->info("🔄 Indexing: " . ($stats['isIndexing'] ? 'Yes' : 'No'));
            if (isset($stats['databaseSize'])) {
                $this->info("💾 Database size: " . $stats['databaseSize'] . ' bytes');
            }
            
            // Check index settings
            $this->info("\n🔧 Index Settings:");
            $searchableAttrs = $index->getSearchableAttributes();
            $this->info("   Searchable attributes: " . count($searchableAttrs));
            
            $filterableAttrs = $index->getFilterableAttributes();
            $this->info("   Filterable attributes: " . count($filterableAttrs));
            
        } catch (\Exception $e) {
            $this->error("❌ Index check failed: " . $e->getMessage());
        }
    }

    protected function checkSampleData(): void
    {
        $this->newLine();
        $this->info('🎯 Sample Data Check');
        $this->info('====================');

        $model = $this->getDebugModel();
        if (!$model) {
            $this->warn('⚠️  No searchable model found for debugging');
            return;
        }

        try {
            $modelClass = get_class($model);
            $sampleRecord = $modelClass::first();
            
            if (!$sampleRecord) {
                $this->warn("⚠️  No records found in {$modelClass}");
                return;
            }
            
            $this->info("📋 Sample record: {$sampleRecord->getKey()}");
            if (method_exists($sampleRecord, 'name') || isset($sampleRecord->name)) {
                $this->info("📝 Name: {$sampleRecord->name}");
            }
            
            // Check what would be indexed
            $searchableData = $sampleRecord->toSearchableArray();
            $this->info("🔍 Searchable fields: " . count($searchableData));
            
            // Show first few fields as sample
            $sampleFields = array_slice($searchableData, 0, 3, true);
            foreach ($sampleFields as $field => $value) {
                $displayValue = is_array($value) ? '[Array with ' . count($value) . ' items]' : 
                               (is_string($value) ? substr($value, 0, 50) . (strlen($value) > 50 ? '...' : '') : $value);
                $this->info("   {$field}: {$displayValue}");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Sample data check failed: " . $e->getMessage());
        }
    }

    protected function testRawSearch(): void
    {
        $this->newLine();
        $this->info('🔍 Raw Search Test');
        $this->info('==================');

        $model = $this->getDebugModel();
        if (!$model) {
            $this->warn('⚠️  No searchable model found for debugging');
            return;
        }

        try {
            $indexName = $model->searchableAs();
            $index = $this->meilisearch->index($indexName);
            
            // Test basic search
            $results = $index->search('');
            $totalHits = $results->getEstimatedTotalHits();
            $this->info("📊 Total documents in index: {$totalHits}");
            
            if ($totalHits > 0) {
                $firstHit = $results->getHits()[0];
                $this->info("🎯 First document ID: " . $firstHit['id']);
                
                if (isset($firstHit['name'])) {
                    $this->info("🏷️  First document name: " . $firstHit['name']);
                }
                
                // Test a simple search
                $testResults = $index->search('test');
                $testHits = $testResults->getEstimatedTotalHits();
                $this->info("🔎 'test' search results: {$testHits}");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Raw search test failed: " . $e->getMessage());
        }
    }

    protected function getDebugModel(): ?Model
    {
        $modelName = $this->option('model');
        
        if ($modelName) {
            return $this->getModelByName($modelName);
        }

        // Auto-discover searchable models by scanning the App\Models directory
        return $this->discoverSearchableModel();
    }

    protected function getModelByName(string $modelName): ?Model
    {
        $possibleClasses = [
            "App\\Models\\{$modelName}",
            "App\\{$modelName}",
            "Visnsstudio\\VisnsPackages\\Models\\{$modelName}",
        ];

        foreach ($possibleClasses as $className) {
            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);
                
                if (in_array(Searchable::class, $reflection->getTraitNames())) {
                    return new $className();
                }
            }
        }

        return null;
    }

    protected function discoverSearchableModel(): ?Model
    {
        // Try to find any searchable model in common locations
        $searchPaths = [
            app_path('Models'),
            app_path(),
        ];

        foreach ($searchPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/*.php');
            foreach ($files as $file) {
                $className = pathinfo($file, PATHINFO_FILENAME);
                
                $model = $this->getModelByName($className);
                if ($model) {
                    return $model;
                }
            }
        }

        return null;
    }

    /**
     * Check if MeiliSearch is available and configured.
     */
    protected function isAvailable(): bool
    {
        return $this->meilisearch !== null &&
               class_exists(Client::class) &&
               class_exists(Searchable::class) &&
               config('scout.driver') === 'meilisearch';
    }
}