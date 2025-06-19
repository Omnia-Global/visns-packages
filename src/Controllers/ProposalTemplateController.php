<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Visnsstudio\VisnsPackages\Models\ProposalTemplate;

class ProposalTemplateController extends \App\Http\Controllers\Controller
{
    /**
     * Get all proposal templates
     * Integrates with dynamic entity system for consistent API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $templates = ProposalTemplate::with('sections')
                ->when($request->search, function ($query, $search) {
                    $query->where('name', 'like', "%{$search}%")
                          ->orWhere('description', 'like', "%{$search}%");
                })
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching proposal templates: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching proposal templates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get templates for admin table view (integrates with GenericIndex/GenericGrid)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function table(Request $request)
    {
        try {
            $query = ProposalTemplate::query();

            // Apply search if provided
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            $sortBy = $request->get('sortBy', 'name');
            $sortOrder = $request->get('sort', 'asc');
            
            // Validate sort column for security
            $allowedSortColumns = ['id', 'name', 'description', 'is_default', 'created_at', 'updated_at'];
            if (in_array($sortBy, $allowedSortColumns)) {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Apply where clauses for filtering
            if ($request->filled('where')) {
                foreach ($request->where as $condition) {
                    if (isset($condition['id']) && isset($condition['value'])) {
                        $query->where($condition['id'], $condition['value']);
                    }
                }
            }

            // Get paginated results
            $perPage = $request->get('take', 25);
            $templates = $query->withCount('sections')->paginate($perPage);

            return response()->json($templates);
        } catch (\Exception $e) {
            Log::error('Error fetching proposal templates table: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching proposal templates table',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get templates for dropdown (integrates with existing dropdown patterns)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function dropdown(Request $request)
    {
        try {
            $templates = ProposalTemplate::select('id', 'name', 'description')
                ->when($request->search, function ($query, $search) {
                    $query->where('name', 'like', "%{$search}%");
                })
                ->orderBy('name')
                ->get()
                ->map(function ($template) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'description' => $template->description
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching proposal template dropdown: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching proposal template dropdown',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific proposal template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $template = ProposalTemplate::with('sections')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $template
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching proposal template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching proposal template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new proposal template
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'sections' => 'nullable|array',
                'variables' => 'nullable|array',
                'styling' => 'nullable|array',
                'is_default' => 'nullable|boolean',
            ]);

            // If setting as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                ProposalTemplate::where('is_default', true)->update(['is_default' => false]);
            }

            $template = ProposalTemplate::create($validated);

            // Create sections if provided
            if (isset($validated['sections'])) {
                foreach ($validated['sections'] as $index => $section) {
                    $template->sections()->create([
                        'section_type' => $section['type'] ?? 'content',
                        'title' => $section['title'] ?? '',
                        'content' => $section['content'] ?? '',
                        'sort_order' => $section['sort_order'] ?? $index,
                        'is_dynamic' => $section['is_dynamic'] ?? false,
                        'variables' => $section['variables'] ?? [],
                        'styling' => $section['styling'] ?? [],
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $template->load('sections'),
                'message' => 'Proposal template created successfully'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating proposal template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating proposal template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a proposal template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'sections' => 'nullable|array',
                'variables' => 'nullable|array',
                'styling' => 'nullable|array',
                'is_default' => 'nullable|boolean',
            ]);

            // If setting as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                ProposalTemplate::where('id', '!=', $id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $template->update($validated);

            // Update sections if provided
            if (isset($validated['sections'])) {
                // Delete existing sections
                $template->sections()->delete();
                
                // Create new sections
                foreach ($validated['sections'] as $index => $section) {
                    $template->sections()->create([
                        'section_type' => $section['type'] ?? 'content',
                        'title' => $section['title'] ?? '',
                        'content' => $section['content'] ?? '',
                        'sort_order' => $section['sort_order'] ?? $index,
                        'is_dynamic' => $section['is_dynamic'] ?? false,
                        'variables' => $section['variables'] ?? [],
                        'styling' => $section['styling'] ?? [],
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $template->load('sections'),
                'message' => 'Proposal template updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating proposal template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating proposal template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a proposal template
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $template = ProposalTemplate::findOrFail($id);
            
            // Don't allow deletion of default template
            if ($template->is_default) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete default template'
                ], 400);
            }

            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Proposal template deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting proposal template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting proposal template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
            $customVariables = config('visns-packages.proposal.custom_variables', []);

            return response()->json([
                'success' => true,
                'data' => [
                    'system' => $systemVariables,
                    'custom' => $customVariables
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching available variables: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching available variables',
                'error' => $e->getMessage()
            ], 500);
        }
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
            $proposalService = app(\Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class);
            
            $previewData = $proposalService->assembleProposal([
                'template_id' => $id,
                'proposal_data' => $sampleData,
                'sections' => $template->sections->toArray()
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'template' => $template,
                    'preview_html' => $previewData['html'],
                    'sections' => $previewData['sections']
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error previewing template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error previewing template',
                'error' => $e->getMessage()
            ], 500);
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
                'message' => 'Template duplicated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error duplicating template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error duplicating template',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}