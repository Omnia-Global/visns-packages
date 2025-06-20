<?php

namespace Visnsstudio\VisnsPackages\Database\Seeders;

use Illuminate\Database\Seeder;
use Visnsstudio\VisnsPackages\Models\ProposalTemplate;
use Visnsstudio\VisnsPackages\Models\ProposalTemplateSection;

class DefaultProposalTemplateSeeder extends Seeder
{
    /**
     * Seed the default proposal template matching the existing template format
     *
     * @return void
     */
    public function run()
    {
        // Check if default template already exists
        $existingTemplate = ProposalTemplate::where('name', 'Default Business Proposal')->first();
        if ($existingTemplate) {
            $this->command->info('Default proposal template already exists, skipping...');
            return;
        }

        // Create the default template
        $template = ProposalTemplate::create([
            'name' => 'Standard Business Proposal',
            'description' => 'Professional business proposal template with OMNIA Global Group branding, including cover page, executive summary, pricing, and comprehensive terms & conditions.',
            'is_default' => true,
            'variables' => [
                '{{document_title}}' => 'Document Title',
                '{{customer_name}}' => 'Customer Name',
                '{{company_name}}' => 'Company Name',
            ],
            'styling' => [
                'page_margins' => '40px',
                'font_family' => 'Arial, sans-serif',
                'header_color' => '#2563eb',
                'accent_color' => '#3cbf7d',
                'cover_background' => 'linear-gradient(135deg, #1e293b 0%, #0f4c3a 100%)',
            ]
        ]);

        // Create sections with OMNIA Global Group branding and comprehensive content
        $sections = [
            [
                'section_type' => 'cover_page',
                'title' => 'Proposal Cover',
                'content' => '<div class="cover-page" style="height: 100vh; background: linear-gradient(135deg, #1e293b 0%, #0f4c3a 100%); color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 60px;">
                    <div class="header" style="text-align: center;">
                        <div class="logo" style="margin-bottom: 40px;">
                            <svg width="200" height="50" viewBox="0 0 400 100" style="fill: white;">
                                <circle cx="50" cy="50" r="30" stroke="white" stroke-width="4" fill="none"/>
                                <circle cx="50" cy="50" r="20" fill="#3cbf7d"/>
                                <text x="100" y="35" font-family="Arial, sans-serif" font-size="24" font-weight="bold" fill="white">Omnia</text>
                                <text x="100" y="65" font-family="Arial, sans-serif" font-size="24" font-weight="normal" fill="#3cbf7d">Global</text>
                            </svg>
                        </div>
                    </div>
                    <div class="title-section" style="text-align: center; flex-grow: 1; display: flex; align-items: center; justify-content: center;">
                        <h1 style="font-size: 48px; margin: 0; color: #3cbf7d; text-shadow: 0 2px 4px rgba(0,0,0,0.3);">{{document_title}}</h1>
                    </div>
                    <div class="footer" style="text-align: center; font-size: 14px; color: rgba(255,255,255,0.8);">
                        <p style="margin: 0;">OMNIA GLOBAL GROUP PTY LTD &nbsp;&nbsp;|&nbsp;&nbsp; ACN: 674 383 987</p>
                    </div>
                </div>',
                'sort_order' => 1,
                'is_dynamic' => true,
                'variables' => ['document_title'],
                'styling' => ['page_break_after' => true]
            ],
            [
                'section_type' => 'toc',
                'title' => 'Table of Contents',
                'content' => 'No content',
                'sort_order' => 2,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'content',
                'title' => 'Executive Summary',
                'content' => '<div class="executive-summary">
                    <p>We appreciate the opportunity to present our proposal for {{customer_name}}. Our team has carefully analyzed your requirements and developed a comprehensive solution that addresses your specific needs.</p>
                    
                    <p>This proposal outlines our recommended approach, detailed pricing, and the value we can deliver to your organization. We are confident that our solution will meet your expectations and provide excellent return on investment.</p>
                </div>',
                'sort_order' => 3,
                'is_dynamic' => true,
                'variables' => ['customer_name'],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'quote_items',
                'title' => 'Proposed Solution & Pricing',
                'content' => '<!-- Dynamic pricing table will be generated -->',
                'sort_order' => 4,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'terms_conditions',
                'title' => 'Terms and Conditions',
                'content' => $this->getTermsAndConditionsContent(),
                'sort_order' => 5,
                'is_dynamic' => false,
                'variables' => [],
                'styling' => ['page_break_before' => true]
            ]
        ];

        // Create the sections
        foreach ($sections as $sectionData) {
            ProposalTemplateSection::create(array_merge([
                'template_id' => $template->id,
            ], $sectionData));
        }

        $this->command->info('Default proposal template created successfully with ' . count($sections) . ' sections.');
        $this->command->info('Template: ' . $template->name . ' (ID: ' . $template->id . ')');
    }

