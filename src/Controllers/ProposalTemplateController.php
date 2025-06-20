<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Visnsstudio\VisnsPackages\Models\ProposalTemplate;

class ProposalTemplateController extends \App\Http\Controllers\Controller
{
    // Standard CRUD operations (index, store, show, update, destroy, table, dropdown) 
    // are now handled by DynamicController to maintain consistency across the application.


    /**
     * Get available variables for templates
     * Returns system-defined and custom variables
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableVariables()
    {
        try {
            $systemVariables = [
                '{{customer_name}}' => 'Customer Name',
                '{{customer_address}}' => 'Customer Address',
                '{{quote_number}}' => 'Quote/Proposal Number',
                '{{quote_date}}' => 'Quote Date',
                '{{current_date}}' => 'Current Date',
                '{{total_amount}}' => 'Total Amount',
                '{{company_name}}' => 'Company Name',
                '{{company_address}}' => 'Company Address',
                '{{company_phone}}' => 'Company Phone',
                '{{company_email}}' => 'Company Email',
                '{{project_manager}}' => 'Project Manager',
                '{{due_date}}' => 'Due Date',
                '{{terms_and_conditions}}' => 'Terms and Conditions',
            ];

            // Get custom variables from config if available
            $customVariables = config(
                'visns-packages.proposal.custom_variables',
                []
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'system' => $systemVariables,
                    'custom' => $customVariables,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Error fetching available variables: ' . $e->getMessage()
            );
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching available variables',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get intelligent model-based variables for templates
     * Introspects configured Laravel models and returns their fields as available variables
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIntelligentVariables()
    {
        try {
            // Get configured models from project config
            $configuredModels = config('visns-packages.proposal.intelligent_variables.models', []);
            
            $variables = [];

            foreach ($configuredModels as $modelClass => $config) {
                // Validate that the model class exists
                if (!class_exists($modelClass)) {
                    Log::warning("Model class {$modelClass} not found for intelligent variables");
                    continue;
                }

                try {
                    // Create model instance to introspect
                    $model = new $modelClass();
                    
                    // Get table name for column inspection
                    $tableName = $model->getTable();
                    
                    // Get fillable fields (safe fields that can be mass assigned)
                    $fillableFields = $model->getFillable();
                    
                    // Get table columns using schema
                    $columns = \Illuminate\Support\Facades\Schema::getColumnListing($tableName);
                    
                    // Filter columns based on configuration
                    $allowedFields = [];
                    $excludeFields = $config['exclude'] ?? ['password', 'remember_token', 'email_verified_at'];
                    $includeFields = $config['include'] ?? [];
                    
                    // If include fields are specified, only use those
                    if (!empty($includeFields)) {
                        $allowedFields = array_intersect($columns, $includeFields);
                    } else {
                        // Otherwise, use all columns except excluded ones
                        $allowedFields = array_diff($columns, $excludeFields);
                    }
                    
                    // Build category information
                    $categoryName = $config['name'] ?? class_basename($modelClass);
                    $categoryIcon = $config['icon'] ?? 'FileText';
                    
                    $categoryVariables = [];
                    
                    foreach ($allowedFields as $field) {
                        // Generate human-readable description
                        $description = $this->generateFieldDescription($field, $categoryName);
                        
                        // Generate variable name (with model prefix)
                        $variableName = strtolower(class_basename($modelClass)) . '_' . $field;
                        
                        $categoryVariables[] = [
                            'name' => $variableName,
                            'description' => $description,
                            'field_type' => $this->getFieldType($tableName, $field),
                            'original_field' => $field
                        ];
                    }
                    
                    // Add relationships if configured
                    if (isset($config['relationships'])) {
                        foreach ($config['relationships'] as $relationshipName => $relationshipConfig) {
                            $relationshipVariables = $this->getRelationshipVariables(
                                $model, 
                                $relationshipName, 
                                $relationshipConfig,
                                $categoryName
                            );
                            $categoryVariables = array_merge($categoryVariables, $relationshipVariables);
                        }
                    }
                    
                    if (!empty($categoryVariables)) {
                        $variables[] = [
                            'category' => $categoryName,
                            'icon' => $categoryIcon,
                            'model_class' => $modelClass,
                            'variables' => $categoryVariables
                        ];
                    }
                    
                } catch (\Exception $modelError) {
                    Log::warning("Error processing model {$modelClass}: " . $modelError->getMessage());
                    continue;
                }
            }

            return response()->json([
                'success' => true,
                'variables' => $variables,
                'total_categories' => count($variables),
                'total_variables' => array_sum(array_map(function($cat) { 
                    return count($cat['variables']); 
                }, $variables))
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching intelligent variables: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching intelligent variables',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate human-readable description for a field
     */
    private function generateFieldDescription($field, $categoryName)
    {
        // Convert snake_case to Title Case
        $readable = str_replace('_', ' ', $field);
        $readable = ucwords($readable);
        
        // Add category context if it doesn't already contain it
        if (stripos($readable, $categoryName) === false) {
            return "{$categoryName} {$readable}";
        }
        
        return $readable;
    }

