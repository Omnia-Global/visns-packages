<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Contracts\Auditable;

class BrandingProfile extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'company_name',
        'logo_url',
        'colors',
        'fonts',
        'company_info',
        'is_default',
    ];

    protected $casts = [
        'colors' => 'array',
        'fonts' => 'array',
        'company_info' => 'array',
        'is_default' => 'boolean',
    ];

    protected $dates = ['deleted_at'];

    public function loadableRelations()
    {
        return ['file'];
    }

    public function file()
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function validationRules($context = 'store', $requestData = null)
    {
        $rules = [];

        return $rules;
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
        $columns = ['name', 'company_name'];

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
     * Get the default branding profile
     */
    public static function getDefault()
    {
        $default = static::where('is_default', true)->first();

        if (!$default) {
            // Create a basic default profile if none exists
            $default = static::create([
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

        return $default;
    }

    /**
     * Set this profile as default (unsets others)
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
     * Get the primary color with fallback
     */
    public function getPrimaryColor()
    {
        return $this->colors['primary'] ?? '#2563eb';
    }

    /**
     * Get the secondary color with fallback
     */
    public function getSecondaryColor()
    {
        return $this->colors['secondary'] ?? '#64748b';
    }

    /**
     * Get the accent color with fallback
     */
    public function getAccentColor()
    {
        return $this->colors['accent'] ?? '#059669';
    }

    /**
     * Get the heading font with fallback
     */
    public function getHeadingFont()
    {
        return $this->fonts['heading'] ?? 'Arial, sans-serif';
    }

    /**
     * Get the body font with fallback
     */
    public function getBodyFont()
    {
        return $this->fonts['body'] ?? 'Arial, sans-serif';
    }

    /**
     * Get company address formatted for display
     */
    public function getFormattedAddress()
    {
        $info = $this->company_info ?? [];
        return $info['address'] ?? '';
    }

    /**
     * Get company contact information
     */
    public function getContactInfo()
    {
        $info = $this->company_info ?? [];

        return [
            'phone' => $info['phone'] ?? '',
            'email' => $info['email'] ?? '',
            'website' => $info['website'] ?? '',
            'abn' => $info['abn'] ?? '',
        ];
    }

    /**
     * Generate CSS variables for this branding profile
     */
    public function getCSSVariables()
    {
        return [
            '--primary-color' => $this->getPrimaryColor(),
            '--secondary-color' => $this->getSecondaryColor(),
            '--accent-color' => $this->getAccentColor(),
            '--heading-font' => $this->getHeadingFont(),
            '--body-font' => $this->getBodyFont(),
        ];
    }

    /**
     * Generate inline CSS for this branding profile
     */
    public function getInlineCSS()
    {
        $variables = $this->getCSSVariables();

        $css = ":root {\n";
        foreach ($variables as $property => $value) {
            $css .= "    {$property}: {$value};\n";
        }
        $css .= "}\n";

        $css .= "
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
        }";

        return $css;
    }

    /**
     * Get logo HTML element
     */
    public function getLogoHTML($className = 'company-logo')
    {
        if ($this->logo_url) {
            return '<img src="' .
                $this->logo_url .
                '" alt="' .
                htmlspecialchars($this->company_name) .
                ' Logo" class="' .
                $className .
                '">';
        }

        // Fallback to company initials
        $initials = strtoupper(substr($this->company_name, 0, 3));
        return '<div class="logo-placeholder primary-bg ' .
            $className .
            '">' .
            $initials .
            '</div>';
    }

    /**
     * Create a copy of this branding profile
     */
    public function duplicate($newName = null)
    {
        $newName = $newName ?? $this->name . ' (Copy)';

        return static::create([
            'name' => $newName,
            'company_name' => $this->company_name,
            'logo_url' => $this->logo_url, // Reference same logo file
            'colors' => $this->colors,
            'fonts' => $this->fonts,
            'company_info' => $this->company_info,
            'is_default' => false,
        ]);
    }

    /**
     * Update logo and handle file management
     */
    public function updateLogo($logoFile)
    {
        // Delete old logo if exists
        if ($this->logo_url) {
            $oldPath = str_replace('/storage/', '', $this->logo_url);
            Storage::disk('public')->delete($oldPath);
        }

        // Store new logo
        $logoPath = $logoFile->store('branding/logos', 'public');
        $logoUrl = Storage::url($logoPath);

        $this->update(['logo_url' => $logoUrl]);

        return $logoUrl;
    }

    /**
     * Delete logo file when profile is deleted
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($profile) {
            if ($profile->logo_url) {
                $logoPath = str_replace('/storage/', '', $profile->logo_url);
                Storage::disk('public')->delete($logoPath);
            }
        });
    }

    /**
     * Validate branding profile
     */
    public function validateProfile()
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Profile name is required';
        }

        if (empty($this->company_name)) {
            $errors[] = 'Company name is required';
        }

        // Validate colors if provided
        if (!empty($this->colors)) {
            foreach (['primary', 'secondary', 'accent'] as $colorType) {
                if (isset($this->colors[$colorType])) {
                    if (
                        !preg_match(
                            '/^#[0-9A-Fa-f]{6}$/',
                            $this->colors[$colorType]
                        )
                    ) {
                        $errors[] =
                            ucfirst($colorType) .
                            ' color must be a valid hex color code';
                    }
                }
            }
        }

        // Validate email if provided
        if (!empty($this->company_info['email'])) {
            if (
                !filter_var($this->company_info['email'], FILTER_VALIDATE_EMAIL)
            ) {
                $errors[] = 'Company email must be a valid email address';
            }
        }

        // Validate website if provided
        if (!empty($this->company_info['website'])) {
            if (
                !filter_var($this->company_info['website'], FILTER_VALIDATE_URL)
            ) {
                $errors[] = 'Company website must be a valid URL';
            }
        }

        return $errors;
    }

    /**
     * Get color palette for UI display
     */
    public function getColorPalette()
    {
        $colors = $this->colors ?? [];

        return [
            'primary' => [
                'hex' => $colors['primary'] ?? '#2563eb',
                'name' => 'Primary',
                'usage' => 'Headers, buttons, primary elements',
            ],
            'secondary' => [
                'hex' => $colors['secondary'] ?? '#64748b',
                'name' => 'Secondary',
                'usage' => 'Text, borders, secondary elements',
            ],
            'accent' => [
                'hex' => $colors['accent'] ?? '#059669',
                'name' => 'Accent',
                'usage' => 'Highlights, call-to-action elements',
            ],
        ];
    }

    /**
     * Export branding profile data for external use
     */
    public function exportData()
    {
        return [
            'name' => $this->name,
            'company' => [
                'name' => $this->company_name,
                'logo_url' => $this->logo_url,
                'info' => $this->company_info ?? [],
            ],
            'design' => [
                'colors' => $this->colors ?? [],
                'fonts' => $this->fonts ?? [],
            ],
            'css_variables' => $this->getCSSVariables(),
            'exported_at' => now()->toISOString(),
        ];
    }
}
