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
            // Use template sections
            foreach ($template->sections as $section) {
                $sections[] = [
                    'id' => $section->id,
                    'type' => $section->section_type,
                    'title' => $this->replaceVariables($section->title, $proposalData),
                    'content' => $this->replaceVariables($section->content, $proposalData),
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
                'content' => $this->getDefaultOverviewContent($proposalData),
                'sort_order' => 5,
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
        $html = $this->getHTMLHeader($branding);

        foreach ($sections as $section) {
            $html .= $this->renderSection($section, $branding, $proposalData);
        }

        $html .= $this->getHTMLFooter($branding);

        return $html;
    }

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
                return $this->renderPricingSection($section, $proposalData);
            case 'payment_terms':
                return $this->renderPaymentTerms($section, $branding, $proposalData);
            case 'agreement_signature':
                return $this->renderAgreementSignature($section, $branding, $proposalData);
            case 'content':
            case 'terms':
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
            <div style="margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                <img src="' . $logoUrl . '" alt="' . $companyName . '" style="max-width: 300px; max-height: 120px; object-fit: contain;" />
            </div>';
        }
        
        // Try to use logo_url field if file relationship doesn't exist
        if ($branding && $branding->logo_url) {
            $companyName = $branding->company_name ?? 'Company Logo';
            
            return '
            <div style="margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                <img src="' . $branding->logo_url . '" alt="' . $companyName . '" style="max-width: 300px; max-height: 120px; object-fit: contain;" />
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
        $tocItems = $section['toc_items'] ?? [];
        
        $tocHtml = '
        <div class="table-of-contents" style="page-break-after: always; padding: 80px;">
            <h1 style="font-size: 32px; font-weight: bold; margin-bottom: 40px; text-align: left;">Contents</h1>
            <table class="toc-table" style="width: 100%; border-collapse: collapse; margin-top: 40px;">';

        foreach ($tocItems as $item) {
            $tocHtml .= '
                <tr class="toc-item" style="border-bottom: 1px dotted #ccc;">
                    <td class="toc-title" style="padding: 10px 0; border: none; text-align: left;">' . $item['title'] . '</td>
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
            <h1 style="font-size: 20px; font-weight: bold; margin-bottom: 20px;">' . $section['title'] . '</h1>
            <div class="acceptance-content">
                ' . $this->replaceVariables($section['content'], $proposalData) . '
            </div>
        </div>';
    }

    /**
     * Render pricing section (matching OMNIA format)
     *
     * @param array $section
     * @param array $proposalData
     * @return string
     */
    private function renderPricingSection(array $section, array $proposalData): string
    {
        $html = '
        <div class="pricing-section" style="padding: 70px; page-break-inside: avoid;">
            <h1 style="font-size: 20px; font-weight: bold; margin-bottom: 20px;">' . $section['title'] . '</h1>
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
        return '
        <div class="terms-conditions-section" style="page-break-before: always; padding: 60px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="terms-content" style="line-height: 1.6;">
                ' . $section['content'] . '
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
            <h1 style="font-size: 20px; font-weight: bold; margin-bottom: 20px;">' . $section['title'] . '</h1>
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
        
        return '
        <div class="agreement-signature-section" style="page-break-before: always; padding: 60px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
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
        $pageBreak = in_array($section['type'], ['terms']) ? 'page-break-before: always;' : '';
        
        return '
        <div class="content-section" style="' . $pageBreak . ' padding: 60px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' . ($branding->colors['primary'] ?? '#2563eb') . ';">' . $section['title'] . '</h1>
            <div class="section-content" style="line-height: 1.6;">
                ' . $section['content'] . '
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
                
                p { margin: 10px 0; }
                ul, ol { margin: 10px 0; padding-left: 30px; }
                li { margin-bottom: 5px; }
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
     * @param string $content
     * @param array $data
     * @return string
     */
    private function replaceVariables(string $content, array $data): string
    {
        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
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
        $pageNumber = 1;

        foreach ($sections as &$section) {
            if ($section['type'] === 'toc') {
                // Generate TOC items from other sections (skip cover page and TOC itself)
                foreach ($sections as $tocSection) {
                    if (!in_array($tocSection['type'], ['toc', 'cover_page'])) {
                        $tocItems[] = [
                            'title' => $tocSection['title'],
                            'page' => $pageNumber++,
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
        <div class="agreement-section">
            <h3>Client Acceptance</h3>
            <p>By signing below, the client accepts this proposal and agrees to the terms and conditions outlined herein. This signature constitutes a binding agreement between the parties.</p>
            
            <div class="signature-blocks" style="margin-top: 50px;">
                <div class="client-signature" style="margin-bottom: 50px;">
                    <h4>Client Acceptance:</h4>
                    <div style="margin-top: 30px;">
                        <div style="border-bottom: 1px solid #000; width: 300px; display: inline-block; margin-right: 50px;"></div>
                        <div style="border-bottom: 1px solid #000; width: 150px; display: inline-block;"></div>
                    </div>
                    <div style="margin-top: 10px;">
                        <span style="margin-right: 100px;">Authorized Signature</span>
                        <span style="margin-left: 120px;">Date</span>
                    </div>
                    <div style="margin-top: 20px;">
                        <div style="border-bottom: 1px solid #000; width: 300px; display: inline-block; margin-right: 50px;"></div>
                        <div style="border-bottom: 1px solid #000; width: 150px; display: inline-block;"></div>
                    </div>
                    <div style="margin-top: 10px;">
                        <span style="margin-right: 120px;">Print Name</span>
                        <span style="margin-left: 140px;">Title</span>
                    </div>
                </div>
                
                <div class="company-signature">
                    <h4>{{company_name}} Representative:</h4>
                    <div style="margin-top: 30px;">
                        <div style="border-bottom: 1px solid #000; width: 300px; display: inline-block; margin-right: 50px;"></div>
                        <div style="border-bottom: 1px solid #000; width: 150px; display: inline-block;"></div>
                    </div>
                    <div style="margin-top: 10px;">
                        <span style="margin-right: 100px;">Authorized Signature</span>
                        <span style="margin-left: 120px;">Date</span>
                    </div>
                    <div style="margin-top: 20px;">
                        <div style="border-bottom: 1px solid #000; width: 300px; display: inline-block; margin-right: 50px;"></div>
                        <div style="border-bottom: 1px solid #000; width: 150px; display: inline-block;"></div>
                    </div>
                    <div style="margin-top: 10px;">
                        <span style="margin-right: 120px;">Print Name</span>
                        <span style="margin-left: 140px;">Title</span>
                    </div>
                </div>
            </div>
            
            <div class="agreement-footer" style="margin-top: 50px; padding: 20px; background-color: #f5f5f5; border-left: 4px solid var(--primary-color);">
                <p style="font-style: italic; margin: 0;">This agreement becomes effective upon signature by both parties and supersedes all previous negotiations, representations, or agreements relating to the subject matter herein.</p>
            </div>
        </div>';
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
}