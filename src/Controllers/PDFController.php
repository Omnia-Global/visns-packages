<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Barryvdh\DomPDF\Facade\Pdf;

class PDFController extends \App\Http\Controllers\Controller
{
    /**
     * Generate a PDF from a view
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generatePDF(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'view' => 'required|string',
                'data' => 'nullable|array',
                'filename' => 'nullable|string',
                'paper' => 'nullable|string',
                'orientation' => 'nullable|string|in:portrait,landscape',
                'download' => 'nullable|boolean',
            ]);

            // Get view name
            $viewName = $validated['view'];

            // Get data for the view
            $data = $validated['data'] ?? [];

            // Get filename (default: generated-pdf.pdf)
            $filename = $validated['filename'] ?? 'generated-pdf.pdf';

            // Get paper size (default: a4)
            $paper = $validated['paper'] ?? 'a4';

            // Get orientation (default: portrait)
            $orientation = $validated['orientation'] ?? 'portrait';

            // Check if view exists
            if (!View::exists($viewName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "View '{$viewName}' does not exist",
                    ],
                    404
                );
            }

            // Set default options to better handle CSS and layouts
            $defaultOptions = [
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'isPhpEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150,
                'defaultPaperSize' => $paper,
                'defaultMediaType' => 'screen',
                'defaultPaperOrientation' => $orientation,
                'isFontSubsettingEnabled' => true,
            ];

            // Generate PDF with improved options
            $pdf = PDF::loadView($viewName, $data)
                ->setOptions($defaultOptions)
                ->setPaper($paper, $orientation);

            // Return PDF as download or inline
            $download = $validated['download'] ?? true;

            if ($download) {
                return $pdf->download($filename);
            } else {
                return $pdf->stream($filename);
            }
        } catch (\Exception $e) {
            Log::error('Error generating PDF: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

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
     * Generate a PDF from HTML content
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generatePDFFromHTML(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'html' => 'required|string',
                'filename' => 'nullable|string',
                'paper' => 'nullable|string',
                'orientation' => 'nullable|string|in:portrait,landscape',
                'download' => 'nullable|boolean',
            ]);

            // Get HTML content
            $html = $validated['html'];

            // Get filename (default: generated-pdf.pdf)
            $filename = $validated['filename'] ?? 'generated-pdf.pdf';

            // Get paper size (default: a4)
            $paper = $validated['paper'] ?? 'a4';

            // Get orientation (default: portrait)
            $orientation = $validated['orientation'] ?? 'portrait';

            // Set default options to better handle CSS and layouts
            $defaultOptions = [
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'isPhpEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150,
                'defaultPaperSize' => $paper,
                'defaultMediaType' => 'screen',
                'defaultPaperOrientation' => $orientation,
                'isFontSubsettingEnabled' => true,
            ];

            // Generate PDF with improved options
            $pdf = PDF::loadHTML($html)
                ->setOptions($defaultOptions)
                ->setPaper($paper, $orientation);

            // Return PDF as download or inline
            $download = $validated['download'] ?? true;

            if ($download) {
                return $pdf->download($filename);
            } else {
                return $pdf->stream($filename);
            }
        } catch (\Exception $e) {
            Log::error('Error generating PDF from HTML: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error generating PDF from HTML',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Generate a PDF with custom options
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateCustomPDF(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'view' => 'required_without:html|string',
                'html' => 'required_without:view|string',
                'data' => 'nullable|array',
                'filename' => 'nullable|string',
                'options' => 'nullable|array',
                'download' => 'nullable|boolean',
            ]);

            // Get filename (default: generated-pdf.pdf)
            $filename = $validated['filename'] ?? 'generated-pdf.pdf';

            // Set default options to better handle CSS and layouts
            $defaultOptions = [
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'isPhpEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150,
                'defaultPaperSize' => 'a4',
                'defaultMediaType' => 'screen',
                'isFontSubsettingEnabled' => true,
            ];

            // Merge with user-provided options
            $options = array_merge(
                $defaultOptions,
                $validated['options'] ?? []
            );

            // Initialize PDF
            $pdf = PDF::setOptions($options);

            // Load content from view or HTML
            if (isset($validated['view'])) {
                $viewName = $validated['view'];
                $data = $validated['data'] ?? [];

                // Check if view exists
                if (!View::exists($viewName)) {
                    return response()->json(
                        [
                            'success' => false,
                            'message' => "View '{$viewName}' does not exist",
                        ],
                        404
                    );
                }

                $pdf->loadView($viewName, $data);
            } else {
                $html = $validated['html'];
                $pdf->loadHTML($html);
            }

            // Return PDF as download or inline
            $download = $validated['download'] ?? true;

            if ($download) {
                return $pdf->download($filename);
            } else {
                return $pdf->stream($filename);
            }
        } catch (\Exception $e) {
            Log::error('Error generating custom PDF: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error generating custom PDF',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Generate a Quote PDF with proper styling
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateQuotePDF(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'view' => 'required|string',
                'data' => 'required|array',
                'filename' => 'nullable|string',
                'paper' => 'nullable|string',
                'orientation' => 'nullable|string|in:portrait,landscape',
                'download' => 'nullable|boolean',
            ]);

            // Get view name
            $viewName = $validated['view'];

            // Get data for the view
            $data = $validated['data'] ?? [];

            // Get filename (default: quote.pdf)
            $filename = $validated['filename'] ?? 'quote.pdf';

            // Get paper size (default: a4)
            $paper = $validated['paper'] ?? 'a4';

            // Get orientation (default: portrait)
            $orientation = $validated['orientation'] ?? 'portrait';

            // Check if view exists
            if (!View::exists($viewName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "View '{$viewName}' does not exist",
                    ],
                    404
                );
            }

            // Set enhanced options for quote PDFs
            $options = [
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'isPhpEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150,
                'defaultPaperSize' => $paper,
                'defaultMediaType' => 'screen',
                'defaultPaperOrientation' => $orientation,
                'isFontSubsettingEnabled' => true,
                'isJavascriptEnabled' => true,
                'debugKeepTemp' => true,
                'debugCss' => true,
                'chroot' => public_path(),
            ];

            // Add CSS for quote styling
            $css = '
                body { font-family: Arial, sans-serif; margin: 20px; }
                .quotecontainer { width: 100%; }
                .qitem { margin-bottom: 20px; width: 100%; display: flex; flex-wrap: wrap; }
                .qitem__half { width: 50%; box-sizing: border-box; }
                .qitem__full { width: 100%; }
                .qtitle { font-size: 24px; font-weight: bold; }
                .qdate, .qno { margin-bottom: 10px; }
                .qdetails, .qvisns { margin-bottom: 15px; }
                .content-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                .content-table th { background-color: #f2f2f2; font-weight: bold; text-align: left; padding: 8px; border: 1px solid #ddd; }
                .content-table td { padding: 8px; border: 1px solid #ddd; }
                .content-table tr:nth-child(even) { background-color: #f9f9f9; }
                .splitheader { background-color: #e0e0e0; }
                .splittitle { margin: 5px 0; font-size: 18px; }
            ';

            // Generate PDF with enhanced options and CSS
            $pdf = PDF::loadView($viewName, $data)
                ->setOptions($options)
                ->setPaper($paper, $orientation);

            // Add inline CSS to the PDF
            $pdf->getDomPDF()->addInfo('css', $css);

            // Return PDF as download or inline
            $download = $validated['download'] ?? true;

            if ($download) {
                return $pdf->download($filename);
            } else {
                return $pdf->stream($filename);
            }
        } catch (\Exception $e) {
            Log::error('Error generating Quote PDF: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error generating Quote PDF',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Generate a Proposal PDF with multi-page support
     * Backward compatible - leverages existing PDF generation infrastructure
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateProposalPDF(Request $request)
    {
        try {
            // Log the incoming request for debugging
            Log::info('PDFController::generateProposalPDF - Incoming request data', [
                'request_keys' => array_keys($request->all()),
                'has_header_config' => $request->has('header_config'),
                'header_config_raw' => $request->input('header_config'),
            ]);

            // Handle JSON string from form submission
            $proposalData = $request->input('proposal_data');
            if (is_string($proposalData)) {
                $proposalData = json_decode($proposalData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON in proposal_data: ' . json_last_error_msg());
                }
            }
            
            // Validate request - now proposal_data can be either array or string (we'll parse it)
            $validated = $request->validate([
                'proposal_data' => 'required',
                'template_id' => 'nullable|integer',
                'branding_id' => 'nullable|integer',
                'filename' => 'nullable|string',
                'paper' => 'nullable|string',
                'orientation' => 'nullable|string|in:portrait,landscape',
                'download' => 'nullable|boolean',
                'sections' => 'nullable|array',
                'header_config' => 'nullable|array',
            ]);
            
            // Log validation results
            Log::info('PDFController::generateProposalPDF - Validation results', [
                'header_config_validated' => $validated['header_config'] ?? 'null',
                'header_config_type' => gettype($validated['header_config'] ?? null),
            ]);
            
            // Ensure proposal_data is an array after parsing
            if (!is_array($proposalData)) {
                throw new \InvalidArgumentException('The proposal data must be an array.');
            }
            
            // Override the validated proposal_data with our parsed version
            $validated['proposal_data'] = $proposalData;

            // Use the proposal assembly service to build the proposal
            $proposalService = app(\Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class);
            
            // Build the config array for the proposal assembly service
            $assemblyConfig = [
                'template_id' => $validated['template_id'] ?? null,
                'branding_id' => $validated['branding_id'] ?? null,
                'proposal_data' => $validated['proposal_data'] ?? [],
                'sections' => $validated['sections'] ?? [],
                'header_config' => $validated['header_config'] ?? null,
            ];
            
            // Log the assembly config being sent to the service
            Log::info('PDFController::generateProposalPDF - Assembly config', [
                'assembly_config' => $assemblyConfig,
                'header_config_in_assembly' => $assemblyConfig['header_config'],
            ]);
            
            $proposalData = $proposalService->assembleProposal($assemblyConfig);
            
            // Log what we got back from the assembly service
            Log::info('PDFController::generateProposalPDF - Assembly results', [
                'html_length' => strlen($proposalData['html']),
                'html_contains_header' => strpos($proposalData['html'], 'proposal-header') !== false,
                'html_contains_has_header_class' => strpos($proposalData['html'], 'has-header') !== false,
            ]);

            // Get filename (default: proposal.pdf)
            $filename = $validated['filename'] ?? 'proposal-' . date('Y-m-d') . '.pdf';

            // Get paper size (default: a4)
            $paper = $validated['paper'] ?? 'a4';

            // Get orientation (default: portrait)
            $orientation = $validated['orientation'] ?? 'portrait';

            // Set enhanced options for proposal PDFs
            $options = [
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'isPhpEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150,
                'defaultPaperSize' => $paper,
                'defaultMediaType' => 'screen',
                'defaultPaperOrientation' => $orientation,
                'isFontSubsettingEnabled' => true,
                'isJavascriptEnabled' => false,
                'debugKeepTemp' => false,
                'chroot' => public_path(),
            ];

            // Generate PDF using the assembled HTML content
            $pdf = PDF::loadHTML($proposalData['html'])
                ->setOptions($options)
                ->setPaper($paper, $orientation);
                
            // Add header to each page if header is enabled
            Log::info('PDFController::generateProposalPDF - Header check', [
                'header_config_isset' => isset($validated['header_config']),
                'header_config_enabled' => $validated['header_config']['enabled'] ?? 'not_set',
                'header_condition_met' => isset($validated['header_config']) && ($validated['header_config']['enabled'] ?? false),
            ]);
            
            // Check if headers should be enabled (from request, HTML contains headers, or branding is available)
            $hasHeaders = (isset($validated['header_config']) && ($validated['header_config']['enabled'] ?? false)) || 
                         strpos($proposalData['html'], 'proposal-header') !== false ||
                         strpos($proposalData['html'], 'has-header') !== false;
            
            if ($hasHeaders) {
                Log::info('PDFController::generateProposalPDF - Adding header script to PDF');
                // Get company name from branding metadata if available
                $companyName = 'Company Name';
                if (isset($proposalData['metadata']['branding']->company_name)) {
                    $companyName = $proposalData['metadata']['branding']->company_name;
                }
                
                $pdf->getDomPDF()->getCanvas()->page_script('
                    if ($PAGE_NUM > 1) {
                        $font = $fontMetrics->getFont("Arial", "normal");
                        $canvas->text(40, 40, "' . $companyName . ' - Page $PAGE_NUM", $font, 10, array(0,0,0));
                    }
                ');
            } else {
                Log::info('PDFController::generateProposalPDF - Headers not detected, skipping header script');
            }

            // Return PDF as download or inline
            $download = $validated['download'] ?? true;

            if ($download) {
                return $pdf->download($filename);
            } else {
                return $pdf->stream($filename);
            }
        } catch (\Exception $e) {
            Log::error('Error generating Proposal PDF: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error generating Proposal PDF',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Preview a Proposal without downloading
     * Backward compatible method for proposal preview
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function previewProposalPDF(Request $request)
    {
        try {
            // Handle JSON string from form submission
            $proposalData = $request->input('proposal_data');
            if (is_string($proposalData)) {
                $proposalData = json_decode($proposalData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON in proposal_data: ' . json_last_error_msg());
                }
            }
            
            // Validate the request data
            $validated = $request->validate([
                'proposal_data' => 'required',
                'template_id' => 'nullable|integer',
                'branding_id' => 'nullable|integer',
                'sections' => 'nullable|array',
                'header_config' => 'nullable|array',
            ]);
            
            // Ensure proposal_data is an array after parsing
            if (!is_array($proposalData)) {
                throw new \InvalidArgumentException('The proposal data must be an array.');
            }
            
            // Override the validated proposal_data with our parsed version
            $validated['proposal_data'] = $proposalData;

            // Use the proposal assembly service to build the proposal
            $proposalService = app(\Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class);
            
            // Build the config array for the proposal assembly service
            $assemblyConfig = [
                'template_id' => $validated['template_id'] ?? null,
                'branding_id' => $validated['branding_id'] ?? null,
                'proposal_data' => $validated['proposal_data'] ?? [],
                'sections' => $validated['sections'] ?? [],
                'header_config' => $validated['header_config'] ?? null,
            ];
            
            $proposalData = $proposalService->assembleProposal($assemblyConfig);

            // Return HTML content for preview instead of PDF
            return response()->json([
                'success' => true,
                'html' => $proposalData['html']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error previewing Proposal PDF: ' . $e->getMessage());

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error previewing Proposal PDF',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Generate Proposal HTML for preview
     * Returns HTML content without PDF generation
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateProposalHTML(Request $request)
    {
        try {
            // Handle JSON string from form submission
            $proposalData = $request->input('proposal_data');
            if (is_string($proposalData)) {
                $proposalData = json_decode($proposalData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON in proposal_data: ' . json_last_error_msg());
                }
            }
            
            // Validate request
            $validated = $request->validate([
                'proposal_data' => 'required',
                'template_id' => 'nullable|integer',
                'branding_id' => 'nullable|integer',
                'sections' => 'nullable|array',
                'header_config' => 'nullable|array',
            ]);
            
            // Ensure proposal_data is an array after parsing
            if (!is_array($proposalData)) {
                throw new \InvalidArgumentException('The proposal data must be an array.');
            }
            
            // Override the validated proposal_data with our parsed version
            $validated['proposal_data'] = $proposalData;

            // Use the proposal assembly service to build the proposal
            $proposalService = app(\Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class);
            
            // Build the config array for the proposal assembly service
            $assemblyConfig = [
                'template_id' => $validated['template_id'] ?? null,
                'branding_id' => $validated['branding_id'] ?? null,
                'proposal_data' => $validated['proposal_data'] ?? [],
                'sections' => $validated['sections'] ?? [],
                'header_config' => $validated['header_config'] ?? null,
            ];
            
            $proposalData = $proposalService->assembleProposal($assemblyConfig);

            return response()->json([
                'success' => true,
                'html' => $proposalData['html'],
                'sections' => $proposalData['sections'],
                'metadata' => $proposalData['metadata']
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating Proposal HTML: ' . $e->getMessage());

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error generating Proposal HTML',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }
}