    /**
     * Get field type from database schema
     */
    private function getFieldType($tableName, $fieldName)
    {
        try {
            $columnType = \Illuminate\Support\Facades\DB::getSchemaBuilder()
                ->getColumnType($tableName, $fieldName);
            return $columnType;
        } catch (\Exception $e) {
            return 'string'; // Default fallback
        }
    }

    /**
     * Get variables from model relationships
     */
    private function getRelationshipVariables($model, $relationshipName, $relationshipConfig, $categoryName)
    {
        $variables = [];
        
        try {
            // Check if relationship method exists
            if (!method_exists($model, $relationshipName)) {
                return $variables;
            }
            
            // Get the related model class
            $relation = $model->$relationshipName();
            $relatedModel = $relation->getRelated();
            $relatedClass = get_class($relatedModel);
            
            // Get fields to include from relationship config
            $includeFields = $relationshipConfig['include'] ?? ['name', 'title', 'id'];
            $excludeFields = $relationshipConfig['exclude'] ?? [];
            
            // Get table columns for the related model
            $relatedColumns = \Illuminate\Support\Facades\Schema::getColumnListing($relatedModel->getTable());
            
            // Filter fields
            $allowedFields = array_diff(
                array_intersect($relatedColumns, $includeFields),
                $excludeFields
            );
            
            foreach ($allowedFields as $field) {
                $description = $this->generateFieldDescription($field, $relationshipConfig['name'] ?? $relationshipName);
                $variableName = strtolower($relationshipName) . '_' . $field;
                
                $variables[] = [
                    'name' => $variableName,
                    'description' => $description,
                    'field_type' => $this->getFieldType($relatedModel->getTable(), $field),
                    'original_field' => $field,
                    'relationship' => $relationshipName,
                    'related_model' => $relatedClass
                ];
            }
            
        } catch (\Exception $e) {
            Log::warning("Error processing relationship {$relationshipName}: " . $e->getMessage());
        }
        
        return $variables;
    }

