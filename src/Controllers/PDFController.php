<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Barryvdh\DomPDF\Facade\Pdf;
use Spatie\LaravelPdf\Facades\Pdf as SpatiePdf;
use Spatie\Browsershot\Browsershot;

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
                'header_count' => substr_count($proposalData['html'], 'proposal-header'),
                'html_snippet_with_headers' => $this->extractHeaderSnippets($proposalData['html']),
            ]);

            // Get filename (default: proposal.pdf)
            $filename = $validated['filename'] ?? 'proposal-' . date('Y-m-d') . '.pdf';

            // Get paper size (default: a4)
            $paper = $validated['paper'] ?? 'a4';

            // Get orientation (default: portrait)
            $orientation = $validated['orientation'] ?? 'portrait';

            // Set enhanced options for proposal PDFs with remote image support
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
                'debugKeepTemp' => true, // Keep temp files for debugging
                'debugCss' => true, // Enable CSS debugging
                'chroot' => public_path(),
                // Enable remote content loading for S3 images
                'enable_remote' => true,
                'enable_font_subsetting' => true,
                'enable_css_float' => true,
            ];

            // Log a sample of the HTML being sent to DomPDF
            Log::info('PDFController::generateProposalPDF - HTML sample for DomPDF', [
                'html_start' => substr($proposalData['html'], 0, 1000),
                'html_end' => substr($proposalData['html'], -1000),
                'first_header_position' => strpos($proposalData['html'], 'proposal-header'),
            ]);

            // Save HTML to file for debugging
            file_put_contents(storage_path('app/debug_proposal.html'), $proposalData['html']);

            // Check if headers should be enabled - check both request and metadata
            $headerConfig = $validated['header_config'] ?? $proposalData['metadata']['header_config'] ?? null;
            $hasHeaders = $headerConfig && ($headerConfig['enabled'] ?? false);
            
            // Generate PDF using the assembled HTML content
            $pdf = PDF::setOptions($options)
                ->setPaper($paper, $orientation);
            
            // Set page script BEFORE loading HTML - disabled to get working PDF first
            if ($hasHeaders && false) { // DISABLED - page scripts cause blank PDF
                Log::info('PDFController::generateProposalPDF - Setting page script before HTML load');
                
                // Build the page script with all necessary data
                $companyName = 'LKD Fitouts CRM';
                $headerText = 'Ph: (02) 1234 5678 | www.lkdfitouts.com.au';
                $logoPath = '/Users/reyhan.thee/Projects/lkd-fitouts-crm/storage/app/temp/pdf-images/logo_6.png';
                
                if (isset($proposalData['metadata']['branding'])) {
                    $branding = $proposalData['metadata']['branding'];
                    $companyName = $branding->company_name ?? 'LKD Fitouts CRM';
                    $companyInfo = $branding->company_info ?? [];
                    
                    // Build header content
                    $headerContent = [];
                    if ($headerConfig['show_phone'] ?? false && !empty($companyInfo['phone'] ?? '')) {
                        $headerContent[] = 'Ph: ' . $companyInfo['phone'];
                    }
                    if ($headerConfig['show_email'] ?? false && !empty($companyInfo['email'] ?? '')) {
                        $headerContent[] = $companyInfo['email'];
                    }
                    if ($headerConfig['show_website'] ?? false && !empty($companyInfo['website'] ?? '')) {
                        $headerContent[] = $companyInfo['website'];
                    }
                    $headerText = implode(' | ', $headerContent);
                    
                    // Get logo path
                    if ($branding->file && $branding->file->file_path) {
                        $cachedLogoPath = storage_path('app/temp/pdf-images/logo_' . $branding->file->id . '.' . ($branding->file->file_extension ?? 'png'));
                        if (file_exists($cachedLogoPath)) {
                            $logoPath = $cachedLogoPath;
                        }
                    }
                }
                
                // Create working page script with proper PHP syntax
                $escapedCompanyName = addslashes($companyName);
                $escapedHeaderText = addslashes($headerText);
                
                $preLoadPageScript = '
                if ($PAGE_NUM > 1) {
                    $font = $fontMetrics->getFont("helvetica");
                    
                    // Draw header background
                    $canvas->rectangle(50, 50, 495, 50, array(0.95, 0.95, 0.95), 1);
                    
                    // Company name
                    $canvas->text(60, 75, "' . $escapedCompanyName . '", $font, 14, array(0.2, 0.4, 0.8));
                    
                    // Contact info
                    $canvas->text(60, 90, "' . $escapedHeaderText . '", $font, 10, array(0.3, 0.3, 0.3));
                    
                    // Page number
                    $canvas->text(500, 75, "Page " . $PAGE_NUM, $font, 12, array(0.4, 0.4, 0.4));
                }';
                
                try {
                    // Check if we can access the canvas
                    $domPDF = $pdf->getDomPDF();
                    Log::info('PDFController::generateProposalPDF - DomPDF instance obtained');
                    
                    $canvas = $domPDF->getCanvas();
                    Log::info('PDFController::generateProposalPDF - Canvas instance obtained', [
                        'canvas_class' => get_class($canvas),
                    ]);
                    
                    $canvas->page_script($preLoadPageScript);
                    Log::info('PDFController::generateProposalPDF - Pre-load page script set successfully');
                } catch (\Exception $e) {
                    Log::error('PDFController::generateProposalPDF - Pre-load page script failed', [
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Fallback: Try CSS-based headers by modifying the HTML
                    Log::info('PDFController::generateProposalPDF - Attempting CSS fallback header approach');
                    $cssHeader = '
                    @page {
                        margin-top: 100px;
                        @top-center {
                            content: "' . addslashes($companyName) . ' - ' . addslashes($headerText) . '";
                            font-family: Arial, sans-serif;
                            font-size: 10px;
                            color: #333;
                            border-bottom: 1px solid #2563eb;
                            padding: 10px;
                        }
                    }';
                    
                    // Inject the CSS into the HTML
                    $proposalData['html'] = str_replace(
                        '</style>',
                        $cssHeader . '</style>',
                        $proposalData['html']
                    );
                    
                    Log::info('PDFController::generateProposalPDF - CSS header fallback applied');
                }
            }
            
            // Now load the HTML
            $pdf->loadHTML($proposalData['html']);

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

    /**
     * Generate a Proposal PDF using Spatie Laravel PDF (Chrome-based)
     * Modern approach with better header and CSS support
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateProposalPDFSpatie(Request $request)
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
                'filename' => 'nullable|string',
                'paper' => 'nullable|string',
                'orientation' => 'nullable|string|in:portrait,landscape',
                'download' => 'nullable|boolean',
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

            // Get filename (default: proposal.pdf)
            $filename = $validated['filename'] ?? 'proposal-' . date('Y-m-d') . '.pdf';

            // Get paper size (default: a4)
            $paper = $validated['paper'] ?? 'a4';

            // Get orientation (default: portrait)
            $orientation = $validated['orientation'] ?? 'portrait';

            // Check if headers should be enabled
            $headerConfig = $validated['header_config'] ?? $proposalData['metadata']['header_config'] ?? null;
            $hasHeaders = $headerConfig && ($headerConfig['enabled'] ?? false);

            // Build header HTML for Spatie PDF
            $headerHtml = '';
            if ($hasHeaders && isset($proposalData['metadata']['branding'])) {
                $branding = $proposalData['metadata']['branding'];
                $companyName = $branding->company_name ?? 'LKD Fitouts CRM';
                $companyInfo = $branding->company_info ?? [];
                
                // Get logo information
                $logoHtml = '';
                if (isset($branding->file) && $branding->file) {
                    $logoUrl = $branding->file->file_url ?? '';
                    if ($logoUrl) {
                        // Convert image to base64 data URI for reliable PDF embedding
                        $finalLogoUrl = $logoUrl;
                        $isS3 = (strpos($logoUrl, 's3.amazonaws.com') !== false) || 
                               (strpos($logoUrl, 'amazonaws.com') !== false) || 
                               (strpos($logoUrl, '.s3.') !== false);
                        
                        // For external URLs (especially S3), convert to base64 data URI
                        if (str_starts_with($logoUrl, 'http')) {
                            try {
                                Log::info('PDFController::generateProposalPDFSpatie - Converting logo to base64', [
                                    'url' => $logoUrl,
                                    'is_s3' => $isS3
                                ]);
                                
                                $imageContent = file_get_contents($logoUrl);
                                if ($imageContent !== false) {
                                    // Detect image MIME type
                                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                                    $mimeType = $finfo->buffer($imageContent);
                                    
                                    // Fallback to filename extension if finfo fails
                                    if (!$mimeType || $mimeType === 'application/octet-stream') {
                                        $extension = strtolower(pathinfo($branding->file->file_name ?? 'logo.png', PATHINFO_EXTENSION));
                                        $mimeType = match($extension) {
                                            'png' => 'image/png',
                                            'jpg', 'jpeg' => 'image/jpeg',
                                            'gif' => 'image/gif',
                                            'svg' => 'image/svg+xml',
                                            'webp' => 'image/webp',
                                            default => 'image/png'
                                        };
                                    }
                                    
                                    // Create base64 data URI
                                    $base64 = base64_encode($imageContent);
                                    $finalLogoUrl = "data:{$mimeType};base64,{$base64}";
                                    
                                    Log::info('PDFController::generateProposalPDFSpatie - Logo converted to base64', [
                                        'original_url' => $logoUrl,
                                        'mime_type' => $mimeType,
                                        'base64_length' => strlen($base64),
                                        'data_uri_length' => strlen($finalLogoUrl)
                                    ]);
                                } else {
                                    Log::warning('PDFController::generateProposalPDFSpatie - Failed to fetch image for base64 conversion', [
                                        'url' => $logoUrl
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Log::error('PDFController::generateProposalPDFSpatie - Error converting logo to base64', [
                                    'url' => $logoUrl,
                                    'error' => $e->getMessage()
                                ]);
                                // Fallback to original URL
                                $finalLogoUrl = $logoUrl;
                            }
                        } else {
                            // For local URLs, ensure we have a full absolute URL
                            if (!str_starts_with($logoUrl, 'file://')) {
                                $finalLogoUrl = url($logoUrl);
                            }
                        }
                        
                        Log::info('PDFController::generateProposalPDFSpatie - Logo URL processing', [
                            'original_url' => $logoUrl,
                            'final_url' => $finalLogoUrl,
                            'is_s3' => $isS3,
                            'is_external' => str_starts_with($logoUrl, 'http'),
                            'file_info' => [
                                'filename' => $branding->file->file_name ?? '',
                                'mime_type' => $branding->file->mime_type ?? '',
                            ]
                        ]);
                        
                        $logoHtml = '<img src="' . htmlspecialchars($finalLogoUrl) . '" style="height: 28px; width: auto; margin-right: 15px;" alt="Company Logo">';
                    }
                }
                
                // Build comprehensive company info for right side
                $companyInfoLines = [];
                
                // Add company name if different from branding company name
                if (!empty($companyInfo['name']) && $companyInfo['name'] !== $companyName) {
                    $companyInfoLines[] = $companyInfo['name'];
                }
                
                // Add address if enabled and available
                if ($headerConfig['show_address'] ?? false && !empty($companyInfo['address'])) {
                    $companyInfoLines[] = $companyInfo['address'];
                }
                
                // Add phone if enabled and available
                if ($headerConfig['show_phone'] ?? false && !empty($companyInfo['phone'])) {
                    $companyInfoLines[] = 'Ph: ' . $companyInfo['phone'];
                }
                
                // Add email if available
                if (!empty($companyInfo['email'])) {
                    $companyInfoLines[] = $companyInfo['email'];
                }
                
                // Add website if enabled and available
                if ($headerConfig['show_website'] ?? false && !empty($companyInfo['website'])) {
                    $companyInfoLines[] = $companyInfo['website'];
                }
                
                // Add ABN if enabled and available
                if ($headerConfig['show_abn'] ?? false && !empty($companyInfo['abn'])) {
                    $companyInfoLines[] = 'ABN: ' . $companyInfo['abn'];
                }
                
                $headerHtml = '
                <div style="
                    font-family: Arial, sans-serif; 
                    font-size: 9px; 
                    padding: 10px 20px; 
 
                    margin: 0; 
                    width: 100%; 
                    box-sizing: border-box;
                    background: white;
                    height: 50px;
                    display: flex;
                    align-items: center;
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div style="display: flex; align-items: center; flex: 1;">
                            ' . $logoHtml . '
                        </div>
                        <div style="text-align: right; max-width: 50%;">
                            <div style="color: #333; font-size: 8px; line-height: 1.2;">
                                ' . implode('<br>', array_map('htmlspecialchars', $companyInfoLines)) . '
                            </div>
                        </div>
                    </div>
                </div>';
            }

            // Modify HTML to add proper spacing for headers
            $finalHtml = $proposalData['html'];
            if ($hasHeaders && !empty($headerHtml)) {
                // Add CSS to ensure content doesn't overlap with fixed header on all pages
                $headerSpacing = '
                <style>
                    @page {
                        margin-top: 70px !important;
                        margin-bottom: 50px !important;
                        margin-left: 20px !important;
                        margin-right: 20px !important;
                    }
                    
                    body { 
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                    
                    /* Reduce spacing on first page */
                    body > div:first-child {
                        margin-top: 5px !important;
                    }
                    
                    /* Reduce spacing for main headings */
                    h1:first-of-type {
                        margin-top: 10px !important;
                    }
                    
                    /* Ensure proper spacing for subsequent content blocks */
                    h1, h2, h3, .section {
                        margin-top: 15px !important;
                    }
                    
                    /* Page break handling */
                    .section, .stage {
                        page-break-inside: avoid !important;
                    }
                </style>';
                
                // Insert the spacing CSS into the HTML head
                $finalHtml = str_replace('</head>', $headerSpacing . '</head>', $finalHtml);
            }

            // Create PDF using Spatie Laravel PDF
            $pdf = SpatiePdf::html($finalHtml)
                ->format($paper);
                
            // Set margins based on whether headers are enabled
            if ($hasHeaders && !empty($headerHtml)) {
                // Use CSS @page margins when headers are enabled (smaller margins for compact header)
                $pdf->margins(0, 0, 0, 0);
                
                // Enable browser headers and footers, then add header and footer
                $pdf->withBrowsershot(function (Browsershot $browsershot) use ($headerHtml) {
                    $browsershot->showBrowserHeaderAndFooter();
                    
                    // Add footer with page numbering
                    $footerHtml = '
                    <div style="
                        font-family: Arial, sans-serif; 
                        font-size: 10px; 
                        color: #666; 
                        text-align: center;
                        padding: 10px 0;
                        width: 100%;
                        height: 25px;
                        line-height: 25px;
                        background: transparent;
                    ">
                        Page <span class="pageNumber"></span> of <span class="totalPages"></span>
                    </div>';
                    
                    $browsershot->footerHtml($footerHtml);
                    $browsershot->headerHtml($headerHtml);
                });
            } else {
                // Standard margins when no headers
                $pdf->margins(20, 20, 20, 20);
            }

            // Return PDF as download or inline
            return $pdf->name($filename);
        } catch (\Exception $e) {
            Log::error('Error generating Spatie Proposal PDF: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error generating Proposal PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract header snippets from HTML for debugging
     *
     * @param string $html
     * @return array
     */
    private function extractHeaderSnippets($html)
    {
        $snippets = [];
        preg_match_all('/<div class="proposal-header[^>]*>.*?<\/div>/s', $html, $matches);
        
        foreach ($matches[0] as $index => $match) {
            $snippets[] = [
                'index' => $index,
                'length' => strlen($match),
                'preview' => substr(strip_tags($match), 0, 100) . '...',
                'contains_img' => strpos($match, '<img') !== false,
                'contains_company_name' => strpos($match, 'LKD Fitouts') !== false,
            ];
        }
        
        return $snippets;
    }
}
