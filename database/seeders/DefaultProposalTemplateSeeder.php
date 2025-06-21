<?php

namespace Database\Seeders;

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
        $existingTemplate = ProposalTemplate::where(
            'name',
            'Default Business Proposal'
        )->first();
        if ($existingTemplate) {
            $this->command->info(
                'Default proposal template already exists, skipping...'
            );
            return;
        }

        // Create the default template
        $template = ProposalTemplate::create([
            'name' => 'Standard Business Proposal',
            'description' =>
                'Professional business proposal template with OMNIA Global Group branding, including cover page, executive summary, pricing, and comprehensive terms & conditions.',
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
                'cover_background' =>
                    'linear-gradient(135deg, #1e293b 0%, #0f4c3a 100%)',
            ],
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
                'styling' => ['page_break_after' => true],
            ],
            [
                'section_type' => 'toc',
                'title' => 'Table of Contents',
                'content' => 'No content',
                'sort_order' => 2,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['page_break_after' => false],
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
                'styling' => ['page_break_after' => false],
            ],
            [
                'section_type' => 'quote_items',
                'title' => 'Proposed Solution & Pricing',
                'content' => '<!-- Dynamic pricing table will be generated -->',
                'sort_order' => 4,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['page_break_after' => false],
            ],
            [
                'section_type' => 'terms_conditions',
                'title' => 'Terms and Conditions',
                'content' => $this->getTermsAndConditionsContent(),
                'sort_order' => 5,
                'is_dynamic' => false,
                'variables' => [],
                'styling' => ['page_break_before' => true],
            ],
        ];

        // Create the sections
        foreach ($sections as $sectionData) {
            ProposalTemplateSection::create(
                array_merge(
                    [
                        'template_id' => $template->id,
                    ],
                    $sectionData
                )
            );
        }

        $this->command->info(
            'Default proposal template created successfully with ' .
                count($sections) .
                ' sections.'
        );
        $this->command->info(
            'Template: ' . $template->name . ' (ID: ' . $template->id . ')'
        );
    }

    /**
     * Get the comprehensive terms and conditions content
     *
     * @return string
     */
    private function getTermsAndConditionsContent(): string
    {
        return '<p>This agreement is for the provision of items/services captured in the &lsquo;Summary&rdquo; Table and include the provision of technical services/consulting in relation to technology. Any monthly and or project work amount listed in the summary is due prior to any commencement of work and monthly in advance for the period listed above labelled Term. This monthly amount may be altered by Omnia Global Group Pty Ltd should the work being requested is outside of scope, requires reasonable additional time and to be agreed by client in writing. All costs are ex GST. Should hardware be required/requested and approved by the Client, a cost will be provided to the client for acceptance and a separate invoice will be levied.&nbsp;&nbsp;</p>
<p>&ldquo;<strong>Confidential Information</strong>&rdquo; means all information (in whatever format) designated as such by the Customer or Omnia Global Group Pty Ltd, together with such information which relates to the business affairs, customers, products, developments, trade secrets, know-how and personnel of either party and which may reasonably be regarded as the confidential information of the disclosing party and expressly includes the SERVICE AGREEMENT and any Service agreement s.&nbsp;</p>
<p>&ldquo;<strong>Consulting Services</strong>&rdquo; mean human resources provided by Omnia Global Group Pty Ltd&nbsp;to give domain-specific advice or aid by performing work as defined in this SERVICE AGREEMENT.&nbsp;</p>
<p>&ldquo;<strong>Documentation</strong>&rdquo; means the user and other technical manuals provided to the Customer with the Services.&nbsp;</p>
<p>&ldquo;<strong>Fault</strong>&rdquo; means any inconsistency in the performance of an Infrastructure Service that impacts access and use of that Infrastructure Service.&nbsp;</p>
<p>&ldquo;<strong>Fees</strong>&rdquo; means fees for the Services performed by Omnia Global Group Pty Ltd&nbsp;has agreed by the parties in the Service agreement and excludes out of pocket expenses.&nbsp;</p>
<p>&ldquo;<strong>Intellectual Property Rights</strong>&rdquo; means all intellectual property or all intellectual property rights, registered or unregistered including but not limited to copyright (including software), trademarks, service marks, trade secrets, patents, patent applications, designs, know-how, inventions moral rights other proprietary rights and any application or right to apply for registration of any rights referred to herein.&nbsp;</p>
<p><strong>&lsquo;Omnia Global Group Pty Ltd&rsquo; </strong>means Omnia Global Group Pty Ltd, ABN: 65 635 109 787 of 24 Hasler Road, Osborne Park, 6017, WA</p>
<p><strong>&ldquo;Customer End User&rdquo; </strong>means an end user who sub-lets the Services as defined in this SERVICE AGREEMENT provided by Omnia Global Group Pty Ltd, from the Customer&nbsp;&nbsp;</p>
<p>&ldquo;<strong>Services</strong>&rdquo; means services, provided to Customer pursuant to a Service agreement executed by the parties and could include all or part of Colocation Services, Maintenance Services, and Consulting Services.&nbsp;</p>
<p>&ldquo;<strong>Service Levels</strong>&rdquo; means the speed, rate, response time or other measure of performance set out in a Service agreement.&nbsp;</p>
<p>&ldquo;<strong>Supplier</strong>&rdquo; means a wholesale supplier of services, software, equipment, network or other supplier who Omnia Global Group Pty Ltd may use from time to time to supply Services to Customer.&nbsp;</p>
<p>&ldquo;<strong>Tax</strong>&rdquo; means any tax, levy, impost, deduction, charge, rate, duty or withholding which is levied or imposed by a government authority (local, State, Federal or otherwise) from time to time, including any stamp, value added, goods and services or transaction tax, duty or charge, excluding taxes on profit or capital gains.&nbsp;&nbsp;&nbsp;</p>
<p>&ldquo;<strong>Term&rdquo; </strong>means the term of the SERVICE AGREEMENT that extends from the Effective date as specified in the Service agreement and shall remain in effect for a period of 7 years or as specified in a Service agreement.&nbsp;&nbsp;</p>
<p><strong>Purchasing products and services</strong>&nbsp;</p>
<p>If the parties have executed a Service agreement, then Omnia Global Group Pty Ltd in will perform the Services in accordance with the Service agreement. The Customer shall in a timely manner and at its own expense actively co-operate with Omnia Global Group Pty Ltd and provide or make available to Omnia Global Group Pty Ltd all relevant resources, including, without limitation, all relevant information, documentation, and staff reasonably required by Omnia Global Group Pty Ltd to enable Omnia Global Group Pty Ltd to perform its obligation under the Service agreement. Omnia Global Group Pty Ltd may suspend its obligations for Service agreement s for Consulting Services only during such period that such conditions of access are not maintained, and Customer agrees to reimburse Omnia Global Group Pty Ltd for any actual costs incurred because of such suspension at its then current time and materials rates. Omnia Global Group Pty Ltd shall not be liable for failure to meet time frames or completion dates for Consulting Services only unless such failure is due solely to the negligence of Omnia Global Group Pty Ltd.&nbsp;&nbsp;&nbsp;</p>
<p>If either party proposes in writing a change to the scope or timing of the Services, the proposing party shall submit a copy of the proposed variations to the other party. The other party (or the receiving party) will be reasonable and in good faith consider and discuss with the proposing party the proposed change and a revised estimate for the costs for such change. The receiving party shall advise the proposing party within Fifteen (15) business days, or such other period as is agreed between them, of receipt of the proposed variations either:&nbsp;</p>
<p>(a) that the receiving party accepts the variation, revised timeline and associated charges; or&nbsp;&nbsp;</p>
<p>(b) that the receiving party rejects the variations, revised timeline and associated charges.&nbsp;</p>
<p>If the receiving party accepts the variations, the SERVICE AGREEMENT shall be deemed to incorporate the accepted variations, revised timelines and associated charges from the date upon which the receiving party notifies the proposing party that it accepts the variations.&nbsp; All proposed and accepted variations shall be recorded in writing by the parties and the relevant terms of this SERVICE AGREEMENT, to the extent stipulated in such varied document shall stand altered. For the avoidance of doubt, it is clarified that all unvaried terms of this SERVICE AGREEMENT and the Service agreement shall continue in full force and effect. The receiving party will not unreasonably withhold acceptance of the variations and if the receiving party rejects the proposed variations, Omnia Global Group Pty Ltd&nbsp;&nbsp;&nbsp;&nbsp; shall perform the Services in accordance with the unvaried SERVICE AGREEMENT.&nbsp;&nbsp;&nbsp;</p>
<p>&nbsp;Where required, acceptance tests will be mutually determined during each Service project. Subject to any specific provision in the Service agreement, each deliverable will be deemed accepted if no certificate of acceptance or rejection has been received by Omnia Global Group Pty Ltd&nbsp;within Fifteen (15) business days after the deliverable made available for testing or placed into use by the Customer.&nbsp;&nbsp;</p>
<p>The Customer&rsquo;s right to receive Services for the Term is conditional upon the timely payment of all Fees.&nbsp;&nbsp;</p>
<p>Customer shall pay the Fees and related charges set forth in a Service agreement, and for any other amounts coming due hereafter, monthly in advance.&nbsp;&nbsp;</p>
<p>To receive Services as provided by Omnia Global Group Pty Ltd, all Services must be properly contracted, and all Fees paid on time. Omnia Global Group Pty Ltd is not obligated to continue providing Services if Fees have not been paid.&nbsp;</p>
<p>Customer will reimburse Omnia Global Group Pty Ltd for all reasonable out of pocket expenses (including travel and accommodation expenses) incurred by Omnia Global Group Pty Ltd in providing the Services within 30 days from the date of Omnia Global Group Pty Ltd invoice.&nbsp;</p>
<p>The Fees are exclusive of all applicable Taxes and Customer will pay any applicable Tax in addition to the Fees.&nbsp;&nbsp;</p>
<p><strong>Services</strong>&nbsp;</p>
<p>Omnia Global Group Pty Ltd and its Suppliers do not warrant or represent the performance, accuracy, reliability or continued availability of the Services and the Network or that the Services and the Network will operate free from faults, errors or interruptions.&nbsp;</p>
<p>Omnia Global Group Pty Ltd and its Suppliers will, from time to time, conduct scheduled or unscheduled maintenance on the Network which may interfere with the provision of Services. Omnia Global Group Pty Ltd will use its best endeavours to provide Customer with 10 workings days&rsquo; notice of any scheduled maintenance were reasonably possible.&nbsp;</p>
<p>Omnia Global Group Pty Ltd will use reasonable efforts to rectify identified Faults within a reasonable period.&nbsp;</p>
<p>Omnia Global Group Pty Ltd is not responsible for rectifying Faults where the Fault arises in or is caused by its Suppliers or its Supplier networks outside its reasonable control, but we will request that its Suppliers rectify such Faults.&nbsp;</p>
<p>Customer is responsible for repairing Faults relating to equipment, which is not owned by Omnia Global Group Pty Ltd.&nbsp;</p>
<p>Omnia Global Group Pty Ltd will use its best endeavours to provide the Services in accordance with the relevant Service Levels as set out in the Service agreement. The Customer is required to report to Omnia Global Group Pty Ltd, any error or failure by Omnia Global Group Pty Ltd in respect of the delivery and performance of the Services as set out in the Service agreement.&nbsp;&nbsp;</p>
<p>Omnia Global Group Pty Ltd may vary and update the Service Levels and Rebates by giving to the Customer 30 day&rsquo;s written notice and this variance will not constitute a variance as contemplated in clause 3.5. &nbsp;</p>
<p>If in providing the Services, the Customer or a Customer End User must access facilities which are owned or leased by Omnia Global Group Pty Ltd, the Customer must and will ensure that a Customer End User will comply with the Datacentre Limited Policies and Procedures and with any security, work, health and safety or building entry policies or procedures notified by Omnia Global Group Pty Ltd from time to time in writing.&nbsp;</p>
<p><strong>Warranties</strong>&nbsp;</p>
<p>Omnia Global Group Pty Ltd warrants that it has the right to enter this SERVICE AGREEMENT and any related terms in Service agreement. &nbsp;</p>
<p>Customer warrants that it has the right to enter this SERVICE AGREEMENT and any related Service agreement.&nbsp;&nbsp;</p>
<p>Whilst the Customer is receiving Services in accordance with a Service agreement, Omnia Global Group Pty Ltd will ensure that the Services, when used as permitted by Omnia Global Group Pty Ltd, will operate substantially as described in the Service agreement. Omnia Global Group Pty Ltd does not warrant the Customer or Customer End User&rsquo;s use of the Services will be error-free or uninterrupted. Omnia Global Group Pty Ltd will, at no additional cost and at its sole obligation and Customer exclusive remedy for any break of this warranty, use commercially reasonable efforts to correct any reproducible error in the Service reported to Omnia Global Group Pty Ltd. This warranty shall immediately become void in the event of any modification being made to the Service without the prior written consent of Omnia Global Group Pty Ltd.&nbsp;</p>
<p>Omnia Global Group Pty Ltd warrants that any Services provided to Customer under a Service agreement will be performed with due care in a professional and workman like manner and will conform in all material aspects to the applicable contract.&nbsp;</p>
<p>For the avoidance of doubt, all commitments, indemnities and other terms and conditions offered by Omnia Global Group Pty Ltd with respect to the provision of Services are made directly to the Customer and do not extend to any third parties or Customer End Users or suppliers or partners of the Customer not party to this SERVICE AGREEMENT. To the extent permitted by law, Omnia Global Group Pty Ltd makes no warranties, express or implied or otherwise to any such third party including but not limited to implied warranties.&nbsp;&nbsp;</p>
<p><strong>Limitation of Liability</strong>&nbsp;</p>
<p>Each party&rsquo;s total cumulative liability, whether in contract or tort, negligence or otherwise, (a) in connection with any Service provided under an Service agreement, will not exceed one (1) times the amount of fees paid to Omnia Global Group Pty Ltd under such Service agreement&nbsp; per month, regardless of the total claimed liability; (b) in connection with any Services provided to a Term, will not exceed one (1) times the amount of fees paid to OMNIA GLOBAL GROUP PTY LTD in the 12-month period immediately preceding the claim.&nbsp;&nbsp;</p>
<p>In no event will either party&nbsp; be liable for any consequential, indirect, exemplary, special, or incidental damages ( including additional costs arising from delay or increased inefficiency, loss of contracts or loss of use), or any lost data, lost profits, lost revenue, loss of anticipated saving, loss of production, business interruption, or lost opportunity, arising from or relating to the SERVICE AGREEMENT&nbsp; or any Service agreement&nbsp;&nbsp; (including arising from negligence), regardless of whether the loss was within the contemplation of the parties at the time of entering into the Service agreement or not.&nbsp;&nbsp;</p>
<p>Each party acknowledges that the Fees reflect the allocation of risk between the parties and that the other party would not enter the Service agreement without these limitations on that party&rsquo;s liability. In addition, Customer disclaims all liability of any kind of Omnia Global Group Pty Ltd suppliers and related companies. These limitations shall apply even if any other remedy fails of its essential purpose.&nbsp;&nbsp;</p>
<p><strong>Indemnities</strong>&nbsp;</p>
<p>Each party agrees to indemnify the other against all losses, expenses, damages and legal costs incurred by Omnia Global Group Pty Ltd or the Customer (as applicable) arising directly from an officer, employee or contractor of the Customer or Omnia Global Group Pty Ltd, as the case may be:&nbsp;</p>
<p>acting unlawfully, deceitfully or being wilfully negligent; or&nbsp;</p>
<p>breaching this SERVICE AGREEMENT.&nbsp;</p>
<p>Customer agrees to indemnify Omnia Global Group Pty Ltd against all losses, expenses, damages and legal costs incurred by or awarded against OMNIA GLOBAL GROUP PTY LTD for , personal injury or damage to property arising from any negligent or wilful act or omission of the Customer or the Customer End User, misuse or wrongful disclosure by the Customer of Omnia Global Group Pty Ltd or any third party&rsquo;s Confidential Information, and breaches by the Customer of Omnia Global Group Pty Ltd or any third party&rsquo;s Intellectual Property Rights, and against any claim made against Omnia Global Group Pty Ltd :&nbsp;</p>
<p>by a Supplier or any third party which arises from a negligent act or omission of the Customer or any Customer End User or any breach of any instruction given Omnia Global Group Pty Ltd or this SERVICE AGREEMENT by the Customer; or&nbsp;</p>
<p>arising from any breach of law, regulatory requirement or industry code by Customer or any Customer End User.&nbsp;&nbsp;</p>
<p>The indemnity in this clause 7.2 does not apply in respect of any negligent or wilful acts or omissions on the part of a Customer End User while on any premises where Omnia Global Group Pty Ltd is providing Colocation Services.&nbsp;</p>
<p>Subject to the Customer&rsquo;s rights in statute or the common law, the Customer must not make any claim against a Supplier in connection with this SERVICE AGREEMENT or the Services which would result in Omnia Global Group Pty Ltd becoming liable to that Supplier.&nbsp;</p>
<p>Subject to clause 11.6 Omnia Global Group Pty Ltd may set-off against any payments due to Customer, any amounts due from or payable by Customer under or in relation to:&nbsp;</p>
<p>this SERVICE AGREEMENT (including any clawbacks or any over-payments made by OMNIA GLOBAL GROUP PTY LTD to Customer); or&nbsp;</p>
<p>any other agreement or arrangement between Customer and Omnia Global Group Pty Ltd&nbsp;&nbsp;</p>
<p>Any indemnity provided under this clause and relied on by a party being sued for loss or liability in connection with its obligations under this SERVICE AGREEMENT will be reduced proportionally to the to the extent that the relying party&rsquo;s negligent act or omission or failure to comply with its obligations under this SERVICE AGREEMENT caused or contributed to the claim for the loss or liability.&nbsp;</p>
<p>&nbsp;</p>
<p><strong>Confidentiality</strong>&nbsp;</p>
<p>Except as expressly permitted or required by this SERVICE AGREEMENT, Customer Omnia Global Group Pty Ltd must not use any of the other&rsquo;s Confidential Information for any purpose other than performance of its obligations or exercise of its rights under this SERVICE AGREEMENT.&nbsp;</p>
<p>Except as expressly permitted or required by this SERVICE AGREEMENT, Customer and Omnia Global Group Pty Ltd must not disclose to any other person any of the other\'s Confidential Information.&nbsp;&nbsp;</p>
<p>Customer and Omnia Global Group Pty Ltd may disclose the other&rsquo;s Confidential Information:&nbsp;</p>
<p>If required to do so by law or any regulatory authority, to the extent so required; and to its Personnel (including subcontractors) whose duties reasonably require such disclosure, on condition that the person making such disclosure:&nbsp;&nbsp;</p>
<p>Ensures that each such person to whom such disclosure is made is informed of the confidentiality of the information and the obligations of confidentiality under this SERVICE AGREEMENT; and&nbsp;</p>
<p>Ensures that each such person to whom such disclosure is made complies with those obligations as if they were bound by them.&nbsp;</p>
<p>Except in accordance with the provisions of clauses 9.2.1 or as otherwise required to perform the Services, Customer and Omnia Global Group Pty Ltd and VENDOR&nbsp;&nbsp;&nbsp; must not disclose the terms of this SERVICE AGREEMENT.&nbsp;</p>
<p>If Customer or Omnia Global Group Pty Ltd is required to disclose the other&rsquo;s Confidential Information under clause 9.2.1 it must:&nbsp;</p>
<p>Notify the other of the requirement to disclose as soon as is reasonably possible.&nbsp;</p>
<p>Take all steps necessary to allow the other to challenge or limit the requirement to disclose using any available channel or in any forum, including a court of law.&nbsp;</p>
<p>Provide the other with all assistance and co-operation reasonably requested by the other to assist it to challenge or limit the requirement to disclose; and&nbsp;&nbsp;</p>
<p>Use its best endeavours to ensure that confidential treatment will be given to the Confidential Information by any person to whom it is required to be disclosed.&nbsp;</p>
<p>If Customer or Omnia Global Group Pty Ltd becomes aware of a breach of this clause 9, including a breach of duty of its personnel or Suppliers with respect to the other\'s Confidential Information, it must:&nbsp;</p>
<p>Notify the other as soon as it becomes aware of the breach.&nbsp;</p>
<p>Promptly provide the other with any information or assistance which it may reasonably request to minimise the loss or damage it may suffer because of the breach; and&nbsp;</p>
<p>Co-operate with the other in any investigation or litigation conducted by it to protect its rights in its Confidential Information.&nbsp;&nbsp;</p>
<p>Customer and Omnia Global Group Pty Ltd must establish and maintain effective security measures to prevent any unauthorised use or disclosure of, or unauthorised access, loss or damage to, any of the other&rsquo;s Confidential Information under its possession or control.&nbsp;&nbsp;</p>
<p>Customer and Omnia Global Group Pty Ltd must always keep all materials containing the other&rsquo;s Confidential Information separate from all other materials under its possession or control.&nbsp;</p>
<p>The provisions of this clause 9 survives termination or expiry of this SERVICE AGREEMENT for any reason whatsoever.&nbsp;&nbsp;</p>
<p><strong>Term and Termination</strong>&nbsp;</p>
<p>This SERVICE AGREEMENT shall remain in effect for the Term unless terminated as provided in this section. Customer reserves the right to terminate this monthly rolling agreement by providing 21 calendar days written notice by email.&nbsp;&nbsp;</p>
<p>Upon expiration or termination of this SERVICE AGREEMENT or a license granted under this SERVICE AGREEMENT, Customer shall:&nbsp;</p>
<p>Cease using the applicable Services, Intellectual Property Rights and related Confidential Information of Omnia Global Group Pty Ltd.&nbsp;</p>
<p>Return or deliver to Omnia Global Group Pty Ltd a written certification signed by a corporate officer of Customer within thirty (30) days after termination that Customer has destroyed Omnia Global Group Pty Ltd and VENDOR&rsquo;s Documentation, related Confidential Information and all copies thereof, whether modified or merged into other materials.&nbsp;</p>
<p>Expiry or termination for any reason of this SERVICE AGREEMENT shall not have the effect of terminating all outstanding Service agreement s. The parties agree that in the event the SERVICE AGREEMENT is terminated, due to expire or has expired and there are Service agreement s outstanding, Omnia Global Group Pty Ltd will continue to provide the Services under the same terms as this SERVICE AGREEMENT until the expiry of the term of the last outstanding Service agreement.&nbsp;&nbsp;</p>
<p><strong>General</strong>&nbsp;</p>
<p><strong>Intellectual Property Rights</strong>. The parties agree that all rights or title to or interest in all Intellectual Property which is created prior to or independent of the SERVICE AGREEMENT or a Service agreement shall remain the sole and exclusive property of Omnia Global Group Pty Ltd or the Customer unless expressly provided in the Service agreement. Any Intellectual Property developed during the performance of Services (&ldquo;Property&rdquo;), and all worldwide Intellectual Property Rights therein are the exclusive property of Omnia Global Group Pty Ltd and its Suppliers. All rights in and to the Property not expressly granted to Customer in a Service agreement are reserved by OMNIA GLOBAL GROUP PTY LTD and its Suppliers. Nothing in a Service agreement will be deemed to grant, by implication, estoppel or otherwise, a license under any of Omnia Global Group Pty Ltd existing or future Intellectual Property. The Customer will not remove, alter, or obscure, any proprietary notices (including copyright notices) of OMNIA GLOBAL GROUP PTY LTD and VENDOR or its Suppliers on the Property.&nbsp;</p>
<p><strong>Compliance with Laws</strong>. Customer will comply with all applicable law including export and import control laws and regulations in its use of the Property and customer will not export or re-export the Property without all required government licenses and Customer agrees to comply with the export laws, restrictions, national security controls and regulations of all applicable foreign agencies or authorities. Customer will defend, indemnify, and hold harmless Omnia Global Group Pty Ltd from and against any violation of such laws or regulations by Customer or any of its agents, officers, directors, or employees.&nbsp;</p>
<p><strong>Force Majeure</strong>. Neither party shall be liable for any delays in performance of any of the obligations hereunder due to causes beyond its reasonable control including, without limitation, fire, strike, war, acts of terrorism, riots, acts of any civil or military authority, acts of God, computer viruses, internet failures, judicial action, unavailability or shortages of labour, materials or equipment, failure or delays in delivery of Omnia Global Group Pty Ltd and suppliers or delays in transportation.&nbsp;</p>
<p><strong>Assignment</strong>. No SERVICE AGREEMENT nor any rights, duties, or obligations set forth in any SERVICE AGREEMENT,&nbsp; may be assigned, encumbered, mortgaged, assumed, or otherwise transferred by Customer, in whole or in part, whether directly or by operation of law, including by way of sale of assets, merger or consolidation, or a transaction that results in the equity owners of Customer before the transaction owning less that a majority of the outstanding equity of Customer following the transaction (which shall be considered an assignment hereunder), without the prior written consent of Omnia Global Group Pty Ltd , and any attempt to do so without the express prior written consent (which consent shall be in its sole discretion) shall be deemed a material breach of the SERVICE AGREEMENT(s) which is incapable of being remedied and shall automatically terminate all other rights granted to Customer thereunder. Subject to the foregoing, each SERVICE AGREEMENT will be binding upon and will inure to the benefit of the parties and their respective successors and assigns.&nbsp;</p>
<p><strong>Notices</strong>. All notices, consents, and approvals under any SERVICE AGREEMENT must be delivered in writing by courier or by certified or registered mail (postage prepaid and return recipient requested) to the other party at the address set forth on the cover page of this SERVICE AGREEMENT unless otherwise directed in the relevant SERVICE AGREEMENT , and will be effective upon receipt or three (3) business days after being deposited in the mail as required above, whichever occurs sooner. Either party may change its address by giving written notice of the new address to the other party.&nbsp;</p>
<p><strong>Governing Law and Venue.</strong> The SERVICE AGREEMENT and any claims related to them will be governed by the laws of jurisdiction of Western Australia and, regarding Intellectual Property Rights or confidentiality, by Australian Commonwealth laws, such as laws that apply to contracts between residents of that state performed entirely within such state. The United Nations Convention on Contracts for the International Sale of Goods does not apply to any SERVICE AGREEMENT. Any dispute action or dispute proceeding arising from or relating to any SERVICE AGREEMENT must be brought in Perth, Western Australia. In the event of a court action or civil proceeding arising from or relating to any SERVICE AGREEMENT each party irrevocably submits to the jurisdiction and venue of the courts of Perth, Western Australia as the venue for any such action or proceeding.&nbsp;</p>
<p><strong>Waivers</strong>. All waivers must be in writing. Any waiver or failure to enforce any provision of any SERVICE AGREEMENT on one occasion will not be deemed a waiver of any other provision or of such provision on any other occasion.&nbsp;</p>
<p><strong>Entire Agreement</strong>. This SERVICE AGREEMENT and any relevant Service agreement shall jointly form the SERVICE AGREEMENT which is the complete agreement between the parties regarding subject matter of this SERVICE AGREEMENT and the Service agreement and replace any prior oral or written communications between the parties related to the SERVICE AGREEMENT.&nbsp;</p>
<p><strong>Independent Contractor</strong>. In all matters relating to any SERVICE AGREEMENT, Omnia Global Group Pty Ltd will act as an independent contractor. Neither party will represent that it has any authority to assume or create any obligation, express or implied, in conjunction with the other party.&nbsp;</p>
<p><strong>Severability</strong>. If any provision of any SERVICE AGREEMENT is unenforceable, such provision will be changed and interpreted to accomplish the objectives of such provision to the greatest extent possible under applicable law and the remaining provisions will continue in full force and effect. Without limiting the generality of the foregoing,&nbsp;&nbsp;</p>
<p><strong>Counterparts</strong>. This SERVICE AGREEMENT and any Service agreement may be executed in counterparts, each of which will be considered an original, but all of which together will constitute the same instrument.&nbsp;</p>
<p>Managed Services Agreement - Services&nbsp;</p>
<p>&ldquo;<strong>After Hours</strong>&rdquo; means from 17:00 &ndash; 0830 hours Monday to Friday and all of Saturday and Sunday, including Public Holidays.&nbsp;</p>
<p>&ldquo;<strong>Business Hours</strong>&rdquo; means Monday to Friday from 08.30 to 17:00 hours excluding public holidays.&nbsp;</p>
<p>"<strong>Client</strong>&rdquo;, <strong>&ldquo;You" </strong>or <strong>&ldquo;Your&rdquo; </strong>means a person who seeks or obtains a quote for, or who orders, Goods or Services from Us, and includes both a person whose name is on the Order or on an email attached to which is an order, a person who places an order, and a person on whose behalf an Order is placed or on whose behalf it appears and order is placed, and in any case each of their heirs, successors and assigns;&nbsp;</p>
<p>"<strong>Conditions</strong>" means these terms and conditions.&nbsp;</p>
<p><strong>"Goods" </strong>means any goods and/or services sourced by Us or provided by Us in connection with any such goods and/or services including computer hardware and Software and any goods or services provided in connection with any of those things.&nbsp;</p>
<p><strong>&ldquo;GST&rdquo; </strong>has the meaning given to it under A New Tax System (Goods and Services Tax) Act 1999 (Cth).&nbsp;</p>
<p><strong>&ldquo;Order&rdquo; </strong>means any order requested by You to Us for Goods or Services in any form.&nbsp;</p>
<p><strong>&ldquo;Quote&rdquo; </strong>means a quote provided to You by Us.&nbsp;</p>
<p><strong>&ldquo;Period&rdquo; </strong>means a particular number of half-days, days, weeks, fortnights, months, or any other period, as may be agreed between Us and the You as the period during which some Services will be provided.&nbsp;</p>
<p><strong>&ldquo;Plan&rdquo; </strong>means any arrangement between Us and You (whether alone or in conjunction with any other person) for Services (including unlimited support) and/or the provision of Goods provided by Us under an arrangement in connection with Work agreed to be done or progressed for or on behalf of You or any other person at Your request, including as set out in a Plan Schedule.&nbsp;</p>
<p><strong>&ldquo;Plan Schedule&rdquo; </strong>means the key terms applicable to Plans as set, and as may be varied by Us, from time to time in its absolute discretion without notice to You.&nbsp;</p>
<p><strong>&ldquo;Public Holidays&rdquo; </strong>means any day which is a public holiday throughout Western Australia other than a bank holiday.&nbsp;</p>
<p><strong>&ldquo;Rates&rdquo; </strong>means the hourly rates and other charges for Services (including any call-out fees and any Return/Cancellation Fees) set out in the Rates Schedule, a Plan, Plan Schedule, Quote, contract or arrangement entered into by Us and You or in these Conditions and includes any monies payable to Us on a quantum merit basis for any work it has done.&nbsp;</p>
<p><strong>&ldquo;Rate Schedule&rdquo; </strong>means the schedule of rates, charges and conditions for the services of Ours as set, and as may be varied, by Us from time to time in its absolute discretion without notice to You.&nbsp;</p>
<p><strong>&ldquo;Reasonable Assistance Limits&rdquo; </strong>has the meaning set out in clause <a href="file:///C:/Users/tpeto/Downloads/Services%20Agreement%20TaaS%20Omnia%20Global%2024.docx#_bookmark20" target="_blank" rel="noopener">17.2</a>;&nbsp;</p>
<p><strong>&ldquo;Return/Cancellation Fee&rdquo; </strong>means a fee charged pursuant to clause <a href="file:///C:/Users/tpeto/Downloads/Services%20Agreement%20TaaS%20Omnia%20Global%2024.docx#_bookmark14" target="_blank" rel="noopener">12.5 </a>as set by Us from time to time.&nbsp;</p>
<p><strong>&ldquo;Service request&rdquo; </strong>means a request for service such as adds, moves, changes and technical assistance.&nbsp;</p>
<p><strong>"Services" </strong>means the provision of any services by Us including Work, advice and recommendations.&nbsp;</p>
<p><strong>&ldquo;Software&rdquo; </strong>includes software and any installation, update, associated software and any services provided in connection with any of these things.&nbsp;</p>
<p>" <strong>Us&rdquo;, </strong>&ldquo;<strong>Our</strong>&rdquo; or &ldquo;<strong>We</strong>&rdquo; means Omnia Global Group Pty Ltd 11 610 214 492 and its heirs, successors and assigns; and&nbsp;</p>
<p><strong>&ldquo;Work&rdquo; </strong>means anything We may do, provide, customise, produce or acquire, whether or not in connection with, or for the purposes of, You or Your use or benefit, and includes testing, troubleshooting, installation and configuration of new equipment or software, consulting, scoping, planning, documenting and quoting for complex items.&nbsp;</p>
<p>In these Conditions, the Rate Schedule and every Quote, Order, Plan, contract, or other arrangement in connection with the supply of Goods or Services by Us, unless the contrary intention appears:&nbsp;</p>
<p>Words denoting the <strong>singular </strong>number only <strong>shall include the plural </strong>number and vice versa; Reference to <strong>any gender shall include every other gender</strong>.&nbsp;</p>
<p>Reference to <strong>any Act of Parliament, Statute or Regulation shall include any amendment </strong>currently in force at the relevant time and any Act of Parliament, Statute or Regulation enacted or passed in substitution, therefore.&nbsp;</p>
<p><strong>Headings </strong>and words put in <strong>bold </strong>are for convenience of reference only and <strong>do not affect the interpretation or construction </strong>of these Conditions.&nbsp;</p>
<p>All references to dollars ($) are to Australian Dollars.&nbsp;</p>
<p>A reference to time is to Australian Western Standard Time.&nbsp;</p>
<p>A reference to an <strong>individual or person includes a corporation</strong>, partnership, joint venture, association, authority, trust, state or government and vice versa.&nbsp;</p>
<p>A reference to a recital, clause, schedule, annexure or exhibit is to a recital, clause, schedule, annexure or exhibit of or to these Conditions.&nbsp;</p>
<p>A recital, schedule, annexure or description of the parties&rsquo; forms part of these Conditions.&nbsp;</p>
<p>A reference to any agreement or document is to that agreement or document (and, where applicable, any of its provisions), as amended, novated, supplemented or replaced from time to time.&nbsp;</p>
<p>Where an expression is defined, <strong>another part of speech or grammatical form of that expression has a corresponding meaning</strong>.&nbsp;</p>
<p>A reference to <strong>&ldquo;includes&rdquo; </strong>means <strong>includes without limitation</strong>; A reference to <strong>&ldquo;will&rdquo; </strong>imports a condition not a warranty; and&nbsp;</p>
<p>A reference to <strong>bankruptcy or winding up </strong>includes bankruptcy, winding up, liquidation, dissolution, becoming an insolvent under administration, being subject to administration and the occurrence of anything analogous or having a substantially similar effect to any of those conditions or matters under the law of any applicable jurisdiction and to the procedures, circumstances and events which constitute any of those conditions or matters.&nbsp;</p>
<p>Applications Of These Conditions&nbsp;</p>
<p>Unless otherwise agreed by Us in writing, these Conditions are deemed incorporated in and are applicable to (and to the extent of any inconsistency will prevail over) the terms of every Quote, Order, Plan, contract, or other arrangement in connection with the supply of Goods and/or Services by Us to You.&nbsp;</p>
<p>The invalidity or enforceability of any one or more of the provisions of this Agreement will not invalidate, or render unenforceable, the remaining provisions of this Agreement.&nbsp;</p>
<p>Commitment Term&nbsp;</p>
<p>The minimum term that You acquire the service for is outlined in Service agreement, beginning from the first of the next month after the date of signing or approving the Quote.&nbsp;</p>
<p>After the expiry of the Committed Term, an extension of the Term will automatically commence for the same period as the original Committed Term and will continue indefinitely, unless earlier terminated by you as specified in Clause 4&nbsp;</p>
<p>Representations&nbsp;</p>
<p>You acknowledge that no employee or agent of Ours has any right to make any representation, warranty or promise in relation to the supply of Goods or Services other than subject to and as may be contained in the Conditions.&nbsp;</p>
<p>Notices&nbsp;</p>
<p>Any notices given under the Conditions shall be in writing and sent by e-mail to the last notified e-mail address of Yours. Customer agrees to inform Omnia Global Group Pty Ltd should it wish to opt out of marketing emails and information by emailing <a href="mailto:support@omniaglobal.com" target="_blank" rel="noopener">support@omniaglobal.com</a><span class="MsoHyperlink">.au</span>, opt in is considered in place and agreed, should the customer not inform Omnia Global Group Pty Ltd in writing.&nbsp;&nbsp;&nbsp;</p>
<p>Governing Law&nbsp;</p>
<p>The Conditions shall be governed by and construed in accordance with the laws of Western Australia and the parties submit to the non-exclusive jurisdiction of the Courts of Western Australia.&nbsp;</p>
<p>Assignment&nbsp;</p>
<p>You may not assign Your rights and obligations under this Agreement without the prior written consent of Us.&nbsp;</p>
<p>Variation Of These Terms and Conditions&nbsp;</p>
<p>We may at any time vary these Terms and Conditions by publishing the varied Terms and Conditions on Our website. You accept that by doing this, we have provided You with sufficient notice of the variation. We are under no other obligation to notify You of any variation to these terms and conditions.&nbsp;</p>
<p>Goods and services&nbsp;</p>
<p>Quotes&nbsp;</p>
<p><strong>Term and effect: </strong>Quotes will only be valid for 14 days unless otherwise specified in the Quote. A Quote is merely an invitation to You to place an Order with Us and the acceptance of a Quote by You will not create a binding contract between You and Us.&nbsp;</p>
<p>Quote is valid for 14 days only. Expiry dates on quotes are set to be able to inform Us when the quote is still active or to be discarded. Once discarded the quote will need to be requested again.&nbsp;</p>
<p>Once a quote has been confirmed by Us, then the prices in the quote will be confirmed as the final agreed price. A quote is confirmed as \'final\' as soon as both parties agree with the final price after any last changes requested by You.&nbsp;</p>
<p>The price in the final quote may vary from the original request if there is any price or product changes requested by You. We reserve the right to alter product and prices in the quote, if the quote has not been confirmed with You.&nbsp;</p>
<p>Quotes and estimates shall be deemed to correctly interpret the original specifications and are based on the cost at the time the quote or estimate is.&nbsp;</p>
<p>If You later require any changes to the quotes, and We agree to the changes, these changes will be charged at Our prevailing rate.&nbsp;</p>
<p>Once the Quote has been confirmed and converted to an Order, the Order will be subjected to our normal Terms and Condition of Sale.&nbsp;</p>
<p>The general minimum turnaround time for Quote request to be actioned is usually 24 hours. If a quote is required urgently, please let us know so that we can respond to it accordingly.&nbsp;</p>
<p>When a special price or discount offer has been applied to this Quote, no other special promotion, discount or bonus offer will be applicable.&nbsp;</p>
<p>If products in the Quote are subjected to any price and supply fluctuations that is outside of Our control, we reserve the right to update the price and product in the Quote accordingly. If a product has undergone a price drop or a price increase, the Quote will then be adjusted accordingly. If there is a product that is no longer available, the product will then be replaced or substituted based on Your request and is subject to Your final approval.&nbsp;</p>
<p>Price on non-stocked products is subjected to Price and stock fluctuations and can only be confirmed once the Quote is turned into an Order. While We endeavour to honour every price quoted, if there is a price increase that is beyond our control, we reserve the right to increase the price as necessary.&nbsp;</p>
<p>Once a Quote has already passed the expired date, we may cancel the quote or estimate without having to notify or receive an approval from You.&nbsp;</p>
<p>ETA information is based on an estimate given by our vendors and cannot be held as the actual promised date.&nbsp;</p>
<p>Freight charges will be added to the Order unless otherwise stated. Any included delivery charges are estimates only.&nbsp;</p>
<p>We do not keep inventory and as such only order items once we receive a completed order from a client. If You would like to return an item or cancel an order, a restocking fee may apply. We will need to get approval from the distributor that the stock is returnable before being able to issue a refund as not all products can be returned.&nbsp;</p>
<p>Prices are based upon total Quote Purchase.&nbsp;</p>
<p>Unless specified, all items on quote are covered by manufacturer&rsquo;s warranty.&nbsp;</p>
<p>Covering parts and labour for hardware only on a return to depot basis.&nbsp;</p>
<p><strong>Varying or withdrawing Quotes: </strong>We may vary or withdraw a Quote at any time in Our absolute discretion and without prior notice to You. We may do so for any reason We consider fit, including, e.g., where the Goods or Services become unavailable, or the cost price of Goods or Services increases after the date of the Quote.&nbsp;</p>
<p><strong>Change in underlying costs: </strong>Without prejudice to any other rights of Ours under these Conditions, where there is any increase in the underlying costs incurred by Us in connection with the supply of Goods or Services to You, we may, in our absolute discretion, vary any of Our Rates.&nbsp;</p>
<p><strong>Pre-Paid Blocks of Service: </strong>Where You agree to buy Pre-Paid Blocks of Service during a Period, payment <strong>must be made in advance </strong>for the Pre-Paid Blocks of Service at the rate applicable pursuant to the Rates Schedule for all Services. Each such rate being less any discount agreed in writing between Us and You in respect of the Pre-Paid Blocks of Service. Services <strong>included in a Pre-Paid Block of Service rate </strong>during the Period:&nbsp;</p>
<p>are calculated in accordance with the applicable minimum time periods and&nbsp;</p>
<p><strong>increments </strong>set out in the Rates Schedule; and&nbsp;</p>
<p><strong>are only provided by Us during the applicable Period. </strong>Where Services are provided for a specified Period:&nbsp;</p>
<p>the Services remaining unused for that Period cannot be rolled over into any subsequent Period; and&nbsp;</p>
<p>We are not liable to refund, re-imburse, pay damages or otherwise compensate or indemnify You in respect of those unused Services.&nbsp;</p>
<p>Services And Plans&nbsp;</p>
<p><strong>Service and Plan Variations: </strong>Currently, we offer the Services and Plans referred to in the Rates Schedule and any Plan Schedule. We may withdraw the provision of, or vary the scope or terms of, or add to or change, the Services without notice to You, from time to time in Our absolute discretion.&nbsp;</p>
<p><strong>Copies on Request: </strong>We will provide You with a copy of the current Rates Schedule upon request. Plan Schedules are tailored for Plans and are available to Clients participating in the Plan.&nbsp;</p>
<p>Contracting&nbsp;</p>
<p>We may subcontract any or all the Services to be performed but shall retain prime responsibility for the Services under these terms.&nbsp;</p>
<p>Delivery, Title and Risk&nbsp;</p>
<p><strong>Delivery liability: </strong>We will use all reasonable endeavours to despatch Goods by the due date, but do not accept any liability for non-delivery or failure to deliver on time where this is caused by circumstances beyond the reasonable control of Ours, including, for example, due to failures in supply to Us or delays caused by third parties, such as delivery companies or manufacturers.&nbsp;</p>
<p><strong>Availability to accept delivery: </strong>You must be available to accept the Goods at Your nominated delivery address during Business Hours unless otherwise arranged.&nbsp;</p>
<p><strong>Passing of Risk: </strong>Delivery is deemed to take place when the Goods are delivered to Your nominated address, whereupon risks of loss, breakage and all damage and all other risks pass to You. Nothing in this clause 15.3 will affect title to the Goods.&nbsp;</p>
<p><strong>Obligation to insure: </strong>You will ensure that Goods are adequately insured from the time of delivery under clause 15.3.&nbsp;</p>
<p><strong>Retention of Title: </strong>Until We receive full payment in cleared funds for any moneys due to Us by You on any account or for any reason:&nbsp;</p>
<p>title to, and property in, goods supplied to You remain vested in Us and does not pass to You.&nbsp;</p>
<p>You must hold those Goods as fiduciary bailee and agent for Us and must not sell them.&nbsp;</p>
<p>You must keep those Goods separate from other goods and maintain the Goods and their labelling and packaging intact.&nbsp;</p>
<p>Where You sell the goods in breach of these Conditions, you are required to hold the proceeds of any sale of those Goods on trust for Us in a separate account (however any failure to do so will not affect Your obligation to deal with the proceeds as trustee and remit them to Us).&nbsp;</p>
<p>We may, without prior notice, enter into any premises where We suspect those Goods may be, take possession of those Goods and sever and remove those Goods (notwithstanding that they may have been attached to other goods not the property of Ours) and for this purpose, You hereby irrevocably authorise and direct Us (and Our employees and agents) to enter into such premises as its duly authorised agent and You hereby indemnify and hold harmless Us from and against any costs, claims, allegations, demands, damages or expenses or any other acts or omissions arising from or in connection with, such entry, repossession or removal.&nbsp;</p>
<p>You irrevocably appoint Us as Your attorney to do anything We consider necessary to enter such premises and repossess the Goods as contemplated by this clause 15.5.&nbsp;</p>
<p>Computer Utility, Functionality and Fitness for Purpose&nbsp;</p>
<p><strong>Service limitations given the science of computing: </strong>You acknowledge that a reasonable incident of the Services may involve trial and error and that it is a science applied often in novel or unknown circumstances and involving experiment. You acknowledge that the Services may involve tests, troubleshooting, advice and recommendations that may prove incorrect or inappropriate, particularly to cure a problem You are having. While We will make what We consider (in Our absolute discretion) to be all reasonable endeavours to provide appropriate tests, troubleshooting, sound advice and good recommendations to assist You, you will always indemnify and hold Us harmless in the provision of our Services to You.&nbsp;</p>
<p><strong>Reasonable Assistance Limits: </strong>We are only obliged to provide what We consider, in Our absolute discretion, to be reasonable assistance in the circumstances (including with the installation and customisation of new software or hardware for You or any other Work) under any Plan and You will pay for additional work at the Rates unless otherwise agreed. Without limiting the discretion of Us to determine what reasonable assistance is, normally, reasonable assistance is limited to work done during Business Hours over a period not exceeding any period that We have allowed or allows for the Work or has estimated or estimates the Work will take, whether notice of the time allowed or estimated is given by Us to You.&nbsp;</p>
<p><strong>Recommendations, suitability, functionality and fitness for purpose: </strong>The parties acknowledge that:&nbsp;</p>
<p>We may recommend that You purchase Goods provided by third parties from time to time.&nbsp;</p>
<p>Recommendations may be made in situations where You have made known to Us the purpose for which the Goods will be used, or some function sought to be fulfilled.&nbsp;</p>
<p>You acknowledge that We have no control over many factors involved with the suitability, function or fitness for purpose of Goods in an existing or new computer environment, e.g.&nbsp;</p>
<p>the compatibility or ability of the Goods to fit into or perform to expectations in the receiving computer/internet environment; or&nbsp;</p>
<p>the behaviour of third-party supplier, e.g., in relation to support.&nbsp;</p>
<p>You acknowledge that for a whole number of reasons outside of Our control, the Goods may fail to meet Your expectations, may not turn out to be fit for all or any of the purposes sought, may not be suitable or may not function properly in all or any respects.&nbsp;</p>
<p>You acknowledge that the Services provided by Us may involve the very task of seeking to customise Goods so they may be fit for purposes and that customisation may be a very substantial project.&nbsp;</p>
<p>Accordingly, you will accept the sole responsibility for, and indemnify and hold Us harmless in respect of:&nbsp;</p>
<p>decisions as to whether to follow recommendations by Us.&nbsp;</p>
<p>decisions as to whether to purchase or customise Goods or obtain Services for that or any other purpose; and&nbsp;</p>
<p>any failure or defect in suitability, function or fitness for purpose of any Goods and/or Services, including a responsibility to obtain Your own independent advice or second opinion from a suitably qualified person.&nbsp;</p>
<p>Where We provide Services with a view to achieving Your purposes, suitability, function or fitness for purpose (whether expressed, agreed or otherwise), You must pay for those Services on time without any set-off or counter-claim, whether or not We are able to achieve any of such purposes, suitability, function or fitness for purpose, provided always that We have acted in good faith and have made what We consider, in Our absolute discretion, to have made all reasonable endeavours to achieve those outcomes.&nbsp;</p>
<p><strong>Testing Procedures: </strong>You will follow the instructions of Ours about testing or troubleshooting any problems and that if those do not resolve the outstanding problems, we will, subject to these Conditions, allocate such resources as We consider reasonable in the circumstances towards their resolution.&nbsp;</p>
<p>Force Majeure&nbsp;</p>
<p><strong>Force Majeure: </strong>If We are unable to supply any Goods or Services due to circumstances beyond Our reasonable control, we may cancel the Order (even if the Order has already been accepted) or cease to provide the Services by written notice to You, in which case You will hold Us harmless.&nbsp;</p>
<p>We will not be liable for any breach of contract due to any matter or thing beyond Our control, including failures by third parties to supply goods, services or transport, stoppages, transport breakdown, fire, flood, earthquake, acts of God, strikes, lockouts, work stoppages, wars, riots or civil commotion, intervention or public authority, explosion or accident.&nbsp;</p>
<p>Product Specifications&nbsp;</p>
<p><strong>Alterations to Specifications: </strong>We make every effort to supply the Goods in accordance with the Order however We may supply alternate Goods subject to minor variations in actual dimensions and specifications where these are changed by the manufacturer of the Goods after the Order date and before delivery.&nbsp;</p>
<p><strong>Substitute Goods: </strong>If We cannot supply the Goods ordered by You, we may supply alternate Goods of equal or superior quality provided however that You will not pay a higher price than the price Quoted or otherwise agreed for the Goods ordered.&nbsp;</p>
<p>Warranties&nbsp;</p>
<p><strong>Reliance on Manufacturer&rsquo;s Warranty: </strong>You will rely on the warranties provided by the manufacturer of Goods supplied by Us (where applicable) and will deal directly with such manufacturer rather than Us for all claims covered by such warranties.&nbsp;</p>
<p><strong>No claim for manufacturer&rsquo;s default: </strong>You indemnifies and hold Us harmless in respect of the performance or otherwise, by any manufacturer of Goods supplied.&nbsp;</p>
<p>to You by Us, of any of the obligations of such manufacturer in respect of such Goods. This includes any damages or moneys due to You arising under, or in connection with, any breach by the manufacturer of any the manufacturer&rsquo;s warranties in respect of the Goods.&nbsp;</p>
<p>Liability&nbsp;</p>
<p><strong>Exclusion: </strong>Except as specifically set out herein and so far, as may be permitted by law, any term, condition or warranty in respect of the quality, fitness for purpose, condition, description, assembly, manufacture, design or performance of the Goods or Services, whether implied by statute, common law, trade usage, custom or otherwise, is hereby expressly excluded.&nbsp;</p>
<p><strong>No liability for program or data loss: </strong>You indemnify and hold Us harmless in respect of any allegation, claim, loss or expense of Yours or any third party for any program or data loss or damage suffered by You or that third party arising directly or indirectly from the supply of the Goods or Services by Us to You. You acknowledge You are solely responsible for backing up Your programs and data to mitigate Your own potential loss of programs and data.&nbsp;</p>
<p><strong>Limit on consequential damage: </strong>You indemnify and hold Us harmless in respect of any allegation or claim as to any indirect or consequential losses or expenses suffered by You or any third party, howsoever caused, including but not limited to loss of turnover, profits, business or goodwill or any liability to You or any third party.&nbsp;</p>
<p><strong>Limit on damage from a failure in supply: </strong>You indemnify and hold Us harmless for any allegation or claim for loss or damage by You or a third party where We have failed to meet any delivery date or cancels or suspends the supply of Goods or Services.&nbsp;</p>
<p><strong>General limit on liability: </strong>Except as otherwise expressly stated in these terms and conditions, we are not liable for any loss or damage of any kind however caused (including, but not limited to, by the negligence of Us) which is suffered or incurred by You in connection with:&nbsp;</p>
<p>Goods or Services provided to You or any Work.&nbsp;</p>
<p>these Terms and Conditions.&nbsp;</p>
<p>Your use of Our website (including the use of a credit card or other debit device) or any linked website.&nbsp;</p>
<p>the non-availability of Goods or Our Services for any reason.&nbsp;</p>
<p>any act or omission of Ours or the provision of inaccurate, incomplete or incorrect information by You, or&nbsp;</p>
<p>for any other reason whatsoever.&nbsp;</p>
<p><strong>Limitation options: </strong>To the extent that any legislation implies a condition or warranty that cannot be excluded but can be limited, clause <a href="file:///C:/Users/tpeto/Downloads/Services%20Agreement%20TaaS%20Omnia%20Global%2024.docx#_bookmark25" target="_blank" rel="noopener">21.5 </a>does not apply to that liability and Our liability for any breach of that condition or warranty is limited to Our doing any one or more of the following (at its election):&nbsp;</p>
<p>replacing the Goods or supplying equivalent Goods, Services or Work.&nbsp;</p>
<p>repairing the Goods or the Work.&nbsp;</p>
<p>paying the cost of replacing the Goods or the Work or acquiring equivalent Goods, Services or Work; or&nbsp;</p>
<p>paying the cost of having the Goods or the Work repaired.&nbsp;</p>
<p><strong>Laws still apply: </strong>Nothing in these Conditions is to be interpreted as excluding, restricting or modifying or having the effect of excluding, restricting or modifying the application of any State or Federal legislation applicable to the supply of the Goods or Services which cannot be excluded, restricted or modified.&nbsp;</p>
<p><strong>Severance: </strong>If any provision contained in the Conditions is unlawful, invalid or unenforceable, those provisions may be severed without prejudice to the validity and enforceability of the remaining provisions of the Conditions.&nbsp;</p>
<p>Errors And Omissions&nbsp;</p>
<p>We make every effort to ensure that all prices and descriptions quoted are correct and accurate. In the case of an error or omission, we may rescind the affected contract by written notice to You, notwithstanding that We have already accepted Your Order and/or received payment from You. Our liability in that event will be limited to the return of any money You have paid in respect of the Order.&nbsp;</p>
<p>Our responsibilities&nbsp;</p>
<p>Privacy Statement and Your Rights&nbsp;</p>
<p>We are collecting Your personal information for the fulfilment of Quotes, Orders and the provision of Goods or Services to you and it may retain and use it for any such purposes (&ldquo;Authorised Purposes&rdquo;).&nbsp;</p>
<p>You are required to provide your personal information to Us for Authorised Purposes.&nbsp;</p>
<p>We may disclose Your personal information to other persons for the purposes of the fulfilment of Quotes, Orders and Work for you or in order to provide Goods or Services to You, to verify the information You provide, for enquiries about Goods or Services that may be suitable for your purposes, or to confirm Your requirements, to anyone proposing to supply Goods or Services to You, or to acquire Goods or Services on Your behalf, or in respect of enquiries relating to any of the foregoing.&nbsp;</p>
<p>Otherwise, we will not disclose Your personal information without Your consent unless authorised by law.&nbsp;</p>
<p>Your personal information will be held by Us at Our Principal Place of Business and You can contact Us to request to access or correct it.&nbsp;</p>
<p>We rely on You to submit correct information and details where requested. You accept that You may incur additional expenses if you submit incorrect information.&nbsp;</p>
<p>Lodging Of Service Requests&nbsp;</p>
<p>For Us to provide You with the agreed Service, you agree to follow Our process for lodging of Service Requests as outlined in Appendix A.&nbsp;</p>
<p>Access To Systems, Sites and People&nbsp;</p>
<p>To provide You with the agreed Service, you agree to give Us access to various items of Yours including but not limited to, equipment, people and sites as and when required.&nbsp;</p>
<p>You agree to allow Us to install software on Your Equipment that allows Our technicians to access Your systems at any time. This software allows Us to view system statuses, send monitoring information, see users&rsquo; desktops and control Your PC&rsquo;s. This may require that devices are left on overnight or weekends.&nbsp;</p>
<p>Third Party Authorisations&nbsp;</p>
<p>At times We may need to contact Your third-party providers on Your behalf, such as Your internet provider. Some of these providers may require Your authorisation for Us to deal on Your behalf. It is Your responsibility to ensure that We can deal freely with these providers.&nbsp;</p>
<p>NON-SOLICITATION Of Clients and Employees&nbsp;</p>
<p>You agree that employees are one of Our most valuable assets, policy and professional ethics require that Our employees not seek employment with or be offered employment by You during engagement and for a period of two (2) years thereafter (or the maximum amount permissible by a Court).&nbsp;</p>
<p>You agree that Our damages resulting from breach of this clause 30.1 would be impracticable and that it would be extremely difficult for Us to ascertain the actual number of damages. Therefore, in the event You violate this provision, you agree to immediately pay Us 100% of the employee&rsquo;s total annual salary, as liquidated damages and We shall have the option to terminate this Agreement without further notice or liability to You. The number of liquidated damages reflected herein is not intended as a penalty and is reasonably calculated based upon the projected costs We would incur to identify, recruit, hire and train suitable replacements for such personnel.&nbsp;</p>
<p>Software&nbsp;</p>
<p>All Software licences are the responsibility of You and not that of Us. It is the duty of Yours to store all licences for all Software used, so that that they can be reproduced when required. This includes all Software installed by Us.&nbsp;</p>
<p>You indemnify and hold Us harmless against any claim, allegation, loss, damage or expense arising directly or indirectly from:&nbsp;</p>
<p>any unauthorised Software use by You.&nbsp;</p>
<p>any breach of any Software licence in respect of Software provided to Us by You to be installed on one of Your computers.&nbsp;</p>
<p>otherwise because of Us installing Software at Your where You are not authorised to use the Software; and&nbsp;</p>
<p>any problem, defect or malfunction associated with any Software (or related services) supplied by third parties.&nbsp;</p>
<p>All copyright in custom software remains the sole property of Ours unless alternate arrangements are made as part of a separate software agreement.&nbsp;</p>';
    }
}
