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
            $headerConfig = $config['header_config'] ?? null;

            // Load template and branding first
            $template = $templateId
                ? ProposalTemplate::with('sections')->find($templateId)
                : null;

            // Log the incoming configuration for debugging
            \Log::info(
                'ProposalAssemblyService::assembleProposal - Incoming config',
                [
                    'template_id' => $templateId,
                    'branding_id' => $brandingId,
                    'has_proposal_data' => !empty($proposalData),
                    'sections_count' => count($customSections),
                    'header_config' => $headerConfig,
                    'header_config_type' => gettype($headerConfig),
                    'header_enabled' => $headerConfig['enabled'] ?? 'not_set',
                    'template_styling' =>
                        $template->styling ?? 'no_template_or_styling',
                ]
            );
            $branding = $brandingId
                ? BrandingProfile::with('file')->find($brandingId)
                : $this->getDefaultBranding();

            // Debug branding profile loading
            \Log::info(
                'ProposalAssemblyService::assembleProposal - Branding profile loaded',
                [
                    'branding_id' => $brandingId,
                    'branding_exists' => !is_null($branding),
                    'branding_company_name' =>
                        $branding->company_name ?? 'null',
                    'branding_logo_url' => $branding->logo_url ?? 'null',
                    'branding_has_file_relationship' => !is_null(
                        $branding->file ?? null
                    ),
                    'branding_file_details' => $branding->file
                        ? [
                            'id' => $branding->file->id ?? 'null',
                            'file_path' => $branding->file->file_path ?? 'null',
                            'file_name' => $branding->file->file_name ?? 'null',
                            'file_url' => $branding->file->file_url ?? 'null',
                            'file_extension' =>
                                $branding->file->file_extension ?? 'null',
                        ]
                        : 'no_file_relationship',
                    'branding_company_info' =>
                        $branding->company_info ?? 'null',
                ]
            );

            // Build sections array (without variable replacement for extraction)
            $sectionsForExtraction = $this->buildSectionsForExtraction(
                $template,
                $customSections
            );

            // Extract variables used in the content BEFORE replacement
            $variablesUsed = $this->extractVariablesUsed(
                $sectionsForExtraction
            );

            // Merge branding data into proposal data for variable replacement
            if ($branding) {
                $proposalData['branding_company_name'] =
                    $branding->company_name ?? null;
                $proposalData['branding_primary_color'] =
                    $branding->colors['primary'] ?? null;
                $proposalData['branding_company_info'] =
                    $branding->company_info ?? null;
            }

            // Build sections array with variable replacement
            $sections = $this->buildSections(
                $template,
                $customSections,
                $proposalData,
                $branding
            );

            // Add header_config to proposalData so it's available in assembleHTML
            // Priority order: direct header_config > template.styling.header > default config
            if (!$headerConfig) {
                // Check if template has header configuration in its styling
                $templateHeaderConfig = null;
                if ($template && isset($template->styling['header'])) {
                    $templateHeaderConfig = $template->styling['header'];
                    \Log::info(
                        'ProposalAssemblyService::assembleProposal - Found header config in template styling.header',
                        [
                            'template_header_config' => $templateHeaderConfig,
                        ]
                    );
                } elseif (
                    $template &&
                    isset($template->styling['header_config'])
                ) {
                    // Fallback for old naming convention
                    $templateHeaderConfig = $template->styling['header_config'];
                    \Log::info(
                        'ProposalAssemblyService::assembleProposal - Found header config in template styling.header_config',
                        [
                            'template_header_config' => $templateHeaderConfig,
                        ]
                    );
                }

                // Use template config or default config if we have branding
                if ($templateHeaderConfig) {
                    $headerConfig = $templateHeaderConfig;
                } elseif ($branding) {
                    // Enable headers by default for all proposals with branding
                    $headerConfig = [
                        'enabled' => true,
                        'show_address' => true,
                        'show_phone' => true,
                        'show_website' => false,
                        'show_abn' => false,
                    ];
                    \Log::info(
                        'ProposalAssemblyService::assembleProposal - Using default header config',
                        [
                            'default_header_config' => $headerConfig,
                        ]
                    );
                }
            }
            $proposalData['header_config'] = $headerConfig;

            // Log before HTML assembly
            \Log::info(
                'ProposalAssemblyService::assembleProposal - Before HTML assembly',
                [
                    'header_config_in_proposal_data' =>
                        $proposalData['header_config'],
                    'branding_company_name' =>
                        $branding->company_name ?? 'null',
                ]
            );

            // Generate HTML content
            $html = $this->assembleHTML($sections, $branding, $proposalData);

            // Log after HTML assembly
            \Log::info(
                'ProposalAssemblyService::assembleProposal - After HTML assembly',
                [
                    'html_length' => strlen($html),
                    'html_contains_header' =>
                        strpos($html, 'proposal-header') !== false,
                    'html_contains_has_header' =>
                        strpos($html, 'has-header') !== false,
                ]
            );

            return [
                'html' => $html,
                'sections' => $sections,
                'variables_used' => $variablesUsed,
                'metadata' => [
                    'template' => $template,
                    'branding' => $branding,
                    'header_config' => $headerConfig,
                    'generated_at' => now()->toISOString(),
                    'total_pages' => $this->estimatePageCount($sections),
                ],
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
    private function buildSectionsForExtraction(
        $template,
        array $customSections
    ): array {
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
    private function buildSections(
        $template,
        array $customSections,
        array $proposalData,
        $branding
    ): array {
        $sections = [];

        if ($template && $template->sections) {
            // Check if we have saved proposal content
            $savedContent = $proposalData['proposal_content'] ?? null;

            // Use template sections
            foreach ($template->sections as $section) {
                $content = $section->content;

                // If this is a content section and we have saved content, use the saved content
                if (
                    $section->section_type === 'content' &&
                    !empty($savedContent)
                ) {
                    $content = $savedContent;
                }

                $sections[] = [
                    'id' => $section->id,
                    'type' => $section->section_type,
                    'title' => $this->replaceVariables(
                        $section->title,
                        $proposalData
                    ),
                    'content' => $this->replaceVariables(
                        $content,
                        $proposalData
                    ),
                    'sort_order' => $section->sort_order,
                    'is_dynamic' => $section->is_dynamic,
                    'styling' => $section->styling ?? [],
                    'variables' => $section->variables ?? [],
                ];
            }
        } else {
            // Use custom sections or default structure
            $sections = $this->buildDefaultSections(
                $customSections,
                $proposalData
            );
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
    private function buildDefaultSections(
        array $customSections,
        array $proposalData
    ): array {
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
                'content' =>
                    $savedContent ?:
                    $this->getDefaultOverviewContent($proposalData),
                'sort_order' => 5,
                'is_dynamic' => true,
                'styling' => [],
                'variables' => [],
            ],
            [
                'type' => 'content',
                'title' => 'Proposal Content',
                'content' =>
                    $savedContent ?: '<p>No custom content provided.</p>',
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
    private function assembleHTML(
        array $sections,
        $branding,
        array $proposalData
    ): string {
        // Store sections for TOC generation
        $this->allSections = $sections;

        // Check if there's a cover page section
        $hasCoverPage = false;
        foreach ($sections as $section) {
            $sectionType = is_array($section)
                ? $section['section_type'] ?? null
                : $section->section_type ?? null;
            if ($sectionType === 'cover_page') {
                $hasCoverPage = true;
                break;
            }
        }

        // Get header configuration from proposalData
        $headerConfig = $proposalData['header_config'] ?? null;

        // Log header config extraction
        \Log::info(
            'ProposalAssemblyService::assembleHTML - Header config extraction',
            [
                'proposal_data_keys' => array_keys($proposalData),
                'header_config_from_proposal_data' => $headerConfig,
                'has_cover_page' => $hasCoverPage,
            ]
        );

        $html = trim(
            $this->getHTMLHeader($branding, $hasCoverPage, $headerConfig)
        );

        foreach ($sections as $index => $section) {
            $sectionHtml = $this->renderSection(
                $section,
                $branding,
                $proposalData
            );
            // Trim whitespace from section HTML to prevent blank pages
            $html .= trim($sectionHtml);
        }

        \Log::info(
            'ProposalAssemblyService::assembleHTML - Sections rendered',
            [
                'total_sections' => count($sections),
                'header_enabled' =>
                    $headerConfig && ($headerConfig['enabled'] ?? false),
            ]
        );

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
    private function renderSection(
        array $section,
        $branding,
        array $proposalData
    ): string {
        switch ($section['type']) {
            case 'cover_page':
                return $this->renderCoverPage(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'toc':
                return $this->renderTableOfContents($section, $proposalData);
            case 'terms_conditions':
                return $this->renderTermsConditions(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'review_log':
                return $this->renderChangeLog(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'overview':
                return $this->renderOverviewSection(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'acceptance':
                return $this->renderAcceptanceSection(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'quote_items':
                return $this->renderPricingSection(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'payment_terms':
                return $this->renderPaymentTerms(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'agreement_signature':
                return $this->renderAgreementSignature(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'content':
                return $this->renderContentSection(
                    $section,
                    $branding,
                    $proposalData
                );
            case 'terms':
                return $this->renderTermsSection(
                    $section,
                    $branding,
                    $proposalData
                );
            default:
                return $this->renderContentSection(
                    $section,
                    $branding,
                    $proposalData
                );
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
    private function renderCoverPage(
        array $section,
        $branding,
        array $proposalData
    ): string {
        // Get logo from branding profile
        $logoHtml = $this->renderBrandingLogo($branding);

        return '
        <div class="omnia-cover-page" style="page-break-after: always; padding: 0; background-color: #1f2937 !important; color: white !important; height: 297mm; position: relative; font-family: Arial, sans-serif; margin: 0; box-sizing: border-box; width: 100%; overflow: hidden; page-break-inside: avoid;">
            
            <!-- Logo positioned in upper left -->
            <div class="logo-section" style="position: absolute; top: 60px; left: 60px; z-index: 10;">
                ' .
            $logoHtml .
            '
            </div>
            
            <!-- Quote title left-aligned in middle vertical area -->
            <div class="cover-title" style="position: absolute; top: 50%; left: 60px; transform: translateY(-50%); text-align: left; width: calc(100% - 120px); max-width: 1000px; padding: 0; box-sizing: border-box;">
                <h1 style="font-size: 72px; font-weight: 300; margin: 0; color: #6EE7B7 !important; line-height: 1.2; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); word-wrap: normal; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; letter-spacing: -0.5px;">' .
            ($proposalData['document_title'] ?? '[Document Title]') .
            '</h1>
            </div>
            
            <!-- Company details at bottom -->
            <div class="cover-footer" style="position: absolute; bottom: 40px; left: 60px; text-align: left; z-index: 10;">
                <p style="font-size: 16px; color: white !important; margin: 0; font-weight: 500; letter-spacing: 0.5px;">' .
            strtoupper(
                $branding->company_name ?? 'OMNIA GLOBAL GROUP PTY LTD'
            ) .
            ' &nbsp;&nbsp;ACN ' .
            ($branding->company_info['acn'] ?? '674 383 987') .
            '</p>
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
            <div style="text-align: left;">
                <img src="' .
                $logoUrl .
                '" alt="' .
                $companyName .
                '" style="max-width: 320px; max-height: 120px; object-fit: contain;" />
            </div>';
        }

        // Try to use logo_url field if file relationship doesn't exist
        if ($branding && $branding->logo_url) {
            $companyName = $branding->company_name ?? 'Company Logo';

            return '
            <div style="text-align: left;">
                <img src="' .
                $branding->logo_url .
                '" alt="' .
                $companyName .
                '" style="max-width: 320px; max-height: 120px; object-fit: contain;" />
            </div>';
        }

        // Fallback to company name text if no logo available
        $companyName = $branding->company_name ?? 'OmniaGlobal';
        return '
        <div style="width: 280px; height: 80px; background: rgba(255,255,255,0.95); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
            <span style="color: #059669; font-size: 26px; font-weight: bold; letter-spacing: -0.5px;">' .
            $companyName .
            '</span>
        </div>';
    }

    /**
     * Render table of contents
     *
     * @param array $section
     * @param array $proposalData
     * @return string
     */
    private function renderTableOfContents(
        array $section,
        array $proposalData
    ): string {
        // Auto-generate TOC items from all sections' headings
        $tocItems = $this->extractHeadingsFromAllSections(
            $section,
            $proposalData
        );

        // Use manually defined TOC items if no headings found
        if (empty($tocItems)) {
            $tocItems = $section['toc_items'] ?? [];
        }

        // Check if TOC has meaningful content (not just placeholders)
        $hasRealContent = false;
        foreach ($tocItems as $item) {
            $title = $item['title'] ?? '';
            // Skip items that are empty or look like placeholders [Heading 1], [Heading 2], etc.
            if (!empty($title) && !preg_match('/^\[.*\]$/', $title)) {
                $hasRealContent = true;
                break;
            }
        }

        // If no real content, skip the TOC entirely to avoid blank page
        if (!$hasRealContent || empty($tocItems)) {
            return '';
        }

        $tocHtml = '
        <div class="table-of-contents" style="page-break-after: always; padding: 70px 90px;">
            <h1 style="font-size: 32px; font-weight: bold; margin-bottom: 40px; text-align: left;">{{TOC_TITLE}}</h1>
            <table class="toc-table" style="width: 100%; border-collapse: collapse; margin-top: 40px;">';

        foreach ($tocItems as $item) {
            // Add indentation based on heading level
            $indent = '';
            switch ($item['level'] ?? 1) {
                case 2:
                    $indent = '&nbsp;&nbsp;&nbsp;&nbsp;';
                    break;
                case 3:
                    $indent =
                        '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
                    break;
                default:
                    $indent = '';
                    break;
            }

            $tocHtml .=
                '
                <tr class="toc-item" style="border-bottom: 1px dotted #ccc;">
                    <td class="toc-title" style="padding: 10px 0; border: none; text-align: left;">' .
                $indent .
                $item['title'] .
                '</td>
                    <td class="toc-page" style="padding: 10px 0; border: none; text-align: right; width: 50px;">' .
                ($item['page'] ?? '') .
                '</td>
                </tr>';
        }

        $tocHtml .= '
            </table>
        </div>';

        // Replace the placeholder with the actual section title
        // Use a safe approach to avoid PDF rendering issues
        $sectionTitle = isset($section['title']) && !empty(trim($section['title'])) 
            ? trim($section['title'])
            : 'Contents';
        
        $tocHtml = str_replace('{{TOC_TITLE}}', htmlspecialchars($sectionTitle), $tocHtml);

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
    private function renderAcceptanceSection(
        array $section,
        $branding,
        array $proposalData
    ): string {
        return '
        <div class="acceptance-section" style="padding: 30px 40px; page-break-after: avoid;">
            <h1 style="margin-bottom: 30px; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            ';">' .
            $section['title'] .
            '</h1>
            <div class="acceptance-content">
                ' .
            $this->replaceVariables($section['content'], $proposalData) .
            '
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
    private function renderPricingSection(
        array $section,
        $branding,
        array $proposalData
    ): string {
        // Get intelligent colors from branding profile
        $colors = $this->getTableColors($branding);
        $tableColor = $colors['header']; // Both header and total use the same color now
        $headerColor = $tableColor;
        $totalColor = $tableColor;

        $html =
            '
    <div class="pricing-section" style="padding: 30px 40px; page-break-inside: avoid;">
        <h1 style="margin-bottom: 30px; color: #1F2937; font-size: 28px; font-weight: bold;">' .
            $section['title'] .
            '</h1>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; border: 1px solid #E5E7EB; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <thead>
                <tr style="background-color: ' .
            $headerColor .
            ' !important;">
                    <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: left; vertical-align: middle; color: white !important; font-weight: bold; font-size: 14px;">Description</th>
                    <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: right; vertical-align: middle; width: 120px; color: white !important; font-weight: bold; font-size: 14px;">Price</th>
                    <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: center; vertical-align: middle; width: 60px; color: white !important; font-weight: bold; font-size: 14px;">Qty</th>
                    <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: right; vertical-align: middle; width: 120px; color: white !important; font-weight: bold; font-size: 14px;">Amount</th>
                </tr>
            </thead>
            <tbody>';

        // Add all quote items in OMNIA format
        $allItems = [];
        if (isset($proposalData['items_onceoff'])) {
            $allItems = array_merge($allItems, $proposalData['items_onceoff']);
        }
        if (isset($proposalData['items_monthly_subscription'])) {
            $allItems = array_merge(
                $allItems,
                $proposalData['items_monthly_subscription']
            );
        }
        if (isset($proposalData['items_yearly_subscription'])) {
            $allItems = array_merge(
                $allItems,
                $proposalData['items_yearly_subscription']
            );
        }

        // Add development placeholder if no items
        if (empty($allItems)) {
            $html .= '
                <tr style="background-color: #FFFFFF;">
                    <td style="border: 1px solid #E5E7EB; padding: 14px; font-size: 14px; color: #374151; font-weight: 500;">Development</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-size: 14px; color: #374151;">$19,750.00</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: center; font-size: 14px; color: #374151;">1</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-size: 14px; color: #374151; font-weight: 600;">$19,750.00</td>
                </tr>';
        } else {
            $rowIndex = 0;
            foreach ($allItems as $item) {
                $qty = $item['qty'] ?? 1;
                $rate = $item['rate'] ?? 0;
                $total = $qty * $rate;
                $rowBg = $rowIndex % 2 === 0 ? '#FFFFFF' : '#F9FAFB';
                $html .=
                    '
                <tr style="background-color: ' .
                    $rowBg .
                    ';">
                    <td style="border: 1px solid #E5E7EB; padding: 14px; font-size: 14px; color: #374151; font-weight: 500;">' .
                    ($item['description'] ?? '') .
                    '</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-size: 14px; color: #374151; font-weight: 500;">$' .
                    number_format($rate, 2) .
                    '</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: center; font-size: 14px; color: #374151; font-weight: 500;">' .
                    $qty .
                    '</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-size: 14px; color: #374151; font-weight: 600;">$' .
                    number_format($total, 2) .
                    '</td>
                </tr>';
                $rowIndex++;
            }
        }

        // Add totals rows
        $subtotal = $this->calculateSubtotal($proposalData);
        $taxAmount = $subtotal * 0.1;
        $total = $subtotal + $taxAmount;

        $html .=
            '
            </tbody>
            <tfoot>
                <tr style="background-color: #F3F4F6;">
                    <td colspan="3" style="border: 1px solid #E5E7EB; border-top: 2px solid #D1D5DB; padding: 12px; text-align: right; font-weight: bold; font-size: 14px; color: #374151;">Sub Total:</td>
                    <td style="border: 1px solid #E5E7EB; border-top: 2px solid #D1D5DB; padding: 12px; text-align: right; font-weight: bold; font-size: 14px; color: #374151;">$' .
            number_format($subtotal, 2) .
            '</td>
                </tr>
                <tr style="background-color: #F3F4F6;">
                    <td colspan="3" style="border: 1px solid #E5E7EB; padding: 12px; text-align: right; font-weight: bold; font-size: 14px; color: #374151;">Tax (10%):</td>
                    <td style="border: 1px solid #E5E7EB; padding: 12px; text-align: right; font-weight: bold; font-size: 14px; color: #374151;">$' .
            number_format($taxAmount, 2) .
            '</td>
                </tr>
                <tr style="background-color: ' .
            $totalColor .
            ' !important;">
                    <td colspan="3" style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-weight: bold; font-size: 15px; color: white !important;">Total:</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-weight: bold; font-size: 16px; color: white !important;">$' .
            number_format($total, 2) .
            '</td>
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
    private function renderItemsTable(
        string $title,
        array $items,
        $branding = null
    ): string {
        // Use branding colors if available, with fallback to neutral
        $colors = $this->getTableColors($branding);
        $headerColor = $colors['header'];

        $html =
            '
        <div style="margin: 30px 0;">
            <h2 style="margin-bottom: 15px; color: #059669; font-size: 18px; font-weight: bold;">' .
            $title .
            '</h2>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #E5E7EB; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <thead>
                    <tr style="background-color: ' .
            $headerColor .
            ' !important;">
                        <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: left; vertical-align: middle; color: white !important; font-weight: bold; font-size: 14px;">Description</th>
                        <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: center; vertical-align: middle; width: 60px; color: white !important; font-weight: bold; font-size: 14px;">Qty</th>
                        <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: right; vertical-align: middle; width: 120px; color: white !important; font-weight: bold; font-size: 14px;">Rate</th>
                        <th style="border: 1px solid #E5E7EB; padding: 16px; text-align: right; vertical-align: middle; width: 120px; color: white !important; font-weight: bold; font-size: 14px;">Total</th>
                    </tr>
                </thead>
                <tbody>';

        $rowIndex = 0;
        foreach ($items as $item) {
            $qty = $item['qty'] ?? 1;
            $rate = $item['rate'] ?? 0;
            $total = $qty * $rate;
            $rowBg = $rowIndex % 2 === 0 ? '#FFFFFF' : '#F9FAFB';

            $html .=
                '
                <tr style="background-color: ' .
                $rowBg .
                ';">
                    <td style="border: 1px solid #E5E7EB; padding: 14px; font-size: 14px; color: #374151; font-weight: 500;">' .
                ($item['description'] ?? '') .
                '</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: center; font-size: 14px; color: #374151; font-weight: 500;">' .
                $qty .
                '</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-size: 14px; color: #374151; font-weight: 500;">$' .
                number_format($rate, 2) .
                '</td>
                    <td style="border: 1px solid #E5E7EB; padding: 14px; text-align: right; font-size: 14px; color: #374151; font-weight: 600;">$' .
                number_format($total, 2) .
                '</td>
                </tr>';
            $rowIndex++;
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
    private function renderTotalsSection(
        array $proposalData,
        $branding = null
    ): string {
        $subtotal = $this->calculateSubtotal($proposalData);
        $discount = $proposalData['discount'] ?? 0;
        $taxRate = 0.1; // 10% GST
        $discountAmount = $subtotal * ($discount / 100);
        $subtotalAfterDiscount = $subtotal - $discountAmount;
        $taxAmount = $subtotalAfterDiscount * $taxRate;
        $total = $subtotalAfterDiscount + $taxAmount;

        // Get branding colors for consistent styling
        $colors = $this->getTableColors($branding);
        $totalColor = $colors['total'];

        return '
        <div style="margin-top: 30px; text-align: right;">
            <table style="margin-left: auto; width: 300px; border-collapse: collapse; border: 1px solid #E5E7EB; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <tr style="background-color: #F3F4F6;">
                    <td style="border: 1px solid #E5E7EB; padding: 12px; font-size: 14px; color: #374151; font-weight: bold;">Subtotal:</td>
                    <td style="border: 1px solid #E5E7EB; padding: 12px; text-align: right; font-size: 14px; color: #374151; font-weight: bold;">$' .
            number_format($subtotal, 2) .
            '</td>
                </tr>
                ' .
            ($discount > 0
                ? '
                <tr style="background-color: #F3F4F6;">
                    <td style="border: 1px solid #E5E7EB; padding: 12px; font-size: 14px; color: #374151; font-weight: bold;">Discount (' .
                    $discount .
                    '%):</td>
                    <td style="border: 1px solid #E5E7EB; padding: 12px; text-align: right; font-size: 14px; color: #374151; font-weight: bold;">-$' .
                    number_format($discountAmount, 2) .
                    '</td>
                </tr>'
                : '') .
            '
                <tr style="background-color: #F3F4F6;">
                    <td style="border: 1px solid #E5E7EB; padding: 12px; font-size: 14px; color: #374151; font-weight: bold;">Tax (10%):</td>
                    <td style="border: 1px solid #E5E7EB; padding: 12px; text-align: right; font-size: 14px; color: #374151; font-weight: bold;">$' .
            number_format($taxAmount, 2) .
            '</td>
                </tr>
                <tr style="background-color: ' .
            $totalColor .
            ' !important;">
                    <td style="border: 1px solid #E5E7EB; border-top: 2px solid #D1D5DB; padding: 14px; font-size: 15px; color: white !important; font-weight: bold;">Total:</td>
                    <td style="border: 1px solid #E5E7EB; border-top: 2px solid #D1D5DB; padding: 14px; text-align: right; font-size: 16px; color: white !important; font-weight: bold;">$' .
            number_format($total, 2) .
            '</td>
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
    private function renderTermsConditions(
        array $section,
        $branding,
        array $proposalData
    ): string {
        $content = $this->replaceVariables($section['content'], $proposalData);

        // Use default content if section content is empty
        if (empty(trim($content))) {
            $content = $this->getDefaultTermsConditions();
        }

        return '
        <div class="terms-conditions-section" style="padding: 30px 40px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            ';">' .
            $section['title'] .
            '</h1>
            <div class="terms-content" style="line-height: 1.6;">
                ' .
            $content .
            '
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
    private function renderChangeLog(
        array $section,
        $branding,
        array $proposalData
    ): string {
        $changeEntries = $proposalData['change_entries'] ?? [
            [
                'version' => '1.0',
                'description' => 'Initial Document',
            ],
        ];

        $html =
            '
        <div class="change-log-section" style="padding: 30px 40px; page-break-after: avoid;">
            <h1 style="margin-bottom: 30px; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            ';">' .
            $section['title'] .
            '</h1>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                <thead>
                    <tr style="background-color: #f5f5f5;">
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: left; width: 20%;">Version</th>
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($changeEntries as $entry) {
            $html .=
                '
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 10px;">' .
                ($entry['version'] ?? '') .
                '</td>
                        <td style="border: 1px solid #ddd; padding: 10px;">' .
                ($entry['description'] ?? '') .
                '</td>
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
    private function renderOverviewSection(
        array $section,
        $branding,
        array $proposalData
    ): string {
        $content = $this->replaceVariables($section['content'], $proposalData);

        return '
        <div class="overview-section" style="page-break-before: always; padding: 30px 40px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            ';">' .
            $section['title'] .
            '</h1>
            <div class="overview-content" style="line-height: 1.6;">
                ' .
            $content .
            '
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
    private function renderPaymentTerms(
        array $section,
        $branding,
        array $proposalData
    ): string {
        $content = $this->replaceVariables($section['content'], $proposalData);

        // Add proper list styling to the content
        $content = $this->enhanceListStyling($content);

        return '
        <div class="payment-terms-section" style="page-break-before: always; padding: 30px 40px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            '; font-size: 28px; font-weight: bold;">' .
            $section['title'] .
            '</h1>
            <div class="payment-content" style="line-height: 1.6; font-size: 16px; color: #333;">
                ' .
            $content .
            '
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
    private function renderAgreementSignature(
        array $section,
        $branding,
        array $proposalData
    ): string {
        $content = $this->replaceVariables($section['content'], $proposalData);

        // Use default content if section content is empty
        if (empty(trim($content))) {
            $content = $this->getDefaultAgreementSignature();
        }

        // Strip border styles from the content HTML
        $content = $this->removeBorderStyles($content);

        return '
        <div class="agreement-signature-section" style="padding: 30px 40px; page-break-inside: avoid; border: none !important; outline: none !important;">
            <h1 style="margin-bottom: 16px; margin-top: 0; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            ';">' .
            $section['title'] .
            '</h1>
            <div class="agreement-content" style="line-height: 1.5; border: none !important; box-shadow: none !important; outline: none !important; background: transparent !important; border-width: 0px !important; border-style: none !important; padding: 0; margin: 0;">
                ' .
            $content .
            '
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
    private function renderContentSection(
        array $section,
        $branding,
        array $proposalData
    ): string {
        // Replace variables and enhance list styling for content sections
        $content = $this->replaceVariables($section['content'], $proposalData);
        $content = $this->enhanceListStyling($content);

        // For content sections, don't render the section title - let the dynamic content control its own headings
        return '
        <div class="content-section" style="padding: 30px 40px; page-break-inside: avoid; page-break-after: avoid;">
            <div class="section-content" style="line-height: 1.5;">
                ' .
            $content .
            '
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
    private function renderTermsSection(
        array $section,
        $branding,
        array $proposalData
    ): string {
        // Replace variables and enhance list styling for terms sections
        $content = $this->replaceVariables($section['content'], $proposalData);
        $content = $this->enhanceListStyling($content);

        return '
        <div class="terms-section" style="padding: 30px 40px; page-break-inside: avoid;">
            <h1 style="margin-bottom: 30px; color: ' .
            ($branding->colors['primary'] ?? '#000000') .
            ';">' .
            $section['title'] .
            '</h1>
            <div class="section-content" style="line-height: 1.6;">
                ' .
            $content .
            '
            </div>
        </div>';
    }

    /**
     * Get shared CSS constants for consistent styling between preview and PDF
     *
     * @return array
     */
    public static function getSharedCSSConstants(): array
    {
        return [
            'cover_bg_color' => '#059669',
            'cover_text_color' => '#4ade80',
            'table_header_bg' => '#6B7280',
            'table_border_color' => '#333333',
            'table_total_bg' => '#333333',
            'table_row_alt_bg' => '#f9f9f9',
            'table_subtotal_bg' => '#f3f4f6',
            'font_family' => 'Arial, sans-serif',
            'text_color' => '#374151',
        ];
    }

    /**
     * Get intelligent table colors based on branding profile
     * Ensures good readability and contrast
     *
     * @param mixed $branding BrandingProfile or null
     * @return array
     */
    private function getTableColors($branding): array
    {
        // Default fallback colors for good readability
        $defaultColors = [
            'header' => '#6B7280', // Neutral gray
            'total' => '#4B5563', // Darker gray
        ];

        // If no branding provided, use defaults
        if (!$branding || !isset($branding->colors)) {
            return $defaultColors;
        }

        $brandingColors = $branding->colors ?? [];
        $primary = $brandingColors['primary'] ?? null;
        $secondary = $brandingColors['secondary'] ?? null;
        $accent = $brandingColors['accent'] ?? null;

        // Use the same color for both header and total for symmetrical design
        $tableColor = $this->selectReadableTableColor(
            [$primary, $secondary, $accent],
            $defaultColors['header']
        );

        return [
            'header' => $tableColor,
            'total' => $tableColor,
        ];
    }

    /**
     * Select a readable color for table backgrounds
     * Checks contrast ratio and color brightness
     *
     * @param array $candidateColors
     * @param string $fallback
     * @return string
     */
    private function selectReadableTableColor(
        array $candidateColors,
        string $fallback
    ): string {
        foreach ($candidateColors as $color) {
            if (!$color || !$this->isValidHexColor($color)) {
                continue;
            }

            // Check if color has good contrast and isn't too light
            if ($this->isGoodTableBackgroundColor($color)) {
                return $color;
            }

            // Try darkening the color if it's too light
            $darkenedColor = $this->darkenColor($color, 0.3);
            if ($this->isGoodTableBackgroundColor($darkenedColor)) {
                return $darkenedColor;
            }
        }

        return $fallback;
    }

    /**
     * Check if a color is suitable for table background with white text
     *
     * @param string $hexColor
     * @return bool
     */
    private function isGoodTableBackgroundColor(string $hexColor): bool
    {
        $rgb = $this->hexToRgb($hexColor);
        if (!$rgb) {
            return false;
        }

        // Calculate relative luminance
        $luminance = $this->getRelativeLuminance($rgb);

        // White text (luminance = 1) on colored background
        // WCAG AA requires contrast ratio of at least 4.5:1 for normal text
        // For table headers, we want at least 3:1 for good readability
        $contrastRatio = (1 + 0.05) / ($luminance + 0.05);

        return $contrastRatio >= 3.0;
    }

    /**
     * Convert hex color to RGB array
     *
     * @param string $hex
     * @return array|null
     */
    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return null;
        }

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Calculate relative luminance of an RGB color
     *
     * @param array $rgb
     * @return float
     */
    private function getRelativeLuminance(array $rgb): float
    {
        $convert = function ($value) {
            $value = $value / 255;
            return $value <= 0.03928
                ? $value / 12.92
                : pow(($value + 0.055) / 1.055, 2.4);
        };

        $r = $convert($rgb['r']);
        $g = $convert($rgb['g']);
        $b = $convert($rgb['b']);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Darken a hex color by a percentage
     *
     * @param string $hex
     * @param float $percent (0.0 to 1.0)
     * @return string
     */
    private function darkenColor(string $hex, float $percent): string
    {
        $rgb = $this->hexToRgb($hex);
        if (!$rgb) {
            return $hex;
        }

        $rgb['r'] = max(0, $rgb['r'] * (1 - $percent));
        $rgb['g'] = max(0, $rgb['g'] * (1 - $percent));
        $rgb['b'] = max(0, $rgb['b'] * (1 - $percent));

        return sprintf('#%02x%02x%02x', $rgb['r'], $rgb['g'], $rgb['b']);
    }

    /**
     * Validate hex color format
     *
     * @param string $hex
     * @return bool
     */
    private function isValidHexColor(string $hex): bool
    {
        return preg_match('/^#[a-fA-F0-9]{6}$/', $hex) === 1;
    }

    /**
     * Get HTML document header with branding styles
     *
     * @param BrandingProfile $branding
     * @param bool $hasCoverPage
     * @param array|null $headerConfig
     * @return string
     */
    private function getHTMLHeader(
        $branding,
        $hasCoverPage = false,
        $headerConfig = null
    ): string {
        // Log header generation
        \Log::info('ProposalAssemblyService::getHTMLHeader - Called', [
            'has_branding' => !is_null($branding),
            'branding_company_name' => $branding->company_name ?? 'null',
            'has_cover_page' => $hasCoverPage,
            'header_config' => $headerConfig,
            'header_config_type' => gettype($headerConfig),
            'header_enabled' => $headerConfig['enabled'] ?? 'not_set',
        ]);

        $colors = $branding->colors ?? [];
        $fonts = $branding->fonts ?? [];
        $cssConstants = self::getSharedCSSConstants();

        // Determine body class
        $hasHeaderClass =
            $headerConfig && ($headerConfig['enabled'] ?? false)
                ? ' class="has-header"'
                : '';
        \Log::info(
            'ProposalAssemblyService::getHTMLHeader - Body class generation',
            [
                'header_condition_met' =>
                    $headerConfig && ($headerConfig['enabled'] ?? false),
                'body_class_string' => $hasHeaderClass,
            ]
        );

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Proposal</title>
            <style>
                @page {
                    margin: 0;
                }
                
                .omnia-cover-page {
                    margin: 0 !important;
                    padding: 0 !important;
                }
                
                .acceptance-section, 
                .pricing-section, 
                .terms-conditions-section, 
                .change-log-section, 
                .overview-section, 
                .payment-terms-section, 
                .agreement-signature-section, 
                .content-section, 
                .terms-section {
                    margin: 40px 50px;
                }
                
                body {
                    font-family: ' .
            ($fonts['body'] ?? $cssConstants['font_family']) .
            ';
                    font-size: 16px;
                    line-height: 1.6;
                    color: #333;
                    margin: 0 !important;
                    padding: 0 !important;
                }
                
                /* Ensure first child starts at top of page */
                body > *:first-child {
                    margin-top: 0 !important;
                    padding-top: 0 !important;
                }
                
                /* Force cover page to start at page top */
                .omnia-cover-page {
                    position: relative;
                    margin: 0 !important;
                    padding: 0 !important;
                    page-break-before: avoid !important;
                }
                
                h1, h2, h3, h4, h5, h6 {
                    font-family: ' .
            ($fonts['heading'] ?? 'Arial, sans-serif') .
            ';
                    line-height: 1.3;
                    margin-bottom: 16px;
                    margin-top: 0;
                    font-weight: bold;
                }
                
                h1 {
                    font-size: 24px;
                    color: ' .
            ($colors['primary'] ?? '#000000') .
            ';
                    font-weight: bold;
                    margin-bottom: 16px;
                    margin-top: 0;
                    margin-left: 0;
                    padding-left: 0;
                }
                
                h2 {
                    font-size: 20px;
                    color: ' .
            ($colors['secondary'] ?? '#64748b') .
            ';
                    font-weight: bold;
                    margin-bottom: 14px;
                    margin-top: 0;
                }
                
                h3 {
                    font-size: 18px;
                    color: ' .
            ($colors['accent'] ?? '#10b981') .
            ';
                    font-weight: bold;
                    margin-bottom: 12px;
                    margin-top: 0;
                }
                
                h4 {
                    font-size: 16px;
                    color: ' .
            ($colors['secondary'] ?? '#64748b') .
            ';
                    font-weight: bold;
                    margin-bottom: 10px;
                }
                
                h5 {
                    font-size: 15px;
                    color: ' .
            ($colors['secondary'] ?? '#64748b') .
            ';
                    font-weight: bold;
                    margin-bottom: 8px;
                }
                
                h6 {
                    font-size: 14px;
                    color: ' .
            ($colors['secondary'] ?? '#64748b') .
            ';
                    font-weight: bold;
                    margin-bottom: 6px;
                }
                
                .primary-bg { background-color: ' .
            ($colors['primary'] ?? '#000000') .
            '; }
                .secondary-bg { background-color: ' .
            ($colors['secondary'] ?? '#64748b') .
            '; }
                .accent-bg { background-color: ' .
            ($colors['accent'] ?? '#059669') .
            '; }
                
                .primary-text { color: ' .
            ($colors['primary'] ?? '#000000') .
            '; }
                .secondary-text { color: ' .
            ($colors['secondary'] ?? '#64748b') .
            '; }
                .accent-text { color: ' .
            ($colors['accent'] ?? '#059669') .
            '; }
                
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
                    background-color: ' .
            ($colors['primary'] ?? '#000000') .
            ';
                    line-height: 80px;
                }
                
                table {
                    border-collapse: collapse;
                    width: 100%;
                    margin-bottom: 20px;
                }
                
                table th,
                table td {
                    border: 1px solid ' .
            $cssConstants['table_border_color'] .
            ';
                    padding: 14px;
                    text-align: left;
                    vertical-align: middle;
                    font-size: 14px;
                    line-height: 1.4;
                    font-weight: 500;
                }
                
                /* Honor inline border styles - tables with border="0" or border-style: none */
                table[border="0"],
                table[style*="border-style: none"],
                table[style*="border:none"],
                table[style*="border: none"] {
                    border: none !important;
                }
                
                table[border="0"] th,
                table[border="0"] td,
                table[style*="border-style: none"] th,
                table[style*="border-style: none"] td,
                table[style*="border:none"] th,
                table[style*="border:none"] td,
                table[style*="border: none"] th,
                table[style*="border: none"] td {
                    border: none !important;
                }
                
                /* Table headers now use individual branding colors per table */
                
                table tr:nth-child(even) {
                    background-color: ' .
            $cssConstants['table_row_alt_bg'] .
            ';
                }
                
                table tfoot tr {
                    background-color: ' .
            $cssConstants['table_subtotal_bg'] .
            ';
                }
                
                table tfoot tr:last-child {
                    background-color: ' .
            $cssConstants['table_total_bg'] .
            ' !important;
                }
                
                table tfoot tr:last-child td {
                    color: white !important;
                    font-weight: bold;
                }
                
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .text-left { text-align: left; }
                
                .page-break { page-break-before: always; }
                .no-break { page-break-inside: avoid; }
                
                /* Override container borders only */
                .agreement-content,
                .agreement-signature-section {
                    border: none !important;
                    outline: none !important;
                    box-shadow: none !important;
                    background: transparent !important;
                }
                
                /* Remove borders from any proposal-header elements within signature section */
                .agreement-signature-section .proposal-header,
                .agreement-signature-section .proposal-header *,
                .agreement-content .proposal-header,
                .agreement-content .proposal-header *,
                .agreement-signature-section *,
                .agreement-content *,
                .agreement-signature-section div,
                .agreement-content div,
                .agreement-signature-section p,
                .agreement-content p {
                    border: none !important;
                    outline: none !important;
                    box-shadow: none !important;
                    background: transparent !important;
                }
                
                /* Override any global border styles for signature section */
                .agreement-signature-section {
                    border: none !important;
                    border-width: 0px !important;
                    border-style: none !important;
                    outline: none !important;
                    box-shadow: none !important;
                }
                
                p { 
                    margin: 12px 0; 
                    font-size: 14px; 
                    line-height: 1.5;
                    color: #333;
                }
                ul, ol { 
                    margin: 14px 0; 
                    padding-left: 0; 
                    margin-left: 20px;
                    font-size: 14px;
                    line-height: 1.5;
                    list-style-type: disc;
                    list-style-position: outside;
                }
                ol {
                    list-style-type: decimal;
                }
                li { 
                    margin-bottom: 8px; 
                    padding-left: 6px;
                    margin-left: 0;
                    color: #333;
                    line-height: 1.5;
                    display: list-item;
                    text-indent: 0;
                }
                
                /* Proposal Header Styles - Ultra-simplified for DomPDF compatibility */
                .proposal-header {
                    width: 100%;
                    padding: 10px;
                    border: 2px solid ' .
            ($colors['primary'] ?? '#000000') .
            ';
                    background: #f9f9f9;
                    margin-bottom: 15px;
                    clear: both;
                    overflow: hidden;
                }
                
                .proposal-header-logo {
                    float: left;
                    width: 100px;
                    margin-right: 15px;
                }
                
                .proposal-header-logo img {
                    max-height: 40px;
                    max-width: 100px;
                    width: auto;
                }
                
                .proposal-header-logo .logo-placeholder {
                    width: 40px;
                    height: 40px;
                    background-color: ' .
            ($colors['primary'] ?? '#000000') .
            ';
                    color: white;
                    text-align: center;
                    line-height: 40px;
                    font-weight: bold;
                    font-size: 12px;
                }
                
                .proposal-header-content {
                    float: right;
                    text-align: right;
                    max-width: 300px;
                }
                
                .proposal-header-content .company-name {
                    font-size: 16px;
                    font-weight: bold;
                    color: ' .
            ($colors['primary'] ?? '#000000') .
            ';
                    margin-bottom: 5px;
                    font-family: ' .
            ($fonts['heading'] ?? 'Arial, sans-serif') .
            ';
                }
                
                .proposal-header-content .company-info {
                    font-size: 10px;
                    color: #333;
                    line-height: 1.3;
                }
                
                .proposal-header-content .company-info div {
                    margin-bottom: 2px;
                }
                
                /* Hide header on cover page only */
                .omnia-cover-page .proposal-header {
                    display: none !important;
                }
            </style>
        </head>
        <body' .
            $hasHeaderClass .
            '>';
    }

    /**
     * Generate proposal header HTML
     *
     * @param BrandingProfile $branding
     * @param array|null $headerConfig
     * @return string
     */
    private function generateProposalHeader(
        $branding,
        $headerConfig = null
    ): string {
        \Log::info('ProposalAssemblyService::generateProposalHeader - Called', [
            'header_config' => $headerConfig,
            'header_enabled' => $headerConfig['enabled'] ?? 'not_set',
            'header_is_null' => is_null($headerConfig),
            'branding_company_name' => $branding->company_name ?? 'null',
        ]);

        if (!$headerConfig || !($headerConfig['enabled'] ?? false)) {
            \Log::info(
                'ProposalAssemblyService::generateProposalHeader - Header disabled, returning empty string'
            );
            return '';
        }

        \Log::info(
            'ProposalAssemblyService::generateProposalHeader - Header enabled but using DomPDF page script instead of inline HTML'
        );
        return ''; // Headers are now handled by DomPDF page script in PDFController
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

        // Enhance data with raw numeric versions for calculations
        $enhancedData = $this->enhanceDataWithNumericVersions($data);

        // Add smart client variables
        $enhancedData = $this->addSmartClientVariables($enhancedData);

        // First pass: Replace all simple variable placeholders
        foreach ($enhancedData as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            // Convert value to string, handle arrays and objects appropriately
            if (is_array($value)) {
                $stringValue = $this->arrayToString($value);
            } elseif (is_object($value)) {
                $stringValue = method_exists($value, '__toString')
                    ? (string) $value
                    : '[Object]';
            } elseif (is_bool($value)) {
                $stringValue = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $stringValue = '';
            } else {
                $stringValue = (string) $value;
            }
            $content = str_replace($placeholder, $stringValue, $content);
        }

        // Second pass: Process mathematical expressions
        $content = $this->processMathematicalExpressions(
            $content,
            $enhancedData
        );

        return $content;
    }

    /**
     * Add smart client variables to the data array
     *
     * @param array $data
     * @return array
     */
    private function addSmartClientVariables(array $data): array
    {
        // clients_label should be the COMPANY name (who the client is engaging)
        // The text reads "you agree to engage {{clients_label}}" - so this is the service provider company
        if (!isset($data['clients_label'])) {
            if (isset($data['company_name'])) {
                $data['clients_label'] = $data['company_name'];
            } elseif (isset($data['branding_company_name'])) {
                $data['clients_label'] = $data['branding_company_name'];
            } elseif (isset($data['user_company_name'])) {
                $data['clients_label'] = $data['user_company_name'];
            } else {
                $data['clients_label'] = 'LKD Fitouts'; // fallback
            }
        }

        // If company_name is not set but we have branding info, use that
        if (
            !isset($data['company_name']) &&
            isset($data['branding_company_name'])
        ) {
            $data['company_name'] = $data['branding_company_name'];
        }

        // Also ensure we have customer/client variables properly mapped
        if (!isset($data['customer_name']) && isset($data['client_name'])) {
            $data['customer_name'] = $data['client_name'];
        }

        return $data;
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
                $stringValues[] = method_exists($item, '__toString')
                    ? (string) $item
                    : '[Object]';
            } elseif (is_bool($item)) {
                $stringValues[] = $item ? 'true' : 'false';
            } elseif ($item === null) {
                $stringValues[] = '';
            } else {
                $stringValues[] = (string) $item;
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
                            'page' => $this->estimateSectionPageNumber(
                                $tocSection,
                                $sections
                            ),
                            'type' => $tocSection['type'],
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

        $itemTypes = [
            'items_onceoff',
            'items_monthly_subscription',
            'items_yearly_subscription',
        ];

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
                    'primary' => '#000000',
                    'secondary' => '#64748b',
                    'accent' => '#059669',
                ],
                'fonts' => [
                    'heading' => 'Arial, sans-serif',
                    'body' => 'Arial, sans-serif',
                ],
                'company_info' => [],
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
     * Remove border styles from HTML content
     *
     * @param string $content
     * @return string
     */
    private function removeBorderStyles(string $content): string
    {
        // Remove border-related style attributes
        $content = preg_replace('/border\s*:\s*[^;]+;?/i', '', $content);
        $content = preg_replace('/border-width\s*:\s*[^;]+;?/i', '', $content);
        $content = preg_replace('/border-style\s*:\s*[^;]+;?/i', '', $content);
        $content = preg_replace('/border-color\s*:\s*[^;]+;?/i', '', $content);
        $content = preg_replace('/border-radius\s*:\s*[^;]+;?/i', '', $content);
        $content = preg_replace('/box-shadow\s*:\s*[^;]+;?/i', '', $content);
        $content = preg_replace('/outline\s*:\s*[^;]+;?/i', '', $content);

        // Clean up any empty style attributes or double semicolons
        $content = preg_replace('/style\s*=\s*["\']\s*["\']/i', '', $content);
        $content = preg_replace('/;+/', ';', $content);
        $content = preg_replace_callback(
            '/style\s*=\s*["\'][^"\']*;\s*["\']/i',
            function ($matches) {
                return str_replace(';;', ';', $matches[0]);
            },
            $content
        );

        return $content;
    }

    /**
     * Get default agreement and signature section (static content)
     *
     * @return string
     */
    private function getDefaultAgreementSignature(): string
    {
        return '
        <p style="margin-bottom: 20px; line-height: 1.5; border: none !important;">By signing the Approval to Proceed, you agree to engage {{company_name}} to complete the Scope of Works outlined within this Fee Proposal in accordance with our Terms of Engagement.</p>
        
        <div style="margin-bottom: 40px; border: none !important;">
            <span style="font-weight: bold; font-size: inherit;">Name *:</span>
            <span style="border-bottom: 1px dotted #666; display: inline-block; width: 400px; margin-left: 20px; height: 18px;"></span>
        </div>
        
        <div style="margin-bottom: 40px; border: none !important;">
            <span style="font-weight: bold; font-size: inherit;">Company Name *:</span>
            <span style="border-bottom: 1px dotted #666; display: inline-block; width: 300px; margin-left: 20px; height: 18px;"></span>
        </div>
        
        <div style="margin-bottom: 40px; border: none !important;">
            <span style="font-weight: bold; font-size: inherit;">ABN:</span>
            <span style="border-bottom: 1px dotted #666; display: inline-block; width: 400px; margin-left: 60px; height: 18px;"></span>
        </div>
        
        <div style="margin-bottom: 60px; border: none !important;">
            <span style="font-weight: bold; font-size: inherit;">Signature *:</span>
            <span style="border-bottom: 1px solid #333; display: inline-block; width: 350px; margin-left: 20px; height: 30px;"></span>
        </div>
        
        <div style="margin-bottom: 40px; border: none !important;">
            <span style="font-weight: bold; font-size: inherit;">Date *:</span>
            <span style="border-bottom: 1px dotted #666; display: inline-block; width: 400px; margin-left: 60px; height: 18px;"></span>
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
     * Extract headings from all sections to auto-generate TOC
     *
     * @param array $tocSection
     * @param array $proposalData
     * @return array
     */
    private function extractHeadingsFromAllSections(
        array $tocSection,
        array $proposalData
    ): array {
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
                    'page' => $this->estimateSectionPageNumber(
                        $section,
                        $allSections
                    ),
                    'section_type' => $sectionType,
                ];
            }

            // Extract H1, H2, H3 headings from content using regex
            $patterns = [
                1 => '/<h1[^>]*>(.*?)<\/h1>/i',
                2 => '/<h2[^>]*>(.*?)<\/h2>/i',
                3 => '/<h3[^>]*>(.*?)<\/h3>/i',
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
                                'page' => $this->estimateSectionPageNumber(
                                    $section,
                                    $allSections
                                ),
                                'section_type' => $sectionType,
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
    private function estimateSectionPageNumber(
        array $targetSection,
        array $allSections
    ): int {
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

    /**
     * Enhance data array with raw numeric versions of formatted values
     * This automatically creates _raw versions of currency/numeric fields for calculations
     *
     * @param array $data
     * @return array
     */
    private function enhanceDataWithNumericVersions(array $data): array
    {
        $enhancedData = $data;

        foreach ($data as $key => $value) {
            // Skip if this is already a raw version
            if (str_ends_with($key, '_raw')) {
                continue;
            }

            // Check if this looks like a formatted currency or numeric value
            if (is_string($value)) {
                $numericValue = $this->extractNumericValue($value);

                // Only create raw version if we found a meaningful numeric value
                // and it's not already exactly the same as the original
                if ($numericValue > 0 && $numericValue != $value) {
                    $enhancedData[$key . '_raw'] = $numericValue;
                }
            } elseif (is_numeric($value)) {
                // For direct numeric values, also provide a raw version for consistency
                $enhancedData[$key . '_raw'] = (float) $value;
            }

            // Special handling for mathematical expressions in template content
            // If we find expressions using this variable, make sure raw version exists
            if (
                is_string($value) &&
                (strpos($value, '$') !== false || is_numeric($value))
            ) {
                $numericValue = $this->extractNumericValue($value);
                if ($numericValue > 0) {
                    $enhancedData[$key . '_for_calculation'] = $numericValue;
                }
            }
        }

        return $enhancedData;
    }

    /**
     * Process mathematical expressions in content
     * Supports expressions like: {{variable}} * 0.1, {{var1}} + {{var2}}, etc.
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    private function processMathematicalExpressions(
        string $content,
        array $data
    ): string {
        // First, let's handle expressions with variables still in them
        $content = preg_replace_callback(
            '/\{\{[^}]+\}\}\s*[\+\-\*\/\%]\s*[\d\.\{\}a-zA-Z_\+\-\*\/\%\s]+/',
            function ($matches) use ($data) {
                $expression = $matches[0];

                try {
                    // Replace variables in the expression with their numeric values
                    foreach ($data as $key => $value) {
                        $placeholder = '{{' . $key . '}}';
                        if (strpos($expression, $placeholder) !== false) {
                            $numericValue = $this->extractNumericValue($value);
                            $expression = str_replace(
                                $placeholder,
                                $numericValue,
                                $expression
                            );
                        }
                    }

                    // Evaluate the mathematical expression safely
                    $result = $this->evaluateMathExpression($expression);

                    // Format the result
                    return $this->formatCalculatedValue($result, $data);
                } catch (\Exception $e) {
                    \Log::warning(
                        'Failed to evaluate math expression: ' .
                            $expression .
                            ' - ' .
                            $e->getMessage()
                    );
                    return $matches[0];
                }
            },
            $content
        );

        // Then handle pure numeric expressions like "2222 * 0.1"
        $content = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?)\s*([\+\-\*\/\%])\s*(\d+(?:\.\d+)?)\b/',
            function ($matches) use ($data) {
                $left = (float) $matches[1];
                $operator = $matches[2];
                $right = (float) $matches[3];

                try {
                    switch ($operator) {
                        case '+':
                            $result = $left + $right;
                            break;
                        case '-':
                            $result = $left - $right;
                            break;
                        case '*':
                            $result = $left * $right;
                            break;
                        case '/':
                            if ($right == 0) {
                                throw new \InvalidArgumentException(
                                    'Division by zero'
                                );
                            }
                            $result = $left / $right;
                            break;
                        case '%':
                            if ($right == 0) {
                                throw new \InvalidArgumentException(
                                    'Modulo by zero'
                                );
                            }
                            $result = fmod($left, $right);
                            break;
                        default:
                            return $matches[0];
                    }

                    // Format the result
                    return $this->formatCalculatedValue($result, $data);
                } catch (\Exception $e) {
                    \Log::warning(
                        'Failed to evaluate simple math expression: ' .
                            $matches[0] .
                            ' - ' .
                            $e->getMessage()
                    );
                    return $matches[0];
                }
            },
            $content
        );

        return $content;
    }

    /**
     * Extract numeric value from a variable (removes currency symbols, commas, etc.)
     *
     * @param mixed $value
     * @return float
     */
    private function extractNumericValue($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            // Remove common currency symbols and formatting
            $cleaned = preg_replace('/[\$,\s]/', '', $value);
            $cleaned = preg_replace('/[^\d\.\-]/', '', $cleaned);

            if (is_numeric($cleaned)) {
                return (float) $cleaned;
            }
        }

        return 0.0;
    }

    /**
     * Safely evaluate a mathematical expression
     * Only allows basic arithmetic operations for security
     *
     * @param string $expression
     * @return float
     * @throws \InvalidArgumentException
     */
    private function evaluateMathExpression(string $expression): float
    {
        // Remove any whitespace
        $expression = preg_replace('/\s+/', '', $expression);

        // Security check: only allow numbers, basic operators, and parentheses
        if (!preg_match('/^[\d\.\+\-\*\/\%\(\)]+$/', $expression)) {
            throw new \InvalidArgumentException(
                'Invalid characters in mathematical expression'
            );
        }

        // Additional security: prevent function calls and complex expressions
        if (preg_match('/[a-zA-Z_]/', $expression)) {
            throw new \InvalidArgumentException(
                'Variables not fully replaced in expression'
            );
        }

        // Safely evaluate using PHP's eval with strict validation
        try {
            // Use a calculation library for safer evaluation
            return $this->calculateExpression($expression);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                'Failed to evaluate expression: ' . $e->getMessage()
            );
        }
    }

    /**
     * Calculate mathematical expression using a simple parser
     * Safer alternative to eval()
     *
     * @param string $expression
     * @return float
     */
    private function calculateExpression(string $expression): float
    {
        // Handle parentheses first
        while (preg_match('/\([^()]+\)/', $expression, $matches)) {
            $subExpression = substr($matches[0], 1, -1); // Remove parentheses
            $result = $this->calculateSimpleExpression($subExpression);
            $expression = str_replace($matches[0], $result, $expression);
        }

        return $this->calculateSimpleExpression($expression);
    }

    /**
     * Calculate a simple mathematical expression without parentheses
     * Follows proper order of operations (*, /, % before +, -)
     *
     * @param string $expression
     * @return float
     */
    private function calculateSimpleExpression(string $expression): float
    {
        // Handle multiplication, division, and modulo first (left to right)
        while (
            preg_match(
                '/(-?\d+(?:\.\d+)?)\s*([*\/%])\s*(-?\d+(?:\.\d+)?)/',
                $expression,
                $matches
            )
        ) {
            $left = (float) $matches[1];
            $operator = $matches[2];
            $right = (float) $matches[3];

            switch ($operator) {
                case '*':
                    $result = $left * $right;
                    break;
                case '/':
                    if ($right == 0) {
                        throw new \InvalidArgumentException('Division by zero');
                    }
                    $result = $left / $right;
                    break;
                case '%':
                    if ($right == 0) {
                        throw new \InvalidArgumentException('Modulo by zero');
                    }
                    $result = fmod($left, $right);
                    break;
                default:
                    throw new \InvalidArgumentException(
                        'Unknown operator: ' . $operator
                    );
            }

            $expression = str_replace($matches[0], $result, $expression);
        }

        // Handle addition and subtraction (left to right)
        while (
            preg_match(
                '/(-?\d+(?:\.\d+)?)\s*([\+\-])\s*(-?\d+(?:\.\d+)?)/',
                $expression,
                $matches
            )
        ) {
            $left = (float) $matches[1];
            $operator = $matches[2];
            $right = (float) $matches[3];

            switch ($operator) {
                case '+':
                    $result = $left + $right;
                    break;
                case '-':
                    $result = $left - $right;
                    break;
                default:
                    throw new \InvalidArgumentException(
                        'Unknown operator: ' . $operator
                    );
            }

            $expression = str_replace($matches[0], $result, $expression);
        }

        // Final result should be a single number
        if (!is_numeric($expression)) {
            throw new \InvalidArgumentException(
                'Expression did not evaluate to a number: ' . $expression
            );
        }

        return (float) $expression;
    }

    /**
     * Format a calculated value, preserving currency formatting where appropriate
     *
     * @param float $value
     * @param array $originalData
     * @return string
     */
    private function formatCalculatedValue(
        float $value,
        array $originalData
    ): string {
        // Check if any of the original values contained currency formatting
        $hasCurrency = false;
        foreach ($originalData as $dataValue) {
            if (
                is_string($dataValue) &&
                (strpos($dataValue, '$') !== false ||
                    strpos($dataValue, 'AUD') !== false)
            ) {
                $hasCurrency = true;
                break;
            }
        }

        if ($hasCurrency) {
            // Format as currency
            return '$' . number_format($value, 2);
        } else {
            // Format as regular number with appropriate decimal places
            if ($value == (int) $value) {
                return (string) (int) $value;
            } else {
                return number_format($value, 2);
            }
        }
    }

    /**
     * Ensure image is available locally for PDF generation
     * Downloads S3 images to temporary local storage for DomPDF compatibility
     *
     * @param File $file
     * @return string|null
     */
    private function ensureLocalImageForPDF($file)
    {
        try {
            if (empty($file->file_url)) {
                return null;
            }

            // Create temporary directory for PDF images
            $tempDir = storage_path('app/temp/pdf-images');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Generate local filename based on file ID and extension
            $extension = $file->file_extension ?? 'png';
            $localFilename = 'logo_' . $file->id . '.' . $extension;
            $localPath = $tempDir . '/' . $localFilename;

            // Check if we already have this file locally (and it's recent)
            if (
                file_exists($localPath) &&
                time() - filemtime($localPath) < 3600
            ) {
                // File exists and is less than 1 hour old, use it
                // For DomPDF, we need an absolute file path, not a web URL
                return $localPath;
            }

            // Download the image from S3
            \Log::info(
                'ProposalAssemblyService::ensureLocalImageForPDF - Downloading image from S3',
                [
                    'file_id' => $file->id,
                    'source_url' => $file->file_url,
                    'local_path' => $localPath,
                ]
            );

            $imageData = file_get_contents($file->file_url);
            if ($imageData === false) {
                \Log::warning(
                    'ProposalAssemblyService::ensureLocalImageForPDF - Failed to download image',
                    [
                        'file_id' => $file->id,
                        'source_url' => $file->file_url,
                    ]
                );
                return $file->file_url; // Fallback to original URL
            }

            // Save locally
            if (file_put_contents($localPath, $imageData) === false) {
                \Log::warning(
                    'ProposalAssemblyService::ensureLocalImageForPDF - Failed to save image locally',
                    [
                        'file_id' => $file->id,
                        'local_path' => $localPath,
                    ]
                );
                return $file->file_url; // Fallback to original URL
            }

            // Create symlink in public storage if needed
            $publicTempDir = storage_path('app/public/temp/pdf-images');
            if (!file_exists($publicTempDir)) {
                mkdir($publicTempDir, 0755, true);
            }

            $publicPath = $publicTempDir . '/' . $localFilename;
            if (!file_exists($publicPath)) {
                copy($localPath, $publicPath);
            }

            \Log::info(
                'ProposalAssemblyService::ensureLocalImageForPDF - Image downloaded successfully',
                [
                    'file_id' => $file->id,
                    'local_path' => $localPath,
                ]
            );

            // For DomPDF, we need an absolute file path, not a web URL
            return $localPath;
        } catch (\Exception $e) {
            \Log::error(
                'ProposalAssemblyService::ensureLocalImageForPDF - Exception occurred',
                [
                    'file_id' => $file->id ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return original URL as fallback
            return $file->file_url ?? null;
        }
    }
}
