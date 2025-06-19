<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Visnsstudio\VisnsPackages\Models\ProposalTemplate;
use Visnsstudio\VisnsPackages\Models\BrandingProfile;

class SeedDefaultProposalData extends Migration
{
    /**
     * Run the migrations.
     * Seeds default proposal templates and branding profiles
     * Ensures backward compatibility for existing projects
     *
     * @return void
     */
    public function up()
    {
        // Create default branding profile
        $defaultBranding = BrandingProfile::create([
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
            'company_info' => [
                'address' => '',
                'phone' => '',
                'email' => '',
                'website' => '',
                'abn' => ''
            ],
            'is_default' => true
        ]);

        // Create standard proposal template
        $standardTemplate = ProposalTemplate::create([
            'name' => 'Standard Business Proposal',
            'description' => 'Professional business proposal template with cover page, table of contents, and terms',
            'variables' => [],
            'styling' => [],
            'is_default' => true
        ]);

        // Create sections for standard template
        $sections = [
            [
                'section_type' => 'cover_page',
                'title' => 'Proposal Cover',
                'content' => '<div class="cover-description"><p style="font-size: 1.2em; margin: 20px 0;">We are pleased to present this comprehensive proposal outlining our recommended solution for your business needs.</p><p style="margin: 20px 0;">This proposal includes detailed information about our services, pricing, and terms to help you make an informed decision.</p></div>',
                'sort_order' => 1,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => []
            ],
            [
                'section_type' => 'toc',
                'title' => 'Table of Contents',
                'content' => '',
                'sort_order' => 2,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => []
            ],
            [
                'section_type' => 'content',
                'title' => 'Executive Summary',
                'content' => '<p>We appreciate the opportunity to present our proposal for {{customer_name}}. Our team has carefully analyzed your requirements and developed a comprehensive solution that addresses your specific needs.</p><p>This proposal outlines our recommended approach, detailed pricing, and the value we can deliver to your organization. We are confident that our solution will meet your expectations and provide excellent return on investment.</p><h3>Key Benefits</h3><ul><li>Professional and reliable service delivery</li><li>Competitive pricing with transparent costs</li><li>Dedicated project management and support</li><li>Proven track record of successful implementations</li></ul>',
                'sort_order' => 3,
                'is_dynamic' => false,
                'variables' => ['customer_name'],
                'styling' => []
            ],
            [
                'section_type' => 'quote_items',
                'title' => 'Proposed Solution & Pricing',
                'content' => '',
                'sort_order' => 4,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => []
            ],
            [
                'section_type' => 'terms',
                'title' => 'Terms & Conditions',
                'content' => '<h3>Payment Terms</h3><p>Payment is due within 30 days of invoice date. A 1.5% monthly service charge may be applied to overdue accounts.</p><h3>Acceptance</h3><p>This proposal is valid for 30 days from the date of issue. Acceptance of this proposal constitutes agreement to these terms and conditions.</p><h3>Scope of Work</h3><p>The scope of work is limited to items specifically outlined in this proposal. Any additional work will require a separate agreement and may incur additional charges.</p><h3>Limitation of Liability</h3><p>Our liability is limited to the total amount of this contract. We shall not be liable for any indirect, special, or consequential damages arising from this agreement.</p><h3>Intellectual Property</h3><p>All work products and deliverables created under this proposal shall remain the property of {{company_name}} until full payment is received.</p>',
                'sort_order' => 5,
                'is_dynamic' => false,
                'variables' => ['company_name'],
                'styling' => []
            ]
        ];

        foreach ($sections as $sectionData) {
            $standardTemplate->sections()->create($sectionData);
        }

        // Create simple quote template (backward compatible)
        $simpleTemplate = ProposalTemplate::create([
            'name' => 'Simple Quote',
            'description' => 'Basic quote template for backward compatibility with existing quote system',
            'variables' => [],
            'styling' => [],
            'is_default' => false
        ]);

        // Create sections for simple template
        $simpleSections = [
            [
                'section_type' => 'quote_items',
                'title' => 'Quote Details',
                'content' => '',
                'sort_order' => 1,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => []
            ],
            [
                'section_type' => 'terms',
                'title' => 'Terms & Conditions',
                'content' => '<p>{{terms_and_conditions}}</p>',
                'sort_order' => 2,
                'is_dynamic' => false,
                'variables' => ['terms_and_conditions'],
                'styling' => []
            ]
        ];

        foreach ($simpleSections as $sectionData) {
            $simpleTemplate->sections()->create($sectionData);
        }

        // Create modern proposal template
        $modernTemplate = ProposalTemplate::create([
            'name' => 'Modern Professional',
            'description' => 'Contemporary proposal template with enhanced visual design',
            'variables' => [],
            'styling' => [
                'theme' => 'modern',
                'layout' => 'clean'
            ],
            'is_default' => false
        ]);

        // Create sections for modern template
        $modernSections = [
            [
                'section_type' => 'cover_page',
                'title' => 'Professional Proposal',
                'content' => '<div class="modern-cover"><h2 class="accent-text" style="margin-bottom: 30px;">Partnership Proposal</h2><p style="font-size: 1.1em; line-height: 1.8;">We believe in building lasting partnerships through innovative solutions and exceptional service delivery. This proposal represents our commitment to your success.</p><div style="margin: 30px 0; padding: 20px; background: #f8f9fa; border-left: 4px solid var(--accent-color);"><strong>Our Promise:</strong> Deliver exceptional value through professional service, transparent communication, and proven results.</div></div>',
                'sort_order' => 1,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['theme' => 'modern']
            ],
            [
                'section_type' => 'toc',
                'title' => 'Contents',
                'content' => '',
                'sort_order' => 2,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['theme' => 'modern']
            ],
            [
                'section_type' => 'content',
                'title' => 'About This Proposal',
                'content' => '<p>Dear {{customer_name}} team,</p><p>Thank you for considering {{company_name}} as your service partner. We have carefully reviewed your requirements and are excited to present a solution that aligns with your business objectives.</p><h3 class="primary-text">What Makes Us Different</h3><div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;"><div style="padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px;"><h4 class="accent-text">Experience</h4><p>Years of proven success in delivering quality solutions across diverse industries.</p></div><div style="padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px;"><h4 class="accent-text">Innovation</h4><p>Cutting-edge approaches that drive efficiency and competitive advantage.</p></div></div>',
                'sort_order' => 3,
                'is_dynamic' => false,
                'variables' => ['customer_name', 'company_name'],
                'styling' => ['theme' => 'modern']
            ],
            [
                'section_type' => 'quote_items',
                'title' => 'Investment & Value',
                'content' => '',
                'sort_order' => 4,
                'is_dynamic' => true,
                'variables' => [],
                'styling' => ['theme' => 'modern']
            ],
            [
                'section_type' => 'terms',
                'title' => 'Partnership Terms',
                'content' => '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;"><h3 class="primary-text">Investment & Payment</h3><p>Our flexible payment terms are designed to support your cash flow requirements while ensuring project continuity.</p></div><h3>Payment Schedule</h3><ul><li>Initial payment: 30% upon acceptance</li><li>Progress payments: Aligned with project milestones</li><li>Final payment: Upon project completion and acceptance</li></ul><h3>Project Commitment</h3><p>This proposal represents our firm commitment to deliver the outlined solution within the specified timeframe and budget. We stand behind our work with comprehensive warranties and ongoing support.</p><h3>Next Steps</h3><p>We are ready to begin immediately upon your approval. Our team will contact you within 24 hours of acceptance to schedule the project kickoff meeting.</p>',
                'sort_order' => 5,
                'is_dynamic' => false,
                'variables' => [],
                'styling' => ['theme' => 'modern']
            ]
        ];

        foreach ($modernSections as $sectionData) {
            $modernTemplate->sections()->create($sectionData);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Clean up seeded data
        ProposalTemplate::whereIn('name', [
            'Standard Business Proposal',
            'Simple Quote',
            'Modern Professional'
        ])->delete();

        BrandingProfile::where('name', 'Default')->delete();
    }
}