<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use MeiliSearch\Client;

class MeilisearchTestCommand extends Command
{
    protected $signature = 'meilisearch:test
                            {query : Search query to test}
                            {--model=User : Model to search (default: User)}
                            {--limit=10 : Number of results to return}
                            {--filters= : Filters to apply (e.g., "status = active")}';

    protected $description = 'Test Meilisearch search functionality';

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

        $query = $this->argument('query');
        $modelName = $this->option('model');
        $limit = (int) $this->option('limit');
        $filters = $this->option('filters');

        $this->info('🔍 Meilisearch Test Search');
        $this->info('==========================');

        // Get the model class
        $modelClass = $this->getModelClass($modelName);
        if (!$modelClass) {
            $this->error("Model '{$modelName}' not found or not searchable.");
            return 1;
        }

        $this->info("Model: {$modelName}");
        $this->info("Query: '{$query}'");
        $this->info("Limit: {$limit}");
        if ($filters) {
            $this->info("Filters: {$filters}");
        }
        $this->newLine();

        try {
            // Perform search using Scout
            $searchBuilder = $modelClass::search($query)->take($limit);
            
            if ($filters) {
                // Parse and apply filters
                $searchBuilder->where($filters);
            }

            $results = $searchBuilder->get();

            $this->info("📊 Results found: {$results->count()}");
            $this->newLine();

            if ($results->isEmpty()) {
                $this->warn('No results found.');
                return 0;
            }

            // Display results in a table
            $this->displayResults($results, $modelName);

            // Test direct Meilisearch API
            $this->testDirectMeilisearch($query, $modelClass, $filters, $limit);

        } catch (\Exception $e) {
            $this->error("❌ Search failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function getModelClass(string $modelName): ?string
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
                    return $className;
                }
            }
        }

        return null;
    }

    protected function displayResults($results, string $modelName): void
    {
        $tableData = [];
        $headers = ['ID'];
        
        // Dynamically determine what fields to show based on the first result
        if ($results->isNotEmpty()) {
            $firstResult = $results->first();
            $displayFields = $this->getDisplayFields($firstResult);
            
            foreach ($results as $result) {
                $row = [$result->getKey()];
                
                foreach ($displayFields as $field => $label) {
                    $value = $this->getFieldValue($result, $field);
                    $row[] = $value;
                }
                
                $tableData[] = $row;
            }
            
            $headers = array_merge($headers, array_values($displayFields));
        }

        $this->table($headers, $tableData);
    }

    protected function getDisplayFields($model): array
    {
        $fields = [];
        
        // Common identification fields
        if (isset($model->name)) {
            $fields['name'] = 'Name';
        } elseif (isset($model->title)) {
            $fields['title'] = 'Title';
        }
        
        // Contact information
        if (isset($model->email)) {
            $fields['email'] = 'Email';
        }
        
        // Additional common fields
        if (isset($model->status)) {
            $fields['status'] = 'Status';
        }
        
        // Always show created_at if available
        if (isset($model->created_at)) {
            $fields['created_at'] = 'Created';
        }
        
        // If no common fields found, try to get searchable array keys
        if (empty($fields) && method_exists($model, 'toSearchableArray')) {
            $searchableData = $model->toSearchableArray();
            $keys = array_keys($searchableData);
            
            // Take first 3 non-id fields
            foreach (array_slice($keys, 0, 3) as $key) {
                if ($key !== 'id') {
                    $fields[$key] = ucfirst(str_replace('_', ' ', $key));
                }
            }
        }
        
        return $fields;
    }

    protected function getFieldValue($model, string $field)
    {
        try {
            $value = $model->{$field} ?? 'N/A';
            
            // Format specific field types
            if ($field === 'created_at' && $value instanceof \Carbon\Carbon) {
                return $value->format('Y-m-d');
            }
            
            // Truncate long strings
            if (is_string($value) && strlen($value) > 50) {
                return substr($value, 0, 47) . '...';
            }
            
            // Handle arrays
            if (is_array($value)) {
                return '[Array: ' . count($value) . ' items]';
            }
            
            return $value ?: 'N/A';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    protected function testDirectMeilisearch(string $query, string $modelClass, ?string $filters, int $limit): void
    {
        $this->newLine();
        $this->info('🔧 Direct Meilisearch API Test');
        $this->info('==============================');

        try {
            $instance = new $modelClass();
            $indexName = $instance->searchableAs();
            
            $searchParams = [
                'limit' => $limit,
                'attributesToHighlight' => ['name', 'email', 'title'],
            ];

            if ($filters) {
                $searchParams['filter'] = $filters;
            }

            $results = $this->meilisearch->index($indexName)->search($query, $searchParams);

            $this->info("Raw hits: {$results->getEstimatedTotalHits()}");
            $this->info("Processing time: {$results->getProcessingTimeMs()}ms");
            
            $hits = $results->getHits();
            if (!empty($hits)) {
                $this->info("First result: " . json_encode($hits[0], JSON_PRETTY_PRINT));
            }

        } catch (\Exception $e) {
            $this->error("❌ Direct API test failed: " . $e->getMessage());
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