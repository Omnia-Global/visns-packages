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
            // Validate request
            $validated = $request->validate([
                'proposal_data' => 'required|array',
                'template_id' => 'nullable|integer',
                'branding_id' => 'nullable|integer',
                'filename' => 'nullable|string',
                'paper' => 'nullable|string',
                'orientation' => 'nullable|string|in:portrait,landscape',
                'download' => 'nullable|boolean',
                'sections' => 'nullable|array',
            ]);

            // Use the proposal assembly service to build the proposal
            $proposalService = app(\Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class);
            
            // Build the config array for the proposal assembly service
            $assemblyConfig = [
                'template_id' => $validated['template_id'] ?? null,
                'branding_id' => $validated['branding_id'] ?? null,
                'proposal_data' => $validated['proposal_data'] ?? [],
                'sections' => $validated['sections'] ?? [],
            ];
            
            $proposalData = $proposalService->assembleProposal($assemblyConfig);

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
            // Set download to false for preview
            $request->merge(['download' => false]);
            
            return $this->generateProposalPDF($request);
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
            // Validate request
            $validated = $request->validate([
                'proposal_data' => 'required|array',
                'template_id' => 'nullable|integer',
                'branding_id' => 'nullable|integer',
                'sections' => 'nullable|array',
            ]);

            // Use the proposal assembly service to build the proposal
            $proposalService = app(\Visnsstudio\VisnsPackages\Services\ProposalAssemblyService::class);
            
            // Build the config array for the proposal assembly service
            $assemblyConfig = [
                'template_id' => $validated['template_id'] ?? null,
                'branding_id' => $validated['branding_id'] ?? null,
                'proposal_data' => $validated['proposal_data'] ?? [],
                'sections' => $validated['sections'] ?? [],
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
