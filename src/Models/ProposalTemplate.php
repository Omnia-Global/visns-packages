<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class ProposalTemplate extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'variables',
        'styling',
        'is_default',
    ];

    protected $casts = [
        'variables' => 'array',
        'styling' => 'array',
        'is_default' => 'boolean',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /**
     * Relationship to proposal template sections
     */
    public function sections()
    {
        return $this->hasMany(ProposalTemplateSection::class, 'template_id')->orderBy('sort_order');
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
        $columns = ['name', 'description'];

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
     * Get the default template
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Set this template as default (unsets others)
     */
    public function setAsDefault()
    {
        // Unset other defaults
        static::where('id', '!=', $this->id)->update(['is_default' => false]);
        
        // Set this as default
        $this->update(['is_default' => true]);
        
        return $this;
    }

    /**
     * Get available template variables
     */
    public function getAvailableVariables()
    {
        $systemVariables = [
            '{{customer_name}}' => 'Customer Name',
            '{{customer_address}}' => 'Customer Address',
            '{{quote_number}}' => 'Quote/Proposal Number',
            '{{quote_date}}' => 'Quote Date',
            '{{current_date}}' => 'Current Date',
            '{{total_amount}}' => 'Total Amount',
            '{{company_name}}' => 'Company Name',
            '{{company_address}}' => 'Company Address',
            '{{company_phone}}' => 'Company Phone',
            '{{company_email}}' => 'Company Email',
            '{{project_manager}}' => 'Project Manager',
            '{{due_date}}' => 'Due Date',
            '{{terms_and_conditions}}' => 'Terms and Conditions',
        ];

        $customVariables = $this->variables ?? [];

        return array_merge($systemVariables, $customVariables);
    }

    /**
     * Create a copy of this template
     */
    public function duplicate($newName = null)
    {
        $newName = $newName ?? $this->name . ' (Copy)';
        
        $duplicate = static::create([
            'name' => $newName,
            'description' => $this->description,
            'variables' => $this->variables,
            'styling' => $this->styling,
            'is_default' => false,
        ]);

        // Duplicate sections
        foreach ($this->sections as $section) {
            $duplicate->sections()->create([
                'section_type' => $section->section_type,
                'title' => $section->title,
                'content' => $section->content,
                'sort_order' => $section->sort_order,
                'is_dynamic' => $section->is_dynamic,
                'variables' => $section->variables,
                'styling' => $section->styling,
            ]);
        }

        return $duplicate;
    }

    /**
     * Get template preview data
     */
    public function getPreviewData()
    {
        return [
            'customer_name' => 'Sample Customer Ltd',
            'customer_address' => '123 Business St, Sydney NSW 2000',
            'quote_number' => 'Q-2024-001',
            'quote_date' => date('Y-m-d'),
            'current_date' => date('Y-m-d'),
            'total_amount' => '$15,500.00',
            'company_name' => 'VISNS Studio',
            'company_address' => 'Sydney, NSW',
            'project_manager' => 'John Smith',
            'due_date' => date('Y-m-d', strtotime('+30 days')),
            'terms_and_conditions' => 'Standard terms and conditions apply.',
        ];
    }

    /**
     * Validate template structure
     */
    public function validateTemplate()
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Template name is required';
        }

        if ($this->sections->isEmpty()) {
            $errors[] = 'Template must have at least one section';
        }

        // Check for required section types
        $sectionTypes = $this->sections->pluck('section_type')->toArray();
        
        $requiredSections = ['cover_page', 'toc', 'overview', 'quote_items'];
        foreach ($requiredSections as $required) {
            if (!in_array($required, $sectionTypes)) {
                $errors[] = "Template should include a {$required} section";
            }
        }

        return $errors;
    }

    /**
     * Get section types configuration
     */
    public static function getSectionTypes()
    {
        return [
            'cover_page' => [
                'name' => 'Cover Page',
                'description' => 'Title page with company branding and proposal title (emulates existing template)',
                'required_variables' => ['customer_name', 'company_name', 'quote_number'],
                'supports_custom_content' => true,
                'is_static' => false,
                'supports_dynamic_content' => true,
            ],
            'toc' => [
                'name' => 'Table of Contents',
                'description' => 'Auto-generated table of contents based on all sections',
                'required_variables' => [],
                'supports_custom_content' => false,
                'is_static' => false,
                'supports_dynamic_content' => true,
            ],
            'terms_conditions' => [
                'name' => 'Terms & Conditions',
                'description' => 'Static legal terms and conditions (rarely changes)',
                'required_variables' => [],
                'supports_custom_content' => true,
                'is_static' => true,
                'supports_dynamic_content' => false,
            ],
            'review_log' => [
                'name' => 'Review Log',
                'description' => 'Dynamic review and revision history',
                'required_variables' => ['current_date', 'project_manager'],
                'supports_custom_content' => true,
                'is_static' => false,
                'supports_dynamic_content' => true,
            ],
            'overview' => [
                'name' => 'Overview Section',
                'description' => 'Dynamic content with H1, H2, H3 headers and custom content',
                'required_variables' => ['customer_name'],
                'supports_custom_content' => true,
                'is_static' => false,
                'supports_dynamic_content' => true,
                'supports_headers' => true,
            ],
            'quote_items' => [
                'name' => 'Pricing Section',
                'description' => 'Pricing table following original quote structure',
                'required_variables' => ['items_onceoff', 'items_monthly_subscription', 'items_yearly_subscription', 'total_amount'],
                'supports_custom_content' => false,
                'is_static' => false,
                'supports_dynamic_content' => true,
            ],
            'payment_terms' => [
                'name' => 'Payment Terms',
                'description' => 'Dynamic payment terms and conditions',
                'required_variables' => ['due_date'],
                'supports_custom_content' => true,
                'is_static' => false,
                'supports_dynamic_content' => true,
            ],
            'acceptance' => [
                'name' => 'Acceptance',
                'description' => 'Acceptance section for proposal approval',
                'required_variables' => [],
                'supports_custom_content' => true,
                'is_static' => false,
                'supports_dynamic_content' => true,
            ],
            'agreement_signature' => [
                'name' => 'Agreement & Signature',
                'description' => 'Static agreement and signature section',
                'required_variables' => ['company_name'],
                'supports_custom_content' => true,
                'is_static' => true,
                'supports_dynamic_content' => false,
            ],
        ];
    }
}