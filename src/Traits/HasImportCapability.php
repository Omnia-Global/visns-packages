<?php

namespace Visnsstudio\VisnsPackages\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait HasImportCapability
{
    /**
     * Handle import for a specific model and relation
     */
    public function handleImport(Request $request, $model, $id = null, $relation = null)
    {
        try {
            $action = $request->input('action', $request->route('action'));
            $importController = app(\Visnsstudio\VisnsPackages\Controllers\ImportController::class);
            
            switch ($action) {
                case 'parse-file':
                    return $importController->parseFile($request);
                
                case 'column-suggestions':
                    return $importController->getColumnSuggestions($request);
                
                case 'preview-mapping':
                    return $importController->previewMapping($request);
                
                case 'process':
                    // Add model-specific context
                    $request->merge([
                        'target_model' => $this->getTableNameForModel($model),
                        'parent_id' => $id,
                        'relation_key' => $this->getRelationKey($model, $relation)
                    ]);
                    
                    return $importController->processImport($request);
                
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid import action'
                    ], 400);
            }
            
        } catch (\Exception $e) {
            Log::error('Import handling error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error handling import: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get table name for model
     */
    private function getTableNameForModel($model)
    {
        // Convert model name to table name
        $tableName = strtolower($model);
        
        // Handle special cases
        $specialCases = [
            'visitrequests' => 'visit_request_data',
            'visitrequest' => 'visit_request_data'
        ];
        
        return $specialCases[$tableName] ?? $tableName;
    }

    /**
     * Get relation key for parent-child relationships
     */
    private function getRelationKey($model, $relation)
    {
        if (!$relation) {
            return null;
        }
        
        // Common relation key patterns
        $relationKeys = [
            'attendees' => 'visit_request_id',
            'bios' => 'visit_request_id',
            'notes' => 'visit_request_id',
            'files' => 'fileable_id'
        ];
        
        return $relationKeys[$relation] ?? null;
    }

    /**
     * Get import configuration for a specific model/relation
     */
    public function getImportConfig(Request $request, $model, $relation = null)
    {
        try {
            $configPath = resource_path("js/views/{$model}/detail.json");
            
            if (!file_exists($configPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Model configuration not found'
                ], 404);
            }
            
            $config = json_decode(file_get_contents($configPath), true);
            
            if (!$config) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid configuration file'
                ], 400);
            }
            
            // Extract field configuration for the specific relation/tab
            $fieldConfig = $this->extractFieldConfig($config, $relation);
            
            return response()->json([
                'success' => true,
                'config' => $fieldConfig,
                'model' => $model,
                'relation' => $relation
            ]);
            
        } catch (\Exception $e) {
            Log::error('Import config error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error getting import configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract field configuration from detail.json
     */
    private function extractFieldConfig($config, $relation = null)
    {
        if (!$relation) {
            // Return main form fields if no relation specified
            return $config['tabs'][0]['form']['fields'] ?? [];
        }
        
        // Find the tab for the specified relation
        foreach ($config['tabs'] as $tab) {
            if ($tab['id'] === $relation && isset($tab['form']['fields'])) {
                return array_filter($tab['form']['fields'], function($field) {
                    // Exclude hidden fields and system fields
                    return !in_array($field['type'] ?? '', ['hidden', 'line-break']) &&
                           !in_array($field['id'] ?? '', ['id', 'dataId', 'key']);
                });
            }
        }
        
        return [];
    }

    /**
     * Validate import permissions
     */
    protected function validateImportPermissions(Request $request, $model, $relation = null)
    {
        // Add permission checks here based on your auth system
        // Example:
        // if (!$request->user()->can('import', $model)) {
        //     return false;
        // }
        
        return true;
    }

    /**
     * Log import activity
     */
    protected function logImportActivity($model, $relation, $results, $userId = null)
    {
        try {
            Log::info('Import completed', [
                'model' => $model,
                'relation' => $relation,
                'imported' => $results['imported'] ?? 0,
                'errors' => count($results['errors'] ?? []),
                'user_id' => $userId ?? auth()->id(),
                'timestamp' => now()
            ]);
        } catch (\Exception $e) {
            // Don't fail import if logging fails
            Log::error('Import logging failed: ' . $e->getMessage());
        }
    }

    /**
     * Get import statistics for a model
     */
    public function getImportStats(Request $request, $model, $relation = null)
    {
        try {
            $tableName = $this->getTableNameForModel($model);
            
            // You can expand this to track import history
            // For now, just return basic table stats
            $totalRecords = \DB::table($tableName)->count();
            
            $stats = [
                'total_records' => $totalRecords,
                'last_import' => null, // Implement if you track import history
                'import_history' => [] // Implement if you track import history
            ];
            
            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Import stats error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error getting import statistics'
            ], 500);
        }
    }
}