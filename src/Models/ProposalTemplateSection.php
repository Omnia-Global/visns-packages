<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class ProposalTemplateSection extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'template_id',
        'section_type',
        'title',
        'content',
        'sort_order',
        'is_dynamic',
        'is_enabled',
        'variables',
        'styling',
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'is_enabled' => 'boolean',
        'variables' => 'array',
        'styling' => 'array',
        'sort_order' => 'integer',
    ];

    protected $dates = ['deleted_at'];

    public function loadableRelations()
    {
        return ['template'];
    }

    public function validationRules($context = 'store', $requestData = null)
    {
        $rules = [];

        return $rules;
    }

    /**
     * Relationship to proposal template
     */
    public function template()
    {
        return $this->belongsTo(ProposalTemplate::class, 'template_id');
    }

    /**
     * Scope for custom ordering (integrates with dynamic entity system)
     */
    public function scopeCustomOrder($query, $orderBy, $order)
    {
        if (isset($orderBy) && isset($order)) {
            $query->orderBy($orderBy, $order);
        }

        return $query;
    }

    /**
     * Scope for custom search (integrates with dynamic entity system)
     */
    public function scopeCustomSearch($query, $search)
    {
        $columns = ['title', 'content', 'section_type'];

        if (isset($search) && !empty($search)) {
            foreach ($columns as $key => $item) {
                if ($key == 0) {
                    $query->where($item, 'like', '%' . $search . '%');
                } else {
                    $query->orWhere($item, 'like', '%' . $search . '%');
                }
            }
        }

        return $query;
    }

    /**
     * Get the section type configuration
     */
    public function getSectionTypeConfig()
    {
        $types = ProposalTemplate::getSectionTypes();
        return $types[$this->section_type] ?? null;
    }

    /**
     * Check if section supports custom content
     */
    public function supportsCustomContent()
    {
        $config = $this->getSectionTypeConfig();
        return $config['supports_custom_content'] ?? true;
    }

    /**
     * Get required variables for this section type
     */
    public function getRequiredVariables()
    {
        $config = $this->getSectionTypeConfig();
        return $config['required_variables'] ?? [];
    }

    /**
     * Validate section content based on type
     */
    public function validateSection()
    {
        $errors = [];

        if (empty($this->title)) {
            $errors[] = 'Section title is required';
        }

        if (empty($this->section_type)) {
            $errors[] = 'Section type is required';
        }

        // Type-specific validations
        switch ($this->section_type) {
            case 'cover_page':
                if (empty($this->content) && !$this->is_dynamic) {
                    $errors[] =
                        'Cover page requires content or dynamic generation';
                }
                break;

            case 'overview':
            case 'terms_conditions':
            case 'payment_terms':
            case 'agreement_signature':
            case 'acceptance':
                if (empty($this->content)) {
                    $errors[] =
                        ucfirst(str_replace('_', ' ', $this->section_type)) .
                        ' sections require content';
                }
                break;

            case 'toc':
            case 'quote_items':
            case 'review_log':
                // These are typically dynamic sections
                break;
        }

        return $errors;
    }

    /**
     * Get the section's effective content (with variables replaced)
     */
    public function getEffectiveContent(array $variables = [])
    {
        $content = $this->content;

        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
    }

    /**
     * Extract variables used in this section
     */
    public function getUsedVariables()
    {
        $content = $this->title . ' ' . $this->content;
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * Clone this section for duplication
     */
    public function duplicate($newTemplateId = null)
    {
        return static::create([
            'template_id' => $newTemplateId ?? $this->template_id,
            'section_type' => $this->section_type,
            'title' => $this->title,
            'content' => $this->content,
            'sort_order' => $this->sort_order,
            'is_dynamic' => $this->is_dynamic,
            'is_enabled' => $this->is_enabled,
            'variables' => $this->variables,
            'styling' => $this->styling,
        ]);
    }

    /**
     * Move section up in sort order
     */
    public function moveUp()
    {
        $previousSection = static::where('template_id', $this->template_id)
            ->where('sort_order', '<', $this->sort_order)
            ->orderBy('sort_order', 'desc')
            ->first();

        if ($previousSection) {
            $tempOrder = $this->sort_order;
            $this->update(['sort_order' => $previousSection->sort_order]);
            $previousSection->update(['sort_order' => $tempOrder]);
        }

        return $this;
    }

    /**
     * Move section down in sort order
     */
    public function moveDown()
    {
        $nextSection = static::where('template_id', $this->template_id)
            ->where('sort_order', '>', $this->sort_order)
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($nextSection) {
            $tempOrder = $this->sort_order;
            $this->update(['sort_order' => $nextSection->sort_order]);
            $nextSection->update(['sort_order' => $tempOrder]);
        }

        return $this;
    }

    /**
     * Get default content for section type
     */
    public static function getDefaultContent($sectionType)
    {
        $defaults = [
            'cover_page' => [
                'title' => 'Proposal',
                'content' =>
                    '<div class="cover-page"><div class="cover-branding">{{company_logo}}</div><div class="cover-title"><h1>Business Proposal</h1><h2>For {{customer_name}}</h2></div><div class="cover-details"><p>Proposal Number: {{quote_number}}</p><p>Date: {{current_date}}</p><p>Prepared by: {{company_name}}</p></div></div>',
            ],
            'toc' => [
                'title' => 'Table of Contents',
                'content' => '<!-- Auto-generated table of contents -->',
            ],
            'terms_conditions' => [
                'title' => 'Terms & Conditions',
                'content' =>
                    '<h3>General Terms</h3><p>These terms and conditions form part of any contract for the supply of goods and services by {{company_name}}.</p><h3>Acceptance</h3><p>This proposal is valid for 30 days from the date of issue. Acceptance of this proposal constitutes acceptance of these terms and conditions.</p><h3>Payment</h3><p>Payment terms are as specified in the Payment Terms section.</p><h3>Liability</h3><p>Our liability is limited to the value of the services provided under this agreement.</p>',
            ],
            'review_log' => [
                'title' => 'Review Log',
                'content' =>
                    '<table class="review-log"><thead><tr><th>Version</th><th>Date</th><th>Changes</th><th>Reviewed By</th></tr></thead><tbody><tr><td>1.0</td><td>{{current_date}}</td><td>Initial proposal</td><td>{{project_manager}}</td></tr></tbody></table>',
            ],
            'overview' => [
                'title' => 'Overview',
                'content' =>
                    '<h1>Executive Summary</h1><p>This proposal outlines our recommended solution for {{customer_name}}.</p><h2>Project Scope</h2><p>The scope of this project includes...</p><h3>Key Benefits</h3><ul><li>Benefit 1</li><li>Benefit 2</li><li>Benefit 3</li></ul><h2>Implementation Timeline</h2><p>The proposed timeline for implementation is...</p>',
            ],
            'acceptance' => [
                'title' => 'Acceptance',
                'content' =>
                    '<p>Please review this proposal and provide your acceptance.</p>',
            ],
            'quote_items' => [
                'title' => 'Proposed Solution & Pricing',
                'content' =>
                    '<!-- Auto-generated from quote data following original quote structure -->',
            ],
            'payment_terms' => [
                'title' => 'Payment Terms',
                'content' =>
                    '<h3>Payment Schedule</h3><p>Payment is due within 30 days of invoice date.</p><h3>Payment Methods</h3><p>We accept payment by bank transfer, credit card, or check.</p><h3>Due Date</h3><p>This proposal expires on {{due_date}}.</p>',
            ],
            'agreement_signature' => [
                'title' => 'Agreement & Signatures',
                'content' =>
                    '<div class="signature-section"><h3>Client Acceptance</h3><p>By signing below, the client accepts this proposal and agrees to the terms and conditions outlined herein.</p><div class="signature-block"><div class="signature-line"><p>Client Signature: ___________________________ Date: ___________</p><p>Print Name: ___________________________ Title: ___________</p></div></div><div class="signature-block"><div class="signature-line"><p>{{company_name}} Representative: ___________________________ Date: ___________</p><p>Print Name: ___________________________ Title: ___________</p></div></div></div>',
            ],
        ];

        return $defaults[$sectionType] ?? [
            'title' => 'New Section',
            'content' => '<p>Section content goes here.</p>',
        ];
    }

    /**
     * Update sort orders for sections in template
     */
    public static function updateSortOrders($templateId, array $sectionIds)
    {
        foreach ($sectionIds as $index => $sectionId) {
            static::where('id', $sectionId)
                ->where('template_id', $templateId)
                ->update(['sort_order' => $index + 1]);
        }
    }
}