    /**
     * Preview a template with sample data
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview(Request $request, $id)
    {
        try {
            $template = ProposalTemplate::with('sections')->findOrFail($id);

            // Sample data for preview
            $sampleData = [
                'customer_name' => 'Sample Customer Ltd',
                'customer_address' => '123 Business St, Sydney NSW 2000',
                'quote_number' => 'Q-2024-001',
                'quote_date' => date('Y-m-d'),
                'current_date' => date('Y-m-d'),
                'total_amount' => '$15,500.00',
                'company_name' => 'VISNS Studio',
                'company_address' => 'Sydney, NSW',
                'project_manager' => 'John Smith',
            ];

            // Use the proposal assembly service to build preview
            $proposalService = app(
                \Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class
            );

            $previewData = $proposalService->assembleProposal([
                'template_id' => $id,
                'proposal_data' => $sampleData,
                'sections' => $template->sections->toArray(),
            ]);

            // Check if request wants HTML response (for direct browser access)
            if ($request->header('Accept') && 
                str_contains($request->header('Accept'), 'text/html') && 
                !$request->ajax() && 
                !$request->wantsJson()) {
                
                // Return HTML response for direct browser access
                $html = '<!DOCTYPE html>
<html>
<head>
    <title>Proposal Template Preview - ' . htmlspecialchars($template->name) . '</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .preview-container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .preview-header { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eee; }
        .preview-title { color: #333; margin: 0 0 10px 0; }
        .preview-subtitle { color: #666; margin: 0; }
        .section { margin-bottom: 30px; }
        .section-title { color: #2563eb; margin-bottom: 15px; padding-bottom: 5px; border-bottom: 1px solid #ddd; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="preview-header">
            <h1 class="preview-title">Preview: ' . htmlspecialchars($template->name) . '</h1>
            <p class="preview-subtitle">This is a preview of your proposal template with sample data</p>
        </div>
        ' . $previewData['html'] . '
    </div>
</body>
</html>';
                
                return response($html, 200, ['Content-Type' => 'text/html']);
            }

            // Return JSON response for AJAX requests
            return response()->json([
                'success' => true,
                'data' => [
                    'template' => $template,
                    'preview_html' => $previewData['html'],
                    'sections' => $previewData['sections'],
                    'variables_used' => $previewData['variables_used'] ?? [],
                    'metadata' => $previewData['metadata'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error previewing template: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error previewing template',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Duplicate a template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function duplicate($id)
    {
        try {
            $original = ProposalTemplate::with('sections')->findOrFail($id);

            // Create new template
            $duplicate = ProposalTemplate::create([
                'name' => $original->name . ' (Copy)',
                'description' => $original->description,
                'variables' => $original->variables,
                'styling' => $original->styling,
                'is_default' => false,
            ]);

            // Duplicate sections
            foreach ($original->sections as $section) {
                $duplicate->sections()->create([
                    'section_type' => $section->section_type,
                    'title' => $section->title,
                    'content' => $section->content,
                    'sort_order' => $section->sort_order,
                    'is_dynamic' => $section->is_dynamic,
                    'variables' => $section->variables,
                    'styling' => $section->styling,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $duplicate->load('sections'),
                'message' => 'Template duplicated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error duplicating template: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error duplicating template',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Generate PDF from template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function generatePDF(Request $request, $id)
    {
        try {
            $template = ProposalTemplate::with('sections')->findOrFail($id);

            // Get sample data for PDF generation (similar to preview)
            $sampleData = [
                'customer_name' => 'Sample Customer Ltd',
                'customer_address' => '123 Business St, Sydney NSW 2000',
                'quote_number' => 'Q-2024-001',
                'quote_date' => date('Y-m-d'),
                'current_date' => date('Y-m-d'),
                'total_amount' => '$15,500.00',
                'company_name' => 'VISNS Studio',
                'company_address' => 'Sydney, NSW',
                'project_manager' => 'John Smith',
            ];

            // Use the proposal assembly service to build content
            $proposalService = app(
                \Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class
            );

            $proposalData = $proposalService->assembleProposal([
                'template_id' => $id,
                'proposal_data' => $sampleData,
                'sections' => $template->sections->toArray(),
            ]);

            // Use PDFController to generate the PDF
            $pdfController = app(\Visnsstudio\VisnsPackages\Controllers\PDFController::class);
            
            $pdfRequest = new \Illuminate\Http\Request();
            $pdfRequest->merge([
                'html' => $proposalData['html'],
                'filename' => 'proposal-template-' . $template->id . '.pdf',
                'options' => [
                    'format' => 'A4',
                    'orientation' => 'portrait',
                ]
            ]);

            return $pdfController->generatePDFFromHTML($pdfRequest);

        } catch (\Exception $e) {
            Log::error('Error generating PDF from template: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error generating PDF',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get sections for a specific template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSections($id)
    {
        try {
            $template = ProposalTemplate::with('sections')->findOrFail($id);

            return response()->json([
                'success' => true,
                'sections' => $template->sections,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching template sections: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching template sections',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Add a new section to a template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function addSection(Request $request, $id)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);

            $validated = $request->validate([
                'section_type' => 'required|string',
                'title' => 'required|string',
                'content' => 'nullable|string',
                'sort_order' => 'nullable|integer',
                'is_enabled' => 'nullable|boolean',
                'is_dynamic' => 'nullable|boolean',
                'variables' => 'nullable|array',
                'styling' => 'nullable|array',
            ]);

            // Set default sort order if not provided
            if (!isset($validated['sort_order'])) {
                $maxOrder = $template->sections()->max('sort_order') ?? 0;
                $validated['sort_order'] = $maxOrder + 1;
            }

            $section = $template->sections()->create($validated);

            return response()->json([
                'success' => true,
                'section' => $section,
                'message' => 'Section added successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error adding section: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error adding section',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Update a specific section
     *
     * @param Request $request
     * @param int $id
     * @param int $sectionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSection(Request $request, $id, $sectionId)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);
            $section = $template->sections()->findOrFail($sectionId);

            $validated = $request->validate([
                'section_type' => 'sometimes|string',
                'title' => 'sometimes|string',
                'content' => 'nullable|string',
                'sort_order' => 'sometimes|integer',
                'is_enabled' => 'sometimes|boolean',
                'is_dynamic' => 'sometimes|boolean',
                'variables' => 'nullable|array',
                'styling' => 'nullable|array',
            ]);

            $section->update($validated);

            return response()->json([
                'success' => true,
                'section' => $section->fresh(),
                'message' => 'Section updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating section: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error updating section',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Delete a specific section
     *
     * @param int $id
     * @param int $sectionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSection($id, $sectionId)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);
            $section = $template->sections()->findOrFail($sectionId);

            $section->delete();

            return response()->json([
                'success' => true,
                'message' => 'Section deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting section: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error deleting section',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Reorder sections for a template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorderSections(Request $request, $id)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);

            $validated = $request->validate([
                'sections' => 'required|array',
                'sections.*.id' => 'required|integer',
                'sections.*.sort_order' => 'required|integer',
            ]);

            foreach ($validated['sections'] as $sectionData) {
                $template->sections()
                    ->where('id', $sectionData['id'])
                    ->update(['sort_order' => $sectionData['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sections reordered successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error reordering sections: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error reordering sections',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }
}
