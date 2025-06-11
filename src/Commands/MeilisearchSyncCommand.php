<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Scout\Searchable;
use MeiliSearch\Client;
use Symfony\Component\Finder\Finder;

class MeilisearchSyncCommand extends Command
{
    protected $signature = 'meilisearch:sync
                            {--model= : Specific model to sync (e.g., Customer)}
                            {--flush : Flush all records before syncing}
                            {--force : Force sync without confirmation}
                            {--chunk=100 : Number of records to sync per batch}
                            {--namespace= : Additional namespace to search for models}
                            {--path= : Additional path to search for model files}';

    protected $description = 'Sync searchable models with Meilisearch index';

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

        $this->info('🔍 Meilisearch Sync Command');
        $this->info('============================');

        $modelName = $this->option('model');
        $shouldFlush = $this->option('flush');
        $force = $this->option('force');
        $chunkSize = (int) $this->option('chunk');
        $customNamespace = $this->option('namespace');
        $customPath = $this->option('path');

        // Discover searchable models
        $models = $this->discoverSearchableModels($customNamespace, $customPath);

        if (empty($models)) {
            $this->error('No searchable models found.');
            return 1;
        }

        // Filter to specific model if requested
        if ($modelName) {
            $models = array_filter($models, function ($model) use ($modelName) {
                return class_basename($model) === $modelName;
            });

            if (empty($models)) {
                $this->error("Model '{$modelName}' not found or not searchable.");
                return 1;
            }
        }

        $this->table(['Model', 'Namespace', 'Status'], array_map(function ($model) {
            return [
                class_basename($model), 
                substr($model, 0, strrpos($model, '\\')),
                'Ready'
            ];
        }, $models));

        // Confirm action if not forced
        if (!$force && !$this->confirm('Do you want to proceed with syncing?')) {
            $this->info('Sync cancelled.');
            return 0;
        }

        // Process each model
        foreach ($models as $model) {
            $this->syncModel($model, $shouldFlush, $chunkSize);
        }

        $this->info('✅ Sync completed successfully!');
        return 0;
    }

    protected function discoverSearchableModels(?string $customNamespace = null, ?string $customPath = null): array
    {
        $models = [];
        
        // Get search paths from configuration and defaults
        $searchPaths = $this->getModelSearchPaths($customPath);
        $searchNamespaces = $this->getModelNamespaces($customNamespace);

        foreach ($searchPaths as $path) {
            if (!File::exists($path)) {
                continue;
            }

            $models = array_merge($models, $this->discoverModelsInPath($path, $searchNamespaces));
        }

        return array_unique($models);
    }

    protected function getModelSearchPaths(?string $customPath = null): array
    {
        $paths = [
            app_path('Models'),
            app_path(),
        ];

        // Add package model paths
        $packagePaths = config('visns-packages.model_paths', []);
        $paths = array_merge($paths, $packagePaths);

        // Add custom path if provided
        if ($customPath && File::exists($customPath)) {
            $paths[] = $customPath;
        }

        return array_filter($paths, fn($path) => File::exists($path));
    }

    protected function getModelNamespaces(?string $customNamespace = null): array
    {
        $namespaces = [
            'App\\Models\\',
            'App\\',
            'Visnsstudio\\VisnsPackages\\Models\\',
        ];

        // Add package model namespaces
        $packageNamespaces = config('visns-packages.model_namespaces', []);
        $namespaces = array_merge($namespaces, $packageNamespaces);

        // Add custom namespace if provided
        if ($customNamespace) {
            $namespaces[] = rtrim($customNamespace, '\\') . '\\';
        }

        return $namespaces;
    }

    protected function discoverModelsInPath(string $path, array $namespaces): array
    {
        $models = [];
        
        $finder = new Finder();
        $finder->files()->in($path)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $className = str_replace(['/', '.php'], ['\\', ''], $relativePath);

            foreach ($namespaces as $namespace) {
                $fullClassName = $namespace . $className;
                
                if (class_exists($fullClassName)) {
                    $reflection = new \ReflectionClass($fullClassName);
                    
                    if (!$reflection->isAbstract() && 
                        in_array(Searchable::class, $reflection->getTraitNames())) {
                        $models[] = $fullClassName;
                        break; // Found in this namespace, no need to check others
                    }
                }
            }
        }

        return $models;
    }

    protected function syncModel(string $model, bool $flush, int $chunkSize): void
    {
        $modelName = class_basename($model);
        $this->newLine();
        $this->info("📦 Processing {$modelName}...");

        try {
            // Get model instance to check index name
            $instance = new $model();
            $indexName = $instance->searchableAs();

            // Flush if requested
            if ($flush) {
                $this->warn("🗑️  Flushing {$indexName}...");
                $this->meilisearch->index($indexName)->deleteAllDocuments();
                $this->info("✅ Flushed {$indexName}");
            }

            // Get total count
            $totalCount = $model::count();
            $this->info("📊 Total {$modelName} records: {$totalCount}");

            if ($totalCount === 0) {
                $this->warn("⚠️  No records to sync for {$modelName}");
                return;
            }

            // Create progress bar
            $progressBar = $this->output->createProgressBar($totalCount);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %memory:6s%');
            $progressBar->start();

            // Sync in chunks
            $model::chunk($chunkSize, function ($records) use ($progressBar) {
                $records->searchable();
                $progressBar->advance($records->count());
            });

            $progressBar->finish();
            $this->newLine();
            $this->info("✅ {$modelName} sync completed");

        } catch (\Exception $e) {
            $this->error("❌ Failed to sync {$modelName}: " . $e->getMessage());
        }
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