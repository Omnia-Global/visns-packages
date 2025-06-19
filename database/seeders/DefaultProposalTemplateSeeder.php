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
            'name' => 'OMNIA Global Group Proposal Template',
            'description' => 'Default template matching the exact OMNIA Global Group proposal format with Change Log, Overview, Acceptance, Pricing, Payment Terms, Agreement Signature, and Terms & Conditions.',
            'is_default' => true,
            'variables' => [
                '{{company_logo}}' => 'Company Logo URL',
                '{{proposal_title}}' => 'Proposal Title',
                '{{client_logo}}' => 'Client Logo URL (if applicable)',
            ],
            'styling' => [
                'page_margins' => '40px',
                'font_family' => 'Arial, sans-serif',
                'header_color' => '#2563eb',
                'accent_color' => '#059669',
            ]
        ]);

        // Create sections matching the exact OMNIA template structure
        $sections = [
            [
                'section_type' => 'cover_page',
                'title' => '[Document Title]',
                'content' => '<div class="cover-page">
                    <div class="header">
                        <h1 style="text-align: center; font-size: 24px; margin-bottom: 10px;">[Document Title]</h1>
                        <p style="text-align: center; font-size: 14px; margin-bottom: 30px;">OMNIA GLOBAL GROUP PTY LTD ACN: 674 383 987</p>
                    </div>
                    <div class="contents-section">
                        <h2 style="font-size: 18px; margin-bottom: 20px;">Contents</h2>
                        <div class="toc-placeholder"><!-- Table of contents will be auto-generated --></div>
                    </div>
                </div>',
                'sort_order' => 1,
                'is_dynamic' => true,
                'variables' => ['document_title'],
                'styling' => ['page_break_after' => true, 'text_align' => 'left']
            ],
            [
                'section_type' => 'review_log',
                'title' => 'Change Log',
                'content' => '<div class="change-log">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                        <thead>
                            <tr style="background-color: #f5f5f5;">
                                <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Version</th>
                                <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 10px;">1.0</td>
                                <td style="border: 1px solid #ddd; padding: 10px;">Initial Document</td>
                            </tr>
                        </tbody>
                    </table>
                </div>',
                'sort_order' => 2,
                'is_dynamic' => true,
                'variables' => ['current_date'],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'overview',
                'title' => 'Overview',
                'content' => '<div class="overview-section">
                    <h1>[Heading 1]</h1>
                    <h2>[Heading 2]</h2>
                    <p>[Paragraph text]</p>
                </div>',
                'sort_order' => 3,
                'is_dynamic' => true,
                'variables' => ['customer_name'],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'acceptance',
                'title' => 'Acceptance',
                'content' => '<div class="acceptance-section">
                    <!-- Acceptance content will be populated dynamically -->
                </div>',
                'sort_order' => 4,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'quote_items',
                'title' => 'Pricing',
                'content' => '<div class="pricing-section">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <thead>
                            <tr style="background-color: #f5f5f5;">
                                <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
                                <th style="border: 1px solid #ddd; padding: 10px; text-align: right;">Price</th>
                                <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Qty</th>
                                <th style="border: 1px solid #ddd; padding: 10px; text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 10px;">Development</td>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: right;"></td>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: center;"></td>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: right;"></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="4" style="border: 1px solid #ddd; padding: 10px; text-align: right;"><strong>Sub Total:</strong></td></tr>
                            <tr><td colspan="4" style="border: 1px solid #ddd; padding: 10px; text-align: right;"><strong>Tax:</strong></td></tr>
                            <tr><td colspan="4" style="border: 1px solid #ddd; padding: 10px; text-align: right;"><strong>Total:</strong></td></tr>
                        </tfoot>
                    </table>
                </div>',
                'sort_order' => 5,
                'is_dynamic' => true,
                'variables' => ['items_onceoff', 'items_monthly_subscription', 'items_yearly_subscription', 'total_amount'],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'payment_terms',
                'title' => 'Payment Terms',
                'content' => '<div class="payment-terms">
                    <p>50% of the total cost is due upon acceptance of the quote.</p>
                    <p>25% is payable upon reaching 50% project completion.</p>
                    <p>The remaining 25% is due upon final project completion.</p>
                    <p>Monthly subscription payments will commence upon the deployment of the web application, even if other components, such as the mobile app, are completed at a later stage.</p>
                    <p>Monthly finance amounts are subject to change and funding criteria, funding is provided by a 3rd party company.</p>
                </div>',
                'sort_order' => 6,
                'is_dynamic' => true,
                'variables' => ['due_date'],
                'styling' => ['page_break_after' => false]
            ],
            [
                'section_type' => 'agreement_signature',
                'title' => 'Agreement Signature',
                'content' => '<div class="agreement-signature">
                    <p>We are excited about the opportunity to work with you on this project and deliver a solution that meets your needs and exceeds your expectations. To proceed, please review the details of the proposal and provide your acceptance by signing below.</p>
                    <p>By signing this document, you agree to the scope outlined in this proposal and authorize us to commence work on the project as described.</p>
                    <div class="signature-block" style="margin-top: 40px;">
                        <p><strong>Client Name:</strong> ____________________________________</p>
                        <br>
                        <p><strong>Signature:</strong> ______________________________________</p>
                        <br>
                        <p><strong>Date:</strong> ________________________________________</p>
                    </div>
                    <p style="margin-top: 30px;">We look forward to a successful collaboration.</p>
                </div>',
                'sort_order' => 7,
                'is_dynamic' => false,
                'variables' => ['company_name'],
                'styling' => ['page_break_before' => false]
            ],
            [
                'section_type' => 'terms_conditions',
                'title' => 'Terms and Conditions',
                'content' => '<div class="terms-conditions" style="font-size: 12px; line-height: 1.4;">
                    <p>This agreement is for the provision of items/services captured in the "Summary" Table and include the provision of technical services/consulting in relation to technology. Any monthly and or project work amount listed in the summary is due prior to any commencement of work and monthly in advance for the period listed above labelled Term. This monthly amount may be altered by Omnia Global Group Pty Ltd should the work being requested is outside of scope, requires reasonable additional time and to be agreed by client in writing. All costs are ex GST. Should hardware be required/requested and approved by the Client, a cost will be provided to the client for acceptance and a separate invoice will be levied.</p>
                    
                    <p><strong>"Confidential Information"</strong> means all information (in whatever format) designated as such by the Customer or Omnia Global Group Pty Ltd, together with such information which relates to the business affairs, customers, products, developments, trade secrets, know-how and personnel of either party and which may reasonably be regarded as the confidential information of the disclosing party and expressly includes the SERVICE AGREEMENT and any Service agreements.</p>
                    
                    <p><strong>"Consulting Services"</strong> mean human resources provided by Omnia Global Group Pty Ltd to give domain-specific advice or aid by performing work as defined in this SERVICE AGREEMENT.</p>
                    
                    <p><strong>"Fees"</strong> means fees for the Services performed by Omnia Global Group Pty Ltd has agreed by the parties in the Service agreement and excludes out of pocket expenses.</p>
                    
                    <p><strong>"Intellectual Property Rights"</strong> means all intellectual property or all intellectual property rights, registered or unregistered including but not limited to copyright (including software), trademarks, service marks, trade secrets, patents, patent applications, designs, know-how, inventions moral rights other proprietary rights and any application or right to apply for registration of any rights referred to herein.</p>
                    
                    <p><strong>"Omnia Global Group Pty Ltd"</strong> means Omnia Global Group Pty Ltd, ABN: 65 635 109 787 of 24 Hasler Road, Osborne Park, 6017, WA</p>
                    
                    <p><strong>"Services"</strong> means services, provided to Customer pursuant to a Service agreement executed by the parties and could include all or part of Colocation Services, Maintenance Services, and Consulting Services.</p>
                    
                    <p><strong>"Term"</strong> means the term of the SERVICE AGREEMENT that extends from the Effective date as specified in the Service agreement and shall remain in effect for a period of 7 years or as specified in a Service agreement.</p>
                    
                    <h3>Purchasing products and services</h3>
                    <p>If the parties have executed a Service agreement, then Omnia Global Group Pty Ltd will perform the Services in accordance with the Service agreement. The Customer shall in a timely manner and at its own expense actively co-operate with Omnia Global Group Pty Ltd and provide or make available to Omnia Global Group Pty Ltd all relevant resources, including, without limitation, all relevant information, documentation, and staff reasonably required by Omnia Global Group Pty Ltd to enable Omnia Global Group Pty Ltd to perform its obligation under the Service agreement.</p>
                    
                    <h3>Limitation of Liability</h3>
                    <p>Each party"s total cumulative liability, whether in contract or tort, negligence or otherwise, (a) in connection with any Service provided under a Service agreement, will not exceed one (1) times the amount of fees paid to Omnia Global Group Pty Ltd under such Service agreement per month, regardless of the total claimed liability.</p>
                    
                    <h3>Governing Law and Venue</h3>
                    <p>The SERVICE AGREEMENT and any claims related to them will be governed by the laws of jurisdiction of Western Australia and, regarding Intellectual Property Rights or confidentiality, by Australian Commonwealth laws. Any dispute action or dispute proceeding arising from or relating to any SERVICE AGREEMENT must be brought in Perth, Western Australia.</p>
                </div>',
                'sort_order' => 8,
                'is_dynamic' => false, // Static content - rarely changes
                'variables' => ['company_name'],
                'styling' => ['page_break_before' => true]
            ],
            [
                'section_type' => 'review_log',
                'title' => 'Review Log',
                'content' => '<!-- Dynamic review log populated from proposal data -->',
                'sort_order' => 4,
                'is_dynamic' => true,
                'variables' => ['current_date', 'project_manager'],
                'styling' => []
            ],
            [
                'section_type' => 'overview',
                'title' => 'Overview',
                'content' => '<div class="overview-section">
                    <h1>Executive Summary</h1>
                    <p>This proposal outlines our comprehensive solution for {{customer_name}}. We have carefully analyzed your requirements and designed a tailored approach that addresses your specific business needs.</p>
                    
                    <h2>Project Overview</h2>
                    <p>Our proposed solution encompasses the following key areas:</p>
                    <ul>
                        <li>Strategic planning and implementation</li>
                        <li>Technical solution delivery</li>
                        <li>Ongoing support and maintenance</li>
                        <li>Quality assurance and testing</li>
                    </ul>
                    
                    <h3>Key Benefits</h3>
                    <ul>
                        <li>Improved operational efficiency</li>
                        <li>Cost-effective solution delivery</li>
                        <li>Scalable architecture for future growth</li>
                        <li>Comprehensive support and documentation</li>
                        <li>Risk mitigation and quality assurance</li>
                    </ul>
                    
                    <h2>Implementation Approach</h2>
                    <p>Our implementation methodology follows industry best practices and includes:</p>
                    <ul>
                        <li>Detailed project planning and timeline development</li>
                        <li>Regular milestone reviews and progress reporting</li>
                        <li>Quality assurance and testing protocols</li>
                        <li>Knowledge transfer and training programs</li>
                        <li>Post-implementation support and optimization</li>
                    </ul>
                    
                    <h3>Timeline & Milestones</h3>
                    <p>The proposed implementation timeline allows for thorough planning, execution, and testing to ensure successful delivery of all project components within the agreed timeframe.</p>
                    
                    <h2>Why Choose {{company_name}}</h2>
                    <h3>Experience & Expertise</h3>
                    <p>Our team brings extensive experience in delivering similar solutions, ensuring you receive the highest quality service and results.</p>
                    
                    <h3>Proven Methodology</h3>
                    <p>We follow established methodologies and best practices that have been refined through successful project deliveries.</p>
                </div>',
                'sort_order' => 5,
                'is_dynamic' => true, // Dynamic content with H1, H2, H3 headers
                'variables' => ['customer_name', 'company_name'],
                'styling' => ['page_break_before' => true]
            ],
            [
                'section_type' => 'quote_items',
                'title' => 'Proposed Solution & Pricing',
                'content' => '<!-- Auto-generated pricing following original quote structure -->',
                'sort_order' => 6,
                'is_dynamic' => true,
                'variables' => ['items_onceoff', 'items_monthly_subscription', 'items_yearly_subscription', 'total_amount'],
                'styling' => ['page_break_before' => true]
            ],
            [
                'section_type' => 'payment_terms',
                'title' => 'Payment Terms',
                'content' => '<div class="payment-terms">
                    <h3>Payment Schedule</h3>
                    <p>Payment is due within 30 days of invoice date unless otherwise specified in the agreement.</p>
                    
                    <h3>Payment Methods</h3>
                    <p>We accept payment via:</p>
                    <ul>
                        <li>Electronic Funds Transfer (EFT) - preferred method</li>
                        <li>Credit card (processing fees may apply)</li>
                        <li>Company cheque</li>
                        <li>Direct debit (for ongoing services)</li>
                    </ul>
                    
                    <h3>Late Payment</h3>
                    <p>A 1.5% monthly service charge may be applied to accounts that remain outstanding beyond the payment terms. We reserve the right to suspend services for accounts more than 60 days overdue.</p>
                    
                    <h3>Proposal Validity</h3>
                    <p>This proposal expires on {{due_date}}. Prices are subject to change after this date due to market conditions and resource availability.</p>
                    
                    <h3>Deposits & Milestones</h3>
                    <p>A deposit of 50% may be required before commencement of work, with progress payments aligned to project milestones. The final balance is due upon completion and acceptance of deliverables.</p>
                    
                    <h3>Currency & GST</h3>
                    <p>All prices are quoted in Australian Dollars (AUD) and include GST where applicable. International clients may be subject to different tax arrangements.</p>
                </div>',
                'sort_order' => 7,
                'is_dynamic' => true, // Dynamic payment terms
                'variables' => ['due_date'],
                'styling' => []
            ],
            [
                'section_type' => 'agreement_signature',
                'title' => 'Agreement & Signatures',
                'content' => '<div class="agreement-section">
                    <h3>Client Acceptance</h3>
                    <p>By signing below, the client accepts this proposal and agrees to the terms and conditions outlined herein. This signature constitutes a binding agreement between the parties for the services described in this proposal.</p>
                    
                    <div class="signature-section" style="margin-top: 60px;">
                        <div class="client-signature" style="margin-bottom: 60px;">
                            <h4>Client Acceptance:</h4>
                            <div style="margin-top: 40px;">
                                <div style="border-bottom: 2px solid #000; width: 350px; display: inline-block; margin-right: 100px;"></div>
                                <div style="border-bottom: 2px solid #000; width: 150px; display: inline-block;"></div>
                            </div>
                            <div style="margin-top: 10px;">
                                <span style="margin-right: 150px; font-weight: bold;">Authorized Signature</span>
                                <span style="margin-left: 100px; font-weight: bold;">Date</span>
                            </div>
                            <div style="margin-top: 30px;">
                                <div style="border-bottom: 1px solid #666; width: 350px; display: inline-block; margin-right: 100px;"></div>
                                <div style="border-bottom: 1px solid #666; width: 150px; display: inline-block;"></div>
                            </div>
                            <div style="margin-top: 10px;">
                                <span style="margin-right: 180px;">Print Name</span>
                                <span style="margin-left: 120px;">Title</span>
                            </div>
                        </div>
                        
                        <div class="company-signature">
                            <h4>{{company_name}} Representative:</h4>
                            <div style="margin-top: 40px;">
                                <div style="border-bottom: 2px solid #000; width: 350px; display: inline-block; margin-right: 100px;"></div>
                                <div style="border-bottom: 2px solid #000; width: 150px; display: inline-block;"></div>
                            </div>
                            <div style="margin-top: 10px;">
                                <span style="margin-right: 150px; font-weight: bold;">Authorized Signature</span>
                                <span style="margin-left: 100px; font-weight: bold;">Date</span>
                            </div>
                            <div style="margin-top: 30px;">
                                <div style="border-bottom: 1px solid #666; width: 350px; display: inline-block; margin-right: 100px;"></div>
                                <div style="border-bottom: 1px solid #666; width: 150px; display: inline-block;"></div>
                            </div>
                            <div style="margin-top: 10px;">
                                <span style="margin-right: 180px;">Print Name</span>
                                <span style="margin-left: 120px;">Title</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="agreement-footer" style="margin-top: 60px; padding: 20px; background-color: #f8f9fa; border-left: 4px solid #2563eb;">
                        <p style="font-style: italic; margin: 0; font-size: 14px;">This agreement becomes effective upon signature by both parties and supersedes all previous negotiations, representations, or agreements relating to the subject matter herein. Any modifications must be made in writing and signed by both parties.</p>
                    </div>
                </div>',
                'sort_order' => 8,
                'is_dynamic' => false, // Static agreement signature section
                'variables' => ['company_name'],
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
}