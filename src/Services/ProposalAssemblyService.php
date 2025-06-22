<?php

namespace Visnsstudio\VisnsPackages\Services;

use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\ProposalTemplate;
use Visnsstudio\VisnsPackages\Models\BrandingProfile;

class ProposalAssemblyService
{
    /**
     * Assemble a complete proposal from template, data, and branding
     * Leverages existing PDF generation infrastructure
     *
     * @param array $config
     * @return array
     */
    public function assembleProposal(array $config): array
    {
        try {
            // Extract configuration
            $templateId = $config['template_id'] ?? null;
            $brandingId = $config['branding_id'] ?? null;
            $proposalData = $config['proposal_data'] ?? [];
            $customSections = $config['sections'] ?? [];

            // Load template and branding
            $template = $templateId ? ProposalTemplate::with('sections')->find($templateId) : null;
            $branding = $brandingId ? BrandingProfile::with('file')->find($brandingId) : $this->getDefaultBranding();

            // Build sections array (without variable replacement for extraction)
            $sectionsForExtraction = $this->buildSectionsForExtraction($template, $customSections);
            
            // Extract variables used in the content BEFORE replacement
            $variablesUsed = $this->extractVariablesUsed($sectionsForExtraction);

            // Build sections array with variable replacement
            $sections = $this->buildSections($template, $customSections, $proposalData, $branding);

            // Generate HTML content
            $html = $this->assembleHTML($sections, $branding, $proposalData);

            return [
                'html' => $html,
                'sections' => $sections,
                'variables_used' => $variablesUsed,
                'metadata' => [
                    'template' => $template,
                    'branding' => $branding,
                    'generated_at' => now()->toISOString(),
                    'total_pages' => $this->estimatePageCount($sections),
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error assembling proposal: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build sections array for variable extraction (without replacement)
     *
     * @param ProposalTemplate|null $template
     * @param array $customSections
     * @return array
     */
    private function buildSectionsForExtraction($template, array $customSections): array
    {
        $sections = [];

        if ($template && $template->sections) {
            // Use template sections WITHOUT variable replacement
            foreach ($template->sections as $section) {
                $sections[] = [
                    'id' => $section->id,
                    'type' => $section->section_type,
                    'title' => $section->title, // No replacement
                    'content' => $section->content, // No replacement
                    'sort_order' => $section->sort_order,
                    'is_dynamic' => $section->is_dynamic,
                    'styling' => $section->styling ?? [],
                    'variables' => $section->variables ?? [],
                ];
            }
        } else {
            // Use custom sections or default structure
            $sections = $this->buildDefaultSections($customSections, []);
        }

        return $sections;
    }

    /**
     * Build sections array from template and custom sections
     *
     * @param ProposalTemplate|null $template
     * @param array $customSections
     * @param array $proposalData
     * @param BrandingProfile $branding
     * @return array
     */
    private function buildSections($template, array $customSections, array $proposalData, $branding): array
    {
        $sections = [];

        if ($template && $template->sections) {
            // Check if we have saved proposal content
            $savedContent = $proposalData['proposal_content'] ?? null;
            
            // Use template sections
            foreach ($template->sections as $section) {
                $content = $section->content;
                
                // If this is a content section and we have saved content, use the saved content
                if ($section->section_type === 'content' && !empty($savedContent)) {
                    $content = $savedContent;
                }
                
                $sections[] = [
                    'id' => $section->id,
                    'type' => $section->section_type,
                    'title' => $this->replaceVariables($section->title, $proposalData),
                    'content' => $this->replaceVariables($content, $proposalData),
                    'sort_order' => $section->sort_order,
                    'is_dynamic' => $section->is_dynamic,
                    'styling' => $section->styling ?? [],
                    'variables' => $section->variables ?? [],
                ];
            }
        } else {
            // Use custom sections or default structure
            $sections = $this->buildDefaultSections($customSections, $proposalData);
        }

        // Sort sections by sort_order
        usort($sections, function ($a, $b) {
            return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        });

        // Auto-generate table of contents if requested
        $sections = $this->insertTableOfContents($sections);

        return $sections;
    }

    /**
     * Build default proposal sections when no template is provided
     *
     * @param array $customSections
     * @param array $proposalData
     * @return array
     */
    private function buildDefaultSections(array $customSections, array $proposalData): array
    {
        if (!empty($customSections)) {
            return $customSections;
        }

        // Check if we have saved proposal content
        $savedContent = $proposalData['proposal_content'] ?? null;
        $sectionType = $proposalData['proposal_section_type'] ?? 'content';

        // Default proposal structure matching existing template requirements
        return [
            [
                'type' => 'cover_page',
                'title' => 'Business Proposal',
                'content' => $this->generateCoverPageContent($proposalData),
                'sort_order' => 1,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'toc',
                'title' => 'Table of Contents',
                'content' => '', // Will be auto-generated
                'sort_order' => 2,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'terms_conditions',
                'title' => 'Terms & Conditions',
                'content' => $this->getDefaultTermsConditions(),
                'sort_order' => 3,
                'is_dynamic' => false, // Static content
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'review_log',
                'title' => 'Review Log',
                'content' => '', // Dynamic content
                'sort_order' => 4,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'overview',
                'title' => 'Overview',
                'content' => $savedContent ?: $this->getDefaultOverviewContent($proposalData),
                'sort_order' => 5,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'content',
                'title' => 'Proposal Content',
                'content' => $savedContent ?: '<p>No custom content provided.</p>',
                'sort_order' => 5.5,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'quote_items',
                'title' => 'Proposed Solution & Pricing',
                'content' => '', // Will be generated from quote data
                'sort_order' => 6,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'payment_terms',
                'title' => 'Payment Terms',
                'content' => $this->getDefaultPaymentTerms($proposalData),
                'sort_order' => 7,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'agreement_signature',
                'title' => 'Agreement & Signatures',
                'content' => $this->getDefaultAgreementSignature(),
                'sort_order' => 8,
                'is_dynamic' => false, // Static content
                'styling' => [],
                'variables' => [],
            ],
        ];
    }

    /**
     * Assemble complete HTML document
     *
     * @param array $sections
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function assembleHTML(array $sections, $branding, array $proposalData): string
    {
        // Store sections for TOC generation
        $this->allSections = $sections;
        
        $html = $this->getHTMLHeader($branding);

        foreach ($sections as $section) {
            $html .= $this->renderSection($section, $branding, $proposalData);
        }

        $html .= $this->getHTMLFooter($branding);

        return $html;
    }

    /**
     * Store all sections for TOC generation
     * @var array
     */
    private $allSections = [];

    /**
     * Render individual section
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderSection(array $section, $branding, array $proposalData): string
    {
        // Debug logging to track which renderer is being used
        Log::info('Rendering section', [
            'type' => $section['type'],
            'title' => $section['title'] ?? 'No title',
            'has_content' => !empty($section['content'])
        ]);
        
        switch ($section['type']) {
            case 'cover_page':
                return $this->renderCoverPage($section, $branding, $proposalData);
            case 'toc':
                return $this->renderTableOfContents($section, $proposalData);
            case 'terms_conditions':
                return $this->renderTermsConditions($section, $branding);
            case 'review_log':
                return $this->renderChangeLog($section, $branding, $proposalData);
            case 'overview':
                return $this->renderOverviewSection($section, $branding, $proposalData);
            case 'acceptance':
                return $this->renderAcceptanceSection($section, $branding, $proposalData);
            case 'quote_items':
                return $this->renderPricingSection($section, $branding, $proposalData);
            case 'payment_terms':
                return $this->renderPaymentTerms($section, $branding, $proposalData);
            case 'agreement_signature':
                return $this->renderAgreementSignature($section, $branding, $proposalData);
            case 'content':
                return $this->renderContentSection($section, $branding);
            case 'terms':
                return $this->renderTermsSection($section, $branding);
            default:
                return $this->renderContentSection($section, $branding);
        }
    }

    /**
     * Render clean cover page (OMNIA format)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderCoverPage(array $section, $branding, array $proposalData): string
    {
        // Get logo from branding profile
        $logoHtml = $this->renderBrandingLogo($branding);
        
        return '
        <div class="omnia-cover-page" style="page-break-after: always; padding: 0; background: linear-gradient(135deg, #1a4b3a 0%, #2d6a4f 100%); color: white; height: 100vh; position: relative; font-family: Arial, sans-serif;">
            
            <div class="cover-content" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; width: 100%;">
                <div class="logo-section" style="margin-bottom: 60px;">
                    ' . $logoHtml . '
                </div>
                <div>
                    <h1 style="font-size: 48px; font-weight: bold; margin: 0; color: #4ade80;">' . ($proposalData['document_title'] ?? '[Document Title]') . '</h1>
                </div>
            </div>
            
            <div class="cover-footer" style="position: absolute; bottom: 40px; left: 0; right: 0; text-align: center;">
                <p style="font-size: 14px; color: #a3a3a3; margin: 0;">' . ($branding->company_name ?? 'OMNIA GLOBAL GROUP PTY LTD') . ' &nbsp;&nbsp; ' . ($branding->company_info['acn'] ?? 'ACN: 674 383 987') . '</p>
            </div>
        </div>';
    }

    /**
     * Render branding logo from file relationship
     *
     * @param BrandingProfile $branding
     * @return string
     */
    private function renderBrandingLogo($branding): string
    {
        // Try to get logo from file relationship
        if ($branding && $branding->file && $branding->file->file_url) {
            $logoUrl = $branding->file->file_url;
            $companyName = $branding->company_name ?? 'Company Logo';
            
            return '
            <div style="margin: 0 auto; text-align: center;">
                <img src="' . $logoUrl . '" alt="' . $companyName . '" style="max-width: 450px; max-height: 180px; object-fit: contain;" />
            </div>';
        }
        
        // Try to use logo_url field if file relationship doesn't exist
        if ($branding && $branding->logo_url) {
            $companyName = $branding->company_name ?? 'Company Logo';
            
            return '
            <div style="margin: 0 auto; text-align: center;">
                <img src="' . $branding->logo_url . '" alt="' . $companyName . '" style="max-width: 450px; max-height: 180px; object-fit: contain;" />
            </div>';
        }
        
        // Fallback to company name text if no logo available
        $companyName = $branding->company_name ?? 'OmniaGlobal';
        return '
        <div style="width: 200px; height: 80px; background: white; border-radius: 10px; margin: 0 auto; display: flex; align-items: center; justify-content: center;">
            <span style="color: #2d6a4f; font-size: 24px; font-weight: bold;">' . $companyName . '</span>
        </div>';
    }

    /**
     * Render table of contents
     *
     * @param array $section
     * @param array $proposalData
     * @return string
     */
    private function renderTableOfContents(array $section, array $proposalData): string
    {
        // Auto-generate TOC items from all sections' headings
        $tocItems = $this->extractHeadingsFromAllSections($section, $proposalData);
        
        // Use manually defined TOC items if no headings found
        if (empty($tocItems)) {
            $tocItems = $section['toc_items'] ?? [];
        }
        
        $tocHtml = '
        <div class="table-of-contents" style="page-break-after: always; padding: 80px;">
            <h1 style="font-size: 32px; font-weight: bold; margin-bottom: 40px; text-align: left;">Contents</h1>
            <table class="toc-table" style="width: 100%; border-collapse: collapse; margin-top: 40px;">';

        foreach ($tocItems as $item) {
            // Add indentation based on heading level
            $indent = '';
            switch ($item['level'] ?? 1) {
                case 2:
                    $indent = '&nbsp;&nbsp;&nbsp;&nbsp;';
                    break;
                case 3:
                    $indent = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
                    break;
                default:
                    $indent = '';
                    break;
            }
            
            $tocHtml .= '
                <tr class="toc-item" style="border-bottom: 1px dotted #ccc;">
                    <td class="toc-title" style="padding: 10px 0; border: none; text-align: left;">' . $indent . $item['title'] . '</td>
                    <td class="toc-page" style="padding: 10px 0; border: none; text-align: right; width: 50px;">' . ($item['page'] ?? '') . '</td>
                </tr>';
        }

        $tocHtml .= '
            </table>
        </div>';

        return $tocHtml;
    }

    /**
     * Render quote items section
     *
     * @param array $section
     * @param array $proposalData
     * @return string
     */
    /**
     * Render acceptance section
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderAcceptanceSection(array $section, $branding, array $proposalData): string
    {
        return '
        <div class="acceptance-section" style="padding: 60px; page-break-after: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="acceptance-content">
                ' . $this->replaceVariables($section['content'], $proposalData) . '
            </div>
        </div>';
    }

    /**
     * Render pricing section (matching OMNIA format)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderPricingSection(array $section, $branding, array $proposalData): string
    {
        $html = '
        <div class="pricing-section" style="padding: 70px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background-color: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 15px; text-align: left; vertical-align: top;">Description</th>
                        <th style="border: 1px solid #ddd; padding: 15px; text-align: right; vertical-align: top; width: 80px;">Price</th>
                        <th style="border: 1px solid #ddd; padding: 15px; text-align: center; vertical-align: top; width: 50px;">Qty</th>
                        <th style="border: 1px solid #ddd; padding: 15px; text-align: right; vertical-align: top; width: 80px;">Amount</th>
                    </tr>
                </thead>
                <tbody>';

        // Add all quote items in OMNIA format
        $allItems = [];
        if (isset($proposalData['items_onceoff'])) {
            $allItems = array_merge($allItems, $proposalData['items_onceoff']);
        }
        if (isset($proposalData['items_monthly_subscription'])) {
            $allItems = array_merge($allItems, $proposalData['items_monthly_subscription']);
        }
        if (isset($proposalData['items_yearly_subscription'])) {
            $allItems = array_merge($allItems, $proposalData['items_yearly_subscription']);
        }

        // Add development placeholder if no items
        if (empty($allItems)) {
            $html .= '
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 15px;">Development</td>
                        <td style="border: 1px solid #ddd; padding: 15px; text-align: right;"></td>
                        <td style="border: 1px solid #ddd; padding: 15px; text-align: center;"></td>
                        <td style="border: 1px solid #ddd; padding: 15px; text-align: right;"></td>
                    </tr>';
        } else {
            foreach ($allItems as $item) {
                $qty = $item['qty'] ?? 1;
                $rate = $item['rate'] ?? 0;
                $total = $qty * $rate;
                $html .= '
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 15px;">' . ($item['description'] ?? '') . '</td>
                        <td style="border: 1px solid #ddd; padding: 15px; text-align: right;">$' . number_format($rate, 2) . '</td>
                        <td style="border: 1px solid #ddd; padding: 15px; text-align: center;">' . $qty . '</td>
                        <td style="border: 1px solid #ddd; padding: 15px; text-align: right;">$' . number_format($total, 2) . '</td>
                    </tr>';
            }
        }

        // Add totals rows
        $subtotal = $this->calculateSubtotal($proposalData);
        $taxAmount = $subtotal * 0.10;
        $total = $subtotal + $taxAmount;
        
        $html .= '
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="border: 1px solid #ddd; padding: 10px; text-align: right; font-weight: bold; background-color: #f9f9f9;">Sub Total:</td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: right; font-weight: bold; background-color: #f9f9f9;">$' . number_format($subtotal, 2) . '</td>
                    </tr>
                    <tr>
                        <td colspan="3" style="border: 1px solid #ddd; padding: 10px; text-align: right; font-weight: bold; background-color: #f9f9f9;">Tax (10%):</td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: right; font-weight: bold; background-color: #f9f9f9;">$' . number_format($taxAmount, 2) . '</td>
                    </tr>
                    <tr>
                        <td colspan="3" style="border: 2px solid #333; padding: 12px; text-align: right; font-weight: bold; background-color: #e9e9e9;">Total:</td>
                        <td style="border: 2px solid #333; padding: 12px; text-align: right; font-weight: bold; background-color: #e9e9e9; font-size: 18px;">$' . number_format($total, 2) . '</td>
                    </tr>
                </tfoot>
            </table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render items table
     *
     * @param string $title
     * @param array $items
     * @return string
     */
    private function renderItemsTable(string $title, array $items): string
    {
        $html = '
        <div class="items-table-section" style="margin: 30px 0;">
            <h2 class="accent-text" style="margin-bottom: 15px;">' . $title . '</h2>
            <table class="items-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr class="secondary-bg" style="color: white;">
                        <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Description</th>
                        <th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Qty</th>
                        <th style="padding: 12px; text-align: right; border: 1px solid #ddd;">Rate</th>
                        <th style="padding: 12px; text-align: right; border: 1px solid #ddd;">Total</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($items as $item) {
            $qty = $item['qty'] ?? 1;
            $rate = $item['rate'] ?? 0;
            $total = $qty * $rate;
            
            $html .= '
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . ($item['description'] ?? '') . '</td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;">' . $qty . '</td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">$' . number_format($rate, 2) . '</td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">$' . number_format($total, 2) . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Render totals section
     *
     * @param array $proposalData
     * @return string
     */
    private function renderTotalsSection(array $proposalData): string
    {
        $subtotal = $this->calculateSubtotal($proposalData);
        $discount = $proposalData['discount'] ?? 0;
        $taxRate = 0.10; // 10% GST
        $discountAmount = $subtotal * ($discount / 100);
        $subtotalAfterDiscount = $subtotal - $discountAmount;
        $taxAmount = $subtotalAfterDiscount * $taxRate;
        $total = $subtotalAfterDiscount + $taxAmount;

        return '
        <div class="totals-section" style="margin-top: 30px; text-align: right;">
            <table style="margin-left: auto; width: 300px;">
                <tr>
                    <td style="padding: 8px;">Subtotal:</td>
                    <td style="padding: 8px; text-align: right;">$' . number_format($subtotal, 2) . '</td>
                </tr>
                ' . ($discount > 0 ? '
                <tr>
                    <td style="padding: 8px;">Discount (' . $discount . '%):</td>
                    <td style="padding: 8px; text-align: right;">-$' . number_format($discountAmount, 2) . '</td>
                </tr>' : '') . '
                <tr>
                    <td style="padding: 8px;">GST (10%):</td>
                    <td style="padding: 8px; text-align: right;">$' . number_format($taxAmount, 2) . '</td>
                </tr>
                <tr class="primary-text" style="font-weight: bold; border-top: 2px solid var(--primary-color);">
                    <td style="padding: 12px;">Total:</td>
                    <td style="padding: 12px; text-align: right;">$' . number_format($total, 2) . '</td>
                </tr>
            </table>
        </div>';
    }

    /**
     * Render terms and conditions section (static)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @return string
     */
    private function renderTermsConditions(array $section, $branding): string
    {
        $content = $section['content'];
        
        // Use default content if section content is empty
        if (empty(trim($content))) {
            $content = $this->getDefaultTermsConditions();
        }
        
        return '
        <div class="terms-conditions-section" style="page-break-before: always; padding: 60px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="terms-content" style="line-height: 1.6;">
                ' . $content . '
            </div>
        </div>';
    }

    /**
     * Render change log section (matching OMNIA format)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderChangeLog(array $section, $branding, array $proposalData): string
    {
        $changeEntries = $proposalData['change_entries'] ?? [
            [
                'version' => '1.0',
                'description' => 'Initial Document'
            ]
        ];

        $html = '
        <div class="change-log-section" style="padding: 60px; page-break-after: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                <thead>
                    <tr style="background-color: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: left; width: 20%;">Version</th>
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($changeEntries as $entry) {
            $html .= '
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 10px;">' . ($entry['version'] ?? '') . '</td>
                        <td style="border: 1px solid #ddd; padding: 10px;">' . ($entry['description'] ?? '') . '</td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Render overview section with dynamic headers (H1, H2, H3)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderOverviewSection(array $section, $branding, array $proposalData): string
    {
        $content = $this->replaceVariables($section['content'], $proposalData);
        
        return '
        <div class="overview-section" style="page-break-before: always; padding: 60px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="overview-content" style="line-height: 1.6;">
                ' . $content . '
            </div>
        </div>';
    }

    /**
     * Render payment terms section (dynamic)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderPaymentTerms(array $section, $branding, array $proposalData): string
    {
        $content = $this->replaceVariables($section['content'], $proposalData);
        
        // Add proper list styling to the content
        $content = $this->enhanceListStyling($content);
        
        return '
        <div class="payment-terms-section" style="padding: 60px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="payment-content" style="line-height: 1.6;">
                ' . $content . '
            </div>
        </div>';
    }

    /**
     * Render agreement and signature section (static)
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @param array $proposalData
     * @return string
     */
    private function renderAgreementSignature(array $section, $branding, array $proposalData): string
    {
        $content = $this->replaceVariables($section['content'], $proposalData);
        
        // Use default content if section content is empty
        if (empty(trim($content))) {
            $content = $this->getDefaultAgreementSignature();
        }
        
        return '
        <div class="agreement-signature-section" style="page-break-before: always; padding: 60px; page-break-inside: avoid;">
            <h1 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="agreement-content" style="line-height: 1.6;">
                ' . $content . '
            </div>
        </div>';
    }

    /**
     * Render content section
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @return string
     */
    private function renderContentSection(array $section, $branding): string
    {
        // Enhance list styling for content sections
        $content = $this->enhanceListStyling($section['content']);
        
        // For content sections, don't render the section title - let the dynamic content control its own headings
        return '
        <div class="content-section" style="padding: 60px; page-break-inside: avoid;">
            <div class="section-content" style="line-height: 1.6;">
                ' . $content . '
            </div>
        </div>';
    }

    /**
     * Render terms section with section title
     *
     * @param array $section
     * @param BrandingProfile $branding
     * @return string
     */
    private function renderTermsSection(array $section, $branding): string
    {
        // Enhance list styling for terms sections
        $content = $this->enhanceListStyling($section['content']);
        
        return '
        <div class="terms-section" style="page-break-before: always; padding: 60px; page-break-inside: avoid;">
            <h1 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="section-content" style="line-height: 1.6;">
                ' . $content . '
            </div>
        </div>';
    }

    /**
     * Get HTML document header with branding styles
     *
     * @param BrandingProfile $branding
     * @return string
     */
    private function getHTMLHeader($branding): string
    {
        $colors = $branding->colors ?? [];
        $fonts = $branding->fonts ?? [];

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Proposal</title>
            <style>
                body {
                    font-family: ' . ($fonts['body'] ?? 'Arial, sans-serif') . ';
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 0;
                }
                
                h1, h2, h3, h4, h5, h6 {
                    font-family: ' . ($fonts['heading'] ?? 'Arial, sans-serif') . ';
                    line-height: 1.2;
                    margin-bottom: 15px;
                    margin-top: 0;
                }
                
                h1 {
                    font-size: 18px;
                    color: ' . ($colors['primary'] ?? '#2563eb') . ';
                    font-weight: bold;
                }
                
                h2 {
                    font-size: 15px;
                    color: ' . ($colors['secondary'] ?? '#64748b') . ';
                    font-weight: bold;
                }
                
                h3 {
                    font-size: 13px;
                    color: ' . ($colors['accent'] ?? '#059669') . ';
                    font-weight: bold;
                }
                
                .primary-bg { background-color: ' . ($colors['primary'] ?? '#2563eb') . '; }
                .secondary-bg { background-color: ' . ($colors['secondary'] ?? '#64748b') . '; }
                .accent-bg { background-color: ' . ($colors['accent'] ?? '#059669') . '; }
                
                .primary-text { color: ' . ($colors['primary'] ?? '#2563eb') . '; }
                .secondary-text { color: ' . ($colors['secondary'] ?? '#64748b') . '; }
                .accent-text { color: ' . ($colors['accent'] ?? '#059669') . '; }
                
                .company-logo {
                    max-height: 80px;
                    width: auto;
                    margin: 0 auto;
                    display: block;
                }
                
                .logo-placeholder {
                    width: 80px;
                    height: 80px;
                    border-radius: 40px;
                    text-align: center;
                    vertical-align: middle;
                    color: white;
                    font-weight: bold;
                    font-size: 20px;
                    margin: 0 auto;
                    background-color: ' . ($colors['primary'] ?? '#2563eb') . ';
                    line-height: 80px;
                }
                
                table {
                    border-collapse: collapse;
                    width: 100%;
                    margin-bottom: 20px;
                }
                
                table th,
                table td {
                    border: 1px solid #ddd;
                    padding: 12px;
                    text-align: left;
                    vertical-align: top;
                }
                
                table th {
                    background-color: ' . ($colors['secondary'] ?? '#64748b') . ';
                    color: white;
                    font-weight: bold;
                }
                
                table tr:nth-child(even) {
                    background-color: #f9f9f9;
                }
                
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .text-left { text-align: left; }
                
                .page-break { page-break-before: always; }
                .no-break { page-break-inside: avoid; }
                
                p { margin: 10px 0; font-size: 11px; }
                ul, ol { 
                    margin: 15px 0; 
                    padding-left: 0; 
                    margin-left: 20px;
                    list-style-type: disc;
                    list-style-position: outside;
                }
                ol {
                    list-style-type: decimal;
                }
                li { 
                    margin-bottom: 8px; 
                    padding-left: 20px;
                    margin-left: 0;
                    line-height: 1.6;
                    display: list-item;
                    text-indent: 0;
                }
            </style>
        </head>
        <body>';
    }

    /**
     * Get HTML document footer
     *
     * @param BrandingProfile $branding
     * @return string
     */
    private function getHTMLFooter($branding): string
    {
        return '
        </body>
        </html>';
    }

    /**
     * Replace variables in content with actual values
     *
     * @param string|null $content
     * @param array $data
     * @return string
     */
    private function replaceVariables(?string $content, array $data): string
    {
        // Handle null content
        if ($content === null) {
            return '';
        }

        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            // Convert value to string, handle arrays and objects appropriately
            if (is_array($value)) {
                $stringValue = $this->arrayToString($value);
            } elseif (is_object($value)) {
                $stringValue = method_exists($value, '__toString') ? (string)$value : '[Object]';
            } elseif (is_bool($value)) {
                $stringValue = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $stringValue = '';
            } else {
                $stringValue = (string)$value;
            }
            $content = str_replace($placeholder, $stringValue, $content);
        }

        return $content;
    }

    /**
     * Convert array to string, handling nested arrays and objects
     *
     * @param array $array
     * @return string
     */
    private function arrayToString(array $array): string
    {
        $stringValues = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                $stringValues[] = $this->arrayToString($item);
            } elseif (is_object($item)) {
                $stringValues[] = method_exists($item, '__toString') ? (string)$item : '[Object]';
            } elseif (is_bool($item)) {
                $stringValues[] = $item ? 'true' : 'false';
            } elseif ($item === null) {
                $stringValues[] = '';
            } else {
                $stringValues[] = (string)$item;
            }
        }
        return implode(', ', $stringValues);
    }

    /**
     * Insert table of contents section with auto-generated items
     *
     * @param array $sections
     * @return array
     */
    private function insertTableOfContents(array $sections): array
    {
        $tocItems = [];

        foreach ($sections as &$section) {
            if ($section['type'] === 'toc') {
                // Generate TOC items from other sections with improved page estimation
                foreach ($sections as $tocSection) {
                    if (!in_array($tocSection['type'], ['toc', 'cover_page'])) {
                        $tocItems[] = [
                            'title' => $tocSection['title'],
                            'page' => $this->estimateSectionPageNumber($tocSection, $sections),
                            'type' => $tocSection['type']
                        ];
                    }
                }
                $section['toc_items'] = $tocItems;
                break;
            }
        }

        return $sections;
    }

    /**
     * Calculate subtotal from all items
     *
     * @param array $proposalData
     * @return float
     */
    private function calculateSubtotal(array $proposalData): float
    {
        $subtotal = 0;

        $itemTypes = ['items_onceoff', 'items_monthly_subscription', 'items_yearly_subscription'];
        
        foreach ($itemTypes as $type) {
            if (isset($proposalData[$type]) && is_array($proposalData[$type])) {
                foreach ($proposalData[$type] as $item) {
                    $qty = $item['qty'] ?? 1;
                    $rate = $item['rate'] ?? 0;
                    $subtotal += $qty * $rate;
                }
            }
        }

        return $subtotal;
    }

    /**
     * Get default branding profile
     *
     * @return BrandingProfile
     */
    private function getDefaultBranding()
    {
        $default = BrandingProfile::where('is_default', true)->first();
        
        if (!$default) {
            // Create minimal default branding if none exists
            $default = new BrandingProfile([
                'name' => 'Default',
                'company_name' => config('app.name', 'Your Company'),
                'colors' => [
                    'primary' => '#2563eb',
                    'secondary' => '#64748b',
                    'accent' => '#059669'
                ],
                'fonts' => [
                    'heading' => 'Arial, sans-serif',
                    'body' => 'Arial, sans-serif'
                ],
                'company_info' => []
            ]);
        }

        return $default;
    }

    /**
     * Generate cover page content
     *
     * @param array $proposalData
     * @return string
     */
    private function generateCoverPageContent(array $proposalData): string
    {
        return '
        <div class="cover-description">
            <p style="font-size: 1.2em; margin: 20px 0;">
                We are pleased to present this comprehensive proposal outlining our recommended solution for your business needs.
            </p>
            <p style="margin: 20px 0;">
                This proposal includes detailed information about our services, pricing, and terms to help you make an informed decision.
            </p>
        </div>';
    }

    /**
     * Get default terms and conditions (static content)
     *
     * @return string
     */
    private function getDefaultTermsConditions(): string
    {
        return '
        <h3>General Terms</h3>
        <p>These terms and conditions form part of any contract for the supply of goods and services by {{company_name}}. By accepting this proposal, the client agrees to be bound by these terms.</p>
        
        <h3>Acceptance</h3>
        <p>This proposal is valid for 30 days from the date of issue. Acceptance of this proposal constitutes acceptance of these terms and conditions in their entirety.</p>
        
        <h3>Scope of Work</h3>
        <p>The scope of work is limited to items specifically outlined in this proposal. Any additional work or changes to the scope will require a separate written agreement and may incur additional charges.</p>
        
        <h3>Intellectual Property</h3>
        <p>All intellectual property developed as part of this engagement shall remain the property of {{company_name}} until full payment is received.</p>
        
        <h3>Confidentiality</h3>
        <p>Both parties agree to maintain the confidentiality of all proprietary information exchanged during the course of this engagement.</p>
        
        <h3>Limitation of Liability</h3>
        <p>Our liability is limited to the total amount of this contract. We shall not be liable for any indirect, special, consequential, or punitive damages arising from this agreement.</p>
        
        <h3>Governing Law</h3>
        <p>This agreement shall be governed by and construed in accordance with the laws of the jurisdiction in which {{company_name}} operates.</p>';
    }

    /**
     * Get default overview content with headers
     *
     * @param array $proposalData
     * @return string
     */
    private function getDefaultOverviewContent(array $proposalData): string
    {
        return '
        <h1>Executive Summary</h1>
        <p>This proposal outlines our comprehensive solution for {{customer_name}}. We have carefully analyzed your requirements and designed a tailored approach that addresses your specific business needs.</p>
        
        <h2>Project Overview</h2>
        <p>Our proposed solution encompasses the following key areas:</p>
        <ul>
            <li>Strategic planning and implementation</li>
            <li>Technical solution delivery</li>
            <li>Ongoing support and maintenance</li>
        </ul>
        
        <h3>Key Benefits</h3>
        <ul>
            <li>Improved operational efficiency</li>
            <li>Cost-effective solution delivery</li>
            <li>Scalable architecture for future growth</li>
            <li>Comprehensive support and documentation</li>
        </ul>
        
        <h2>Implementation Approach</h2>
        <p>Our implementation methodology follows industry best practices and includes:</p>
        <ul>
            <li>Detailed project planning and timeline development</li>
            <li>Regular milestone reviews and progress reporting</li>
            <li>Quality assurance and testing protocols</li>
            <li>Knowledge transfer and training programs</li>
        </ul>
        
        <h3>Timeline</h3>
        <p>The proposed implementation timeline allows for thorough planning, execution, and testing to ensure successful delivery of all project components.</p>';
    }

    /**
     * Get default payment terms (dynamic content)
     *
     * @param array $proposalData
     * @return string
     */
    private function getDefaultPaymentTerms(array $proposalData): string
    {
        return '
        <h3>Payment Schedule</h3>
        <p>Payment is due within 30 days of invoice date unless otherwise specified in the agreement.</p>
        
        <h3>Payment Methods</h3>
        <p>We accept payment via:</p>
        <ul>
            <li>Bank transfer (preferred method)</li>
            <li>Credit card (processing fees may apply)</li>
            <li>Company check</li>
        </ul>
        
        <h3>Late Payment</h3>
        <p>A 1.5% monthly service charge may be applied to accounts that remain outstanding beyond the payment terms.</p>
        
        <h3>Proposal Validity</h3>
        <p>This proposal expires on {{due_date}}. Prices are subject to change after this date.</p>
        
        <h3>Deposits</h3>
        <p>A deposit of 50% may be required before commencement of work, with the balance due upon completion.</p>';
    }

    /**
     * Get default agreement and signature section (static content)
     *
     * @return string
     */
    private function getDefaultAgreementSignature(): string
    {
        return '
        <p style="margin-bottom: 30px; line-height: 1.6;">We are excited about the opportunity to work with you on this project and deliver a solution that meets your needs and exceeds your expectations. To proceed, please review the details of the proposal and provide your acceptance by signing below.</p>
        
        <p style="margin-bottom: 50px; line-height: 1.6;">By signing this document, you agree to the scope outlined in this proposal and authorize us to commence work on the project as described.</p>
        
        <div style="margin-bottom: 80px;">
            <span style="font-weight: bold; font-size: inherit;">Client Name:</span>
            <span style="border-bottom: 2px solid #000; display: inline-block; width: 400px; margin-left: 20px; height: 20px;"></span>
        </div>
        
        <div style="margin-bottom: 80px;">
            <span style="font-weight: bold; font-size: inherit;">Signature:</span>
            <span style="border-bottom: 2px solid #000; display: inline-block; width: 400px; margin-left: 35px; height: 20px;"></span>
        </div>
        
        <div style="margin-bottom: 80px;">
            <span style="font-weight: bold; font-size: inherit;">Date:</span>
            <span style="border-bottom: 2px solid #000; display: inline-block; width: 400px; margin-left: 80px; height: 20px;"></span>
        </div>
        
        <p style="margin-top: 50px; line-height: 1.6; font-weight: normal;">We look forward to a successful collaboration.</p>';
    }

    /**
     * Estimate page count for the proposal
     *
     * @param array $sections
     * @return int
     */
    private function estimatePageCount(array $sections): int
    {
        $pageCount = 0;
        
        foreach ($sections as $section) {
            switch ($section['type']) {
                case 'cover_page':
                case 'toc':
                    $pageCount += 1;
                    break;
                case 'quote_items':
                    $pageCount += 2; // Estimate based on typical item count
                    break;
                default:
                    $pageCount += 1;
                    break;
            }
        }

        return max(1, $pageCount);
    }

    /**
     * Generate TOC items for the cover page
     *
     * @param array $proposalData
     * @return array
     */
    private function generateTOCItems(array $proposalData): array
    {
        return [
            ['title' => 'Change Log', 'page' => ''],
            ['title' => 'Overview', 'page' => ''],
            ['title' => '[Heading 1]', 'page' => ''],
            ['title' => '[Heading 2]', 'page' => ''],
            ['title' => 'Acceptance', 'page' => ''],
            ['title' => 'Pricing', 'page' => ''],
            ['title' => 'Payment Terms', 'page' => ''],
            ['title' => 'Agreement Signature', 'page' => ''],
            ['title' => 'Terms and Conditions', 'page' => ''],
        ];
    }

    /**
     * Extract headings from all sections to auto-generate TOC
     *
     * @param array $tocSection
     * @param array $proposalData
     * @return array
     */
    private function extractHeadingsFromAllSections(array $tocSection, array $proposalData): array
    {
        $headings = [];
        $pageNumber = 1;
        
        // Get all sections from the current assembly context
        $allSections = $this->getAllSectionsFromContext();
        
        foreach ($allSections as $section) {
            // Skip TOC and cover page sections
            if (in_array($section['type'], ['toc', 'cover_page'])) {
                continue;
            }
            
            $content = $section['content'] ?? '';
            $sectionType = $section['type'];
            
            // For non-content sections, add the section title as H1 first
            if ($sectionType !== 'content') {
                $headings[] = [
                    'title' => $section['title'] ?? 'Untitled Section',
                    'level' => 1,
                    'page' => $this->estimateSectionPageNumber($section, $allSections),
                    'section_type' => $sectionType
                ];
            }
            
            // Extract H1, H2, H3 headings from content using regex
            $patterns = [
                1 => '/<h1[^>]*>(.*?)<\/h1>/i',
                2 => '/<h2[^>]*>(.*?)<\/h2>/i', 
                3 => '/<h3[^>]*>(.*?)<\/h3>/i'
            ];
            
            foreach ($patterns as $level => $pattern) {
                preg_match_all($pattern, $content, $matches);
                
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $headingText) {
                        // Clean up the heading text (remove HTML tags)
                        $cleanHeading = strip_tags(trim($headingText));
                        
                        if (!empty($cleanHeading)) {
                            $headings[] = [
                                'title' => $cleanHeading,
                                'level' => $level,
                                'page' => $this->estimateSectionPageNumber($section, $allSections),
                                'section_type' => $sectionType
                            ];
                        }
                    }
                }
            }
        }
        
        return $headings;
    }

    /**
     * Estimate page number for a section based on its position and content
     *
     * @param array $targetSection
     * @param array $allSections
     * @return int
     */
    private function estimateSectionPageNumber(array $targetSection, array $allSections): int
    {
        $pageNumber = 1;
        $targetSortOrder = $targetSection['sort_order'] ?? 0;
        
        foreach ($allSections as $section) {
            $sectionSortOrder = $section['sort_order'] ?? 0;
            
            // Stop when we reach the target section
            if ($sectionSortOrder >= $targetSortOrder) {
                break;
            }
            
            // Skip cover page in page counting
            if ($section['type'] === 'cover_page') {
                continue;
            }
            
            // Estimate pages based on section type
            switch ($section['type']) {
                case 'toc':
                    $pageNumber += 1;
                    break;
                case 'quote_items':
                case 'pricing':
                    $pageNumber += 2; // Pricing sections typically longer
                    break;
                case 'terms_conditions':
                case 'agreement_signature':
                    $pageNumber += 1;
                    break;
                case 'content':
                    // Estimate based on content length
                    $contentLength = strlen($section['content'] ?? '');
                    $estimatedPages = max(1, ceil($contentLength / 2000)); // Rough estimate
                    $pageNumber += $estimatedPages;
                    break;
                default:
                    $pageNumber += 1;
                    break;
            }
        }
        
        return $pageNumber;
    }

    /**
     * Get all sections from current context (helper method)
     *
     * @return array
     */
    private function getAllSectionsFromContext(): array
    {
        return $this->allSections;
    }

    /**
     * Extract variables used in section content
     *
     * @param array $sections
     * @return array
     */
    private function extractVariablesUsed(array $sections): array
    {
        $variablesUsed = [];
        
        foreach ($sections as $section) {
            // Check both content and stored variables
            $content = $section['content'] ?? '';
            $storedVariables = $section['variables'] ?? [];
            
            // Find all variables in the format {{variable_name}} from content
            preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);
            
            if (!empty($matches[1])) {
                foreach ($matches[1] as $variable) {
                    $variable = trim($variable);
                    if (!in_array($variable, $variablesUsed)) {
                        $variablesUsed[] = $variable;
                    }
                }
            }
            
            // Also include stored variables from database
            if (!empty($storedVariables) && is_array($storedVariables)) {
                foreach ($storedVariables as $variable) {
                    $variable = trim($variable);
                    if (!in_array($variable, $variablesUsed)) {
                        $variablesUsed[] = $variable;
                    }
                }
            }
            
            // Check title for variables too
            $title = $section['title'] ?? '';
            preg_match_all('/\{\{([^}]+)\}\}/', $title, $titleMatches);
            
            if (!empty($titleMatches[1])) {
                foreach ($titleMatches[1] as $variable) {
                    $variable = trim($variable);
                    if (!in_array($variable, $variablesUsed)) {
                        $variablesUsed[] = $variable;
                    }
                }
            }
        }
        
        return $variablesUsed;
    }

    /**
     * Enhance list styling for better PDF rendering
     *
     * @param string $content
     * @return string
     */
    private function enhanceListStyling(string $content): string
    {
        // Add inline styles to ul and li elements for proper PDF rendering
        $content = preg_replace(
            '/<ul(\s[^>]*)?>/',
            '<ul$1 style="margin: 15px 0; padding-left: 25px; list-style-type: disc;">',
            $content
        );
        
        $content = preg_replace(
            '/<ol(\s[^>]*)?>/',
            '<ol$1 style="margin: 15px 0; padding-left: 25px; list-style-type: decimal;">',
            $content
        );
        
        $content = preg_replace(
            '/<li(\s[^>]*)?>/',
            '<li$1 style="margin: 5px 0; line-height: 1.5; display: list-item;">',
            $content
        );
        
        return $content;
    }
}