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
                return response()->json([
                    'success' => false,
                    'message' => "View '{$viewName}' does not exist",
                ], 404);
            }

            // Generate PDF
            $pdf = PDF::loadView($viewName, $data)
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
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating PDF',
                'error' => $e->getMessage(),
            ], 500);
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

            // Generate PDF
            $pdf = PDF::loadHTML($html)
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
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating PDF from HTML',
                'error' => $e->getMessage(),
            ], 500);
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
            
            // Get options
            $options = $validated['options'] ?? [];
            
            // Initialize PDF
            $pdf = PDF::setOptions($options);
            
            // Load content from view or HTML
            if (isset($validated['view'])) {
                $viewName = $validated['view'];
                $data = $validated['data'] ?? [];
                
                // Check if view exists
                if (!View::exists($viewName)) {
                    return response()->json([
                        'success' => false,
                        'message' => "View '{$viewName}' does not exist",
                    ], 404);
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
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating custom PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