    /**
     * Get the comprehensive terms and conditions content
     *
     * @return string
     */
    private function getTermsAndConditionsContent(): string
    {
        return '<div class="terms-conditions" style="font-size: 11px; line-height: 1.4;">
            <p>This agreement is for the provision of items/services captured in the "Summary" Table and include the provision of technical services/consulting in relation to technology. Any monthly and or project work amount listed in the summary is due prior to any commencement of work and monthly in advance for the period listed above labelled Term. This monthly amount may be altered by Omnia Global Group Pty Ltd should the work being requested is outside of scope, requires reasonable additional time and to be agreed by client in writing. All costs are ex GST. Should hardware be required/requested and approved by the Client, a cost will be provided to the client for acceptance and a separate invoice will be levied.</p>

            <h4>Definitions</h4>
            <p><strong>"Confidential Information"</strong> means all information (in whatever format) designated as such by the Customer or Omnia Global Group Pty Ltd, together with such information which relates to the business affairs, customers, products, developments, trade secrets, know-how and personnel of either party and which may reasonably be regarded as the confidential information of the disclosing party and expressly includes the SERVICE AGREEMENT and any Service agreements.</p>
            
            <p><strong>"Consulting Services"</strong> mean human resources provided by Omnia Global Group Pty Ltd to give domain-specific advice or aid by performing work as defined in this SERVICE AGREEMENT.</p>
            
            <p><strong>"Documentation"</strong> means the user and other technical manuals provided to the Customer with the Services.</p>
            
            <p><strong>"Fees"</strong> means fees for the Services performed by Omnia Global Group Pty Ltd has agreed by the parties in the Service agreement and excludes out of pocket expenses.</p>
            
            <p><strong>"Intellectual Property Rights"</strong> means all intellectual property or all intellectual property rights, registered or unregistered including but not limited to copyright (including software), trademarks, service marks, trade secrets, patents, patent applications, designs, know-how, inventions moral rights other proprietary rights and any application or right to apply for registration of any rights referred to herein.</p>
            
            <p><strong>"Omnia Global Group Pty Ltd"</strong> means Omnia Global Group Pty Ltd, ABN: 65 635 109 787 of 24 Hasler Road, Osborne Park, 6017, WA</p>
            
            <p><strong>"Services"</strong> means services, provided to Customer pursuant to a Service agreement executed by the parties and could include all or part of Colocation Services, Maintenance Services, and Consulting Services.</p>
            
            <p><strong>"Term"</strong> means the term of the SERVICE AGREEMENT that extends from the Effective date as specified in the Service agreement and shall remain in effect for a period of 7 years or as specified in a Service agreement.</p>

            <h4>Purchasing Products and Services</h4>
            <p>If the parties have executed a Service agreement, then Omnia Global Group Pty Ltd will perform the Services in accordance with the Service agreement. The Customer shall in a timely manner and at its own expense actively co-operate with Omnia Global Group Pty Ltd and provide or make available to Omnia Global Group Pty Ltd all relevant resources, including, without limitation, all relevant information, documentation, and staff reasonably required by Omnia Global Group Pty Ltd to enable Omnia Global Group Pty Ltd to perform its obligation under the Service agreement.</p>
            
            <p>If either party proposes in writing a change to the scope or timing of the Services, the proposing party shall submit a copy of the proposed variations to the other party. The other party will be reasonable and in good faith consider and discuss with the proposing party the proposed change and a revised estimate for the costs for such change.</p>

            <h4>Payment Terms</h4>
            <p>Customer shall pay the Fees and related charges set forth in a Service agreement, and for any other amounts coming due hereafter, monthly in advance. Customer will reimburse Omnia Global Group Pty Ltd for all reasonable out of pocket expenses (including travel and accommodation expenses) incurred by Omnia Global Group Pty Ltd in providing the Services within 30 days from the date of Omnia Global Group Pty Ltd invoice.</p>
            
            <p>The Fees are exclusive of all applicable Taxes and Customer will pay any applicable Tax in addition to the Fees.</p>

            <h4>Services</h4>
            <p>Omnia Global Group Pty Ltd and its Suppliers do not warrant or represent the performance, accuracy, reliability or continued availability of the Services and the Network or that the Services and the Network will operate free from faults, errors or interruptions.</p>
            
            <p>Omnia Global Group Pty Ltd will use reasonable efforts to rectify identified Faults within a reasonable period.</p>

            <h4>Warranties</h4>
            <p>Omnia Global Group Pty Ltd warrants that it has the right to enter this SERVICE AGREEMENT and any related terms in Service agreement. Customer warrants that it has the right to enter this SERVICE AGREEMENT and any related Service agreement.</p>
            
            <p>Omnia Global Group Pty Ltd warrants that any Services provided to Customer under a Service agreement will be performed with due care in a professional and workman like manner and will conform in all material aspects to the applicable contract.</p>

            <h4>Limitation of Liability</h4>
            <p>Each party\'s total cumulative liability, whether in contract or tort, negligence or otherwise, in connection with any Service provided under a Service agreement, will not exceed one (1) times the amount of fees paid to Omnia Global Group Pty Ltd under such Service agreement per month, regardless of the total claimed liability.</p>
            
            <p>In no event will either party be liable for any consequential, indirect, exemplary, special, or incidental damages, or any lost data, lost profits, lost revenue, loss of anticipated saving, loss of production, business interruption, or lost opportunity, arising from or relating to the SERVICE AGREEMENT or any Service agreement, regardless of whether the loss was within the contemplation of the parties at the time of entering into the Service agreement or not.</p>

            <h4>Confidentiality</h4>
            <p>Except as expressly permitted or required by this SERVICE AGREEMENT, Customer and Omnia Global Group Pty Ltd must not use any of the other\'s Confidential Information for any purpose other than performance of its obligations or exercise of its rights under this SERVICE AGREEMENT.</p>
            
            <p>Customer and Omnia Global Group Pty Ltd must establish and maintain effective security measures to prevent any unauthorised use or disclosure of, or unauthorised access, loss or damage to, any of the other\'s Confidential Information under its possession or control.</p>

            <h4>Intellectual Property Rights</h4>
            <p>The parties agree that all rights or title to or interest in all Intellectual Property which is created prior to or independent of the SERVICE AGREEMENT or a Service agreement shall remain the sole and exclusive property of Omnia Global Group Pty Ltd or the Customer unless expressly provided in the Service agreement. Any Intellectual Property developed during the performance of Services, and all worldwide Intellectual Property Rights therein are the exclusive property of Omnia Global Group Pty Ltd and its Suppliers.</p>

            <h4>Governing Law and Venue</h4>
            <p>The SERVICE AGREEMENT and any claims related to them will be governed by the laws of jurisdiction of Western Australia and, regarding Intellectual Property Rights or confidentiality, by Australian Commonwealth laws. Any dispute action or dispute proceeding arising from or relating to any SERVICE AGREEMENT must be brought in Perth, Western Australia.</p>

            <h4>General</h4>
            <p>This SERVICE AGREEMENT shall remain in effect for the Term unless terminated as provided in this section. Customer reserves the right to terminate this monthly rolling agreement by providing 21 calendar days written notice by email.</p>
            
            <p>Neither party shall be liable for any delays in performance of any of the obligations hereunder due to causes beyond its reasonable control including, without limitation, fire, strike, war, acts of terrorism, riots, acts of any civil or military authority, acts of God, computer viruses, internet failures, judicial action.</p>
        </div>';
    }
}