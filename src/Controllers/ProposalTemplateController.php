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

            // Get branding profile if specified
            $brandingId = $request->get('branding_profile_id');
            
            $previewData = $proposalService->assembleProposal([
                'template_id' => $id,
                'branding_id' => $brandingId,
                'proposal_data' => $sampleData,
                'sections' => $template->sections->toArray(),
                'header_config' => $template->styling['header'] ?? null,
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
                    'html' => $previewData['html'],
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

            // Get branding profile if specified
            $brandingId = $request->get('branding_profile_id');
            
            $proposalData = $proposalService->assembleProposal([
                'template_id' => $id,
                'branding_id' => $brandingId,
                'proposal_data' => $sampleData,
                'sections' => $template->sections->toArray(),
                'header_config' => $template->styling['header'] ?? null,
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

    /**
     * Get Agreement Signature template data for a specific template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAgreementSignature($id)
    {
        try {
            $template = ProposalTemplate::with('sections')->findOrFail($id);
            
            // Find the agreement_signature section
            $agreementSection = $template->sections()
                ->where('section_type', 'agreement_signature')
                ->first();

            if (!$agreementSection) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agreement Signature section not found for this template',
                ], 404);
            }

            // Parse the content to extract header, body, and fields
            $content = $agreementSection->content ?? '';
            $variables = $agreementSection->variables ?? [];
            
            // Extract headerText, bodyText, and fields from content
            $parsedContent = $this->parseAgreementSignatureContent($content, $variables);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $agreementSection->id,
                    'template_id' => $template->id,
                    'headerText' => $parsedContent['headerText'] ?? '',
                    'bodyText' => $parsedContent['bodyText'] ?? '',
                    'fields' => $parsedContent['fields'] ?? [],
                    'section_type' => $agreementSection->section_type,
                    'title' => $agreementSection->title,
                    'sort_order' => $agreementSection->sort_order,
                    'is_enabled' => $agreementSection->is_enabled ?? true,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching agreement signature template: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching agreement signature template',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Save Agreement Signature template data for a specific template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveAgreementSignature(Request $request, $id)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);

            $validated = $request->validate([
                'headerText' => 'nullable|string',
                'bodyText' => 'nullable|string',
                'fields' => 'nullable|array',
                'fields.*.id' => 'required|string',
                'fields.*.label' => 'required|string',
                'fields.*.type' => 'required|string|in:text,textarea,select,checkbox,radio,date,email,phone,signature',
                'fields.*.required' => 'boolean',
                'fields.*.options' => 'nullable|array',
                'fields.*.placeholder' => 'nullable|string',
                'fields.*.defaultValue' => 'nullable|string',
                'fields.*.validation' => 'nullable|array',
            ]);

            // Build the content structure for the agreement signature section
            $content = $this->buildAgreementSignatureContent(
                $validated['headerText'] ?? '',
                $validated['bodyText'] ?? '',
                $validated['fields'] ?? []
            );

            // Build variables array for template processing
            $variables = $this->buildAgreementSignatureVariables($validated['fields'] ?? []);

            // Find existing agreement_signature section or create new one
            $agreementSection = $template->sections()
                ->where('section_type', 'agreement_signature')
                ->first();

            if ($agreementSection) {
                // Update existing section
                $agreementSection->update([
                    'content' => $content,
                    'variables' => $variables,
                    'title' => 'Agreement & Signature',
                ]);
            } else {
                // Create new section
                $maxOrder = $template->sections()->max('sort_order') ?? 0;
                $agreementSection = $template->sections()->create([
                    'section_type' => 'agreement_signature',
                    'title' => 'Agreement & Signature',
                    'content' => $content,
                    'variables' => $variables,
                    'sort_order' => $maxOrder + 1,
                    'is_enabled' => true,
                    'is_dynamic' => false,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $agreementSection->id,
                    'template_id' => $template->id,
                    'headerText' => $validated['headerText'] ?? '',
                    'bodyText' => $validated['bodyText'] ?? '',
                    'fields' => $validated['fields'] ?? [],
                    'section_type' => $agreementSection->section_type,
                    'title' => $agreementSection->title,
                    'sort_order' => $agreementSection->sort_order,
                    'is_enabled' => $agreementSection->is_enabled,
                ],
                'message' => 'Agreement Signature template saved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving agreement signature template: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error saving agreement signature template',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Parse agreement signature content to extract header, body, and fields
     *
     * @param string $content
     * @param array $variables
     * @return array
     */
    private function parseAgreementSignatureContent($content, $variables)
    {
        // If content is empty, return default structure
        if (empty($content)) {
            return [
                'headerText' => '',
                'bodyText' => '',
                'fields' => [],
            ];
        }

        // Try to parse as JSON first (new format)
        $parsed = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return [
                'headerText' => $parsed['headerText'] ?? '',
                'bodyText' => $parsed['bodyText'] ?? '',
                'fields' => $parsed['fields'] ?? [],
            ];
        }

        // Fallback: parse as HTML/text content (legacy format)
        // For legacy support, we'll extract what we can from HTML content
        return [
            'headerText' => '',
            'bodyText' => $content,
            'fields' => [],
        ];
    }

    /**
     * Build agreement signature content structure
     *
     * @param string $headerText
     * @param string $bodyText
     * @param array $fields
     * @return string
     */
    private function buildAgreementSignatureContent($headerText, $bodyText, $fields)
    {
        return json_encode([
            'headerText' => $headerText,
            'bodyText' => $bodyText,
            'fields' => $fields,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Build variables array for agreement signature template processing
     *
     * @param array $fields
     * @return array
     */
    private function buildAgreementSignatureVariables($fields)
    {
        $variables = [];

        foreach ($fields as $field) {
            $fieldId = $field['id'] ?? '';
            $fieldLabel = $field['label'] ?? '';
            
            if (!empty($fieldId)) {
                $variables["{{agreement_field_{$fieldId}}}"] = $fieldLabel;
            }
        }

        return $variables;
    }

    /**
     * Get ALL database fields from ALL tables for enhanced variable selection
     * This provides a comprehensive list of all available fields for maximum flexibility
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllDatabaseFields()
    {
        try {
            $tables = [];
            $excludedTables = config('visns-packages.proposal.intelligent_variables.excluded_tables', [
                'migrations', 'password_resets', 'password_reset_tokens', 'failed_jobs',
                'personal_access_tokens', 'sessions', 'cache', 'cache_locks', 'jobs',
                'job_batches', 'notifications', 'telescope_entries', 'telescope_entries_tags',
                'telescope_monitoring', 'activity_log', 'media', 'permission_tables'
            ]);
            
            $excludedFields = config('visns-packages.proposal.intelligent_variables.global_exclusions', [
                'id', 'password', 'remember_token', 'email_verified_at', 'two_factor_secret',
                'two_factor_recovery_codes', 'created_at', 'updated_at', 'deleted_at'
            ]);

            // Get all tables in the database
            $connection = \Illuminate\Support\Facades\DB::connection();
            $databaseName = $connection->getDatabaseName();
            
            if ($connection->getDriverName() === 'mysql') {
                $allTables = $connection->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?", [$databaseName]);
                $tableNames = array_map(function($table) { return $table->TABLE_NAME; }, $allTables);
            } else if ($connection->getDriverName() === 'pgsql') {
                $allTables = $connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $tableNames = array_map(function($table) { return $table->tablename; }, $allTables);
            } else {
                // SQLite or other databases
                $tableNames = \Illuminate\Support\Facades\Schema::getAllTables();
                $tableNames = array_map(function($table) { return array_values((array)$table)[0]; }, $tableNames);
            }

            foreach ($tableNames as $tableName) {
                // Skip excluded tables
                if (in_array($tableName, $excludedTables)) {
                    continue;
                }

                try {
                    // Get column information for the table
                    $columns = \Illuminate\Support\Facades\Schema::getColumnListing($tableName);
                    
                    // Filter out excluded fields
                    $allowedColumns = array_diff($columns, $excludedFields);
                    
                    if (empty($allowedColumns)) {
                        continue;
                    }

                    $tableVariables = [];
                    foreach ($allowedColumns as $column) {
                        $fieldType = $this->getFieldType($tableName, $column);
                        $description = $this->generateFieldDescription($column, ucfirst($tableName));
                        
                        // Add main variable
                        $tableVariables[] = [
                            'name' => $tableName . '_' . $column,
                            'description' => $description,
                            'field_type' => $fieldType,
                            'original_field' => $column,
                            'table_name' => $tableName
                        ];
                        
                        // Add raw version for numeric/currency fields
                        if ($this->isNumericField($fieldType, $column)) {
                            $tableVariables[] = [
                                'name' => $tableName . '_' . $column . '_raw',
                                'description' => $description . ' (for calculations)',
                                'field_type' => 'float',
                                'original_field' => $column,
                                'table_name' => $tableName,
                                'is_calculation_version' => true
                            ];
                        }
                    }

                    if (!empty($tableVariables)) {
                        // Determine icon based on table name patterns
                        $icon = $this->getTableIcon($tableName);
                        
                        $tables[] = [
                            'category' => $this->formatTableName($tableName),
                            'icon' => $icon,
                            'table_name' => $tableName,
                            'variables' => $tableVariables,
                            'field_count' => count($tableVariables)
                        ];
                    }

                } catch (\Exception $tableError) {
                    Log::warning("Error processing table {$tableName}: " . $tableError->getMessage());
                    continue;
                }
            }

            // Sort tables by name for better UX
            usort($tables, function($a, $b) {
                return strcmp($a['category'], $b['category']);
            });

            return response()->json([
                'success' => true,
                'tables' => $tables,
                'total_tables' => count($tables),
                'total_variables' => array_sum(array_map(function($table) { 
                    return $table['field_count']; 
                }, $tables)),
                'excluded_tables' => $excludedTables,
                'excluded_fields' => $excludedFields
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching all database fields: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching database fields',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get appropriate icon for a table based on its name
     */
    private function getTableIcon($tableName)
    {
        $iconMap = [
            // Users & Auth
            'users' => 'User', 'user' => 'User', 'admins' => 'UserCheck', 'staff' => 'Users',
            // Customers & Clients
            'customers' => 'User', 'clients' => 'Users', 'contacts' => 'Contact',
            // Projects & Work
            'projects' => 'FolderOpen', 'jobs' => 'Briefcase', 'tasks' => 'CheckSquare',
            'designs' => 'PaintBucket', 'estimates' => 'Calculator', 'estimations' => 'Calculator',
            // Financial
            'quotes' => 'FileText', 'invoices' => 'Receipt', 'payments' => 'CreditCard',
            'transactions' => 'DollarSign', 'billing' => 'Receipt',
            // Content & Media
            'files' => 'File', 'images' => 'Image', 'documents' => 'FileText',
            'media' => 'Image', 'galleries' => 'Images',
            // Communication
            'messages' => 'MessageSquare', 'emails' => 'Mail', 'notifications' => 'Bell',
            'comments' => 'MessageCircle', 'notes' => 'StickyNote',
            // System & Config
            'settings' => 'Settings', 'configs' => 'Settings', 'preferences' => 'Sliders',
            'templates' => 'Layout', 'branding' => 'Palette',
            // Location & Address
            'addresses' => 'MapPin', 'locations' => 'MapPin', 'sites' => 'Building',
            // Contractors & Vendors
            'contractors' => 'Hammer', 'vendors' => 'Store', 'suppliers' => 'Truck',
            // Time & Scheduling
            'schedules' => 'Calendar', 'appointments' => 'Clock', 'timesheets' => 'Timer',
            // Quality & Issues
            'defects' => 'AlertTriangle', 'issues' => 'AlertCircle', 'reports' => 'BarChart3',
            // Categories & Types
            'categories' => 'Folder', 'types' => 'Tag', 'status' => 'CheckCircle'
        ];

        // Check for exact match first
        if (isset($iconMap[$tableName])) {
            return $iconMap[$tableName];
        }

        // Check for partial matches (singular/plural variations)
        foreach ($iconMap as $pattern => $icon) {
            if (str_contains($tableName, $pattern) || str_contains($pattern, rtrim($tableName, 's'))) {
                return $icon;
            }
        }

        // Default icon
        return 'Database';
    }

    /**
     * Format table name for display
     */
    private function formatTableName($tableName)
    {
        // Convert snake_case to Title Case and handle plurals nicely
        $formatted = str_replace('_', ' ', $tableName);
        $formatted = ucwords($formatted);
        
        // Add some context for common table patterns
        $patterns = [
            'Types' => 'Types & Categories',
            'Categories' => 'Categories & Classifications',
            'Status' => 'Status & States',
            'Config' => 'Configuration',
            'Setting' => 'Settings & Preferences'
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (str_contains($formatted, $pattern)) {
                $formatted = str_replace($pattern, $replacement, $formatted);
                break;
            }
        }

        return $formatted;
    }

    /**
     * Check if a field is numeric and should have a raw calculation version
     */
    private function isNumericField($fieldType, $fieldName)
    {
        // Common numeric field types
        $numericTypes = ['decimal', 'float', 'double', 'integer', 'bigint', 'smallint', 'tinyint', 'money'];
        
        if (in_array(strtolower($fieldType), $numericTypes)) {
            return true;
        }
        
        // Common numeric field name patterns
        $numericPatterns = [
            'price', 'cost', 'fee', 'amount', 'total', 'subtotal', 'budget', 'value',
            'rate', 'charge', 'sum', 'balance', 'payment', 'deposit', 'discount',
            'tax', 'gst', 'vat', 'markup', 'margin', 'commission', 'salary', 'wage'
        ];
        
        $fieldNameLower = strtolower($fieldName);
        foreach ($numericPatterns as $pattern) {
            if (str_contains($fieldNameLower, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
}
