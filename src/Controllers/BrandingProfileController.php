<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Visnsstudio\VisnsPackages\Models\BrandingProfile;

class BrandingProfileController extends \App\Http\Controllers\Controller
{
    // Standard CRUD operations (index, store, show, update, destroy, table, dropdown) 
    // are now handled by DynamicController to maintain consistency across the application.

    /**
     * Get the default branding profile
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDefault()
    {
        try {
            $profile = BrandingProfile::where('is_default', true)->first();

            if (!$profile) {
                // Create a basic default profile if none exists
                $profile = BrandingProfile::create([
                    'name' => 'Default',
                    'company_name' => config('app.name', 'Your Company'),
                    'colors' => [
                        'primary' => '#2563eb',
                        'secondary' => '#64748b',
                        'accent' => '#059669',
                    ],
                    'fonts' => [
                        'heading' => 'Arial, sans-serif',
                        'body' => 'Arial, sans-serif',
                    ],
                    'company_info' => [
                        'address' => '',
                        'phone' => '',
                        'email' => '',
                        'website' => '',
                        'abn' => '',
                    ],
                    'is_default' => true,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $profile,
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Error fetching default branding profile: ' . $e->getMessage()
            );
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching default branding profile',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Apply branding to HTML content
     * Helper method for proposal generation
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyBranding(Request $request)
    {
        try {
            $validated = $request->validate([
                'html' => 'required|string',
                'branding_id' => 'required|integer',
            ]);

            $profile = BrandingProfile::findOrFail($validated['branding_id']);
            $html = $validated['html'];

            // Apply branding styles to HTML
            $brandedHtml = $this->injectBrandingStyles($html, $profile);

            return response()->json([
                'success' => true,
                'data' => [
                    'html' => $brandedHtml,
                    'branding' => $profile,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error applying branding: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error applying branding',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Preview branding profile with sample content
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview($id)
    {
        try {
            $profile = BrandingProfile::findOrFail($id);

            $sampleHtml = $this->generateSampleHTML($profile);

            return response()->json([
                'success' => true,
                'data' => [
                    'profile' => $profile,
                    'preview_html' => $sampleHtml,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Error previewing branding profile: ' . $e->getMessage()
            );
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error previewing branding profile',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Duplicate a branding profile
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function duplicate($id)
    {
        try {
            $original = BrandingProfile::findOrFail($id);

            $duplicate = BrandingProfile::create([
                'name' => $original->name . ' (Copy)',
                'company_name' => $original->company_name,
                'logo_url' => $original->logo_url, // Reference same logo file
                'colors' => $original->colors,
                'fonts' => $original->fonts,
                'company_info' => $original->company_info,
                'is_default' => false,
            ]);

            return response()->json([
                'success' => true,
                'data' => $duplicate,
                'message' => 'Branding profile duplicated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Error duplicating branding profile: ' . $e->getMessage()
            );
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error duplicating branding profile',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Inject branding styles into HTML content
     *
     * @param string $html
     * @param BrandingProfile $profile
     * @return string
     */
    private function injectBrandingStyles($html, $profile)
    {
        $colors = $profile->colors ?? [];
        $fonts = $profile->fonts ?? [];

        $styles =
            "
        <style>
            :root {
                --primary-color: " .
            ($colors['primary'] ?? '#2563eb') .
            ";
                --secondary-color: " .
            ($colors['secondary'] ?? '#64748b') .
            ";
                --accent-color: " .
            ($colors['accent'] ?? '#059669') .
            ";
                --heading-font: " .
            ($fonts['heading'] ?? 'Arial, sans-serif') .
            ";
                --body-font: " .
            ($fonts['body'] ?? 'Arial, sans-serif') .
            ";
            }
            
            body {
                font-family: var(--body-font);
                color: #333;
            }
            
            h1, h2, h3, h4, h5, h6 {
                font-family: var(--heading-font);
                color: var(--primary-color);
            }
            
            .primary-bg { background-color: var(--primary-color); }
            .secondary-bg { background-color: var(--secondary-color); }
            .accent-bg { background-color: var(--accent-color); }
            
            .primary-text { color: var(--primary-color); }
            .secondary-text { color: var(--secondary-color); }
            .accent-text { color: var(--accent-color); }
            
            .company-logo {
                max-height: 80px;
                width: auto;
            }
        </style>
        ";

        // Insert styles before closing head tag or at the beginning if no head tag
        if (strpos($html, '</head>') !== false) {
            $html = str_replace('</head>', $styles . '</head>', $html);
        } else {
            $html = $styles . $html;
        }

        return $html;
    }

    /**
     * Generate sample HTML for branding preview
     *
     * @param BrandingProfile $profile
     * @return string
     */
    private function generateSampleHTML($profile)
    {
        $logoHtml = $profile->logo_url
            ? '<img src="' .
                $profile->logo_url .
                '" alt="Company Logo" class="company-logo">'
            : '<div class="primary-bg" style="width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">LOGO</div>';

        $html =
            '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Branding Preview</title>
        </head>
        <body>
            <div style="padding: 20px; max-width: 800px; margin: 0 auto;">
                <header style="text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid var(--primary-color);">
                    ' .
            $logoHtml .
            '
                    <h1 class="primary-text" style="margin: 10px 0;">' .
            $profile->company_name .
            '</h1>
                    <p class="secondary-text">' .
            ($profile->company_info['address'] ?? 'Company Address') .
            '</p>
                </header>
                
                <main>
                    <h2 class="primary-text">Sample Proposal Title</h2>
                    <p>This is a sample paragraph showing how your branding will appear in proposals. The text uses your selected body font and colors.</p>
                    
                    <h3 class="accent-text">Section Heading</h3>
                    <p>This demonstrates section headings with accent color. Your branding profile ensures consistent styling across all proposal documents.</p>
                    
                    <div class="primary-bg" style="padding: 15px; color: white; margin: 20px 0; border-radius: 5px;">
                        <strong>Important Information Box</strong><br>
                        This shows how highlighted content appears with your primary brand color.
                    </div>
                    
                    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                        <thead>
                            <tr class="secondary-bg" style="color: white;">
                                <th style="padding: 10px; text-align: left;">Item</th>
                                <th style="padding: 10px; text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">Sample Service</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right;">$1,500.00</td>
                            </tr>
                            <tr class="accent-text">
                                <td style="padding: 8px; font-weight: bold;">Total</td>
                                <td style="padding: 8px; text-align: right; font-weight: bold;">$1,500.00</td>
                            </tr>
                        </tbody>
                    </table>
                </main>
                
                <footer style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--secondary-color); text-align: center; color: var(--secondary-color);">
                    <p>' .
            $profile->company_name .
            ' | ' .
            ($profile->company_info['phone'] ?? 'Phone') .
            ' | ' .
            ($profile->company_info['email'] ?? 'Email') .
            '</p>
                </footer>
            </div>
        </body>
        </html>';

        return $this->injectBrandingStyles($html, $profile);
    }
}
