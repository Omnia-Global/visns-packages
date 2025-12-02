<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Config;

use Spatie\Permission\Traits\HasRoles;

use OwenIt\Auditing\Contracts\Auditable;
use Laravel\Sanctum\HasApiTokens;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class User extends Authenticatable implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory, Notifiable, HasRoles, HasApiTokens, HasRelationshipSorting;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'dashboard_settings',
        'provider',
        'provider_id',
        'provider_token',
        'provider_refresh_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'signature',
        'disabled',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'dashboard_settings' => 'array',
        'email_verified_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /**
     * Get the loadable relations for this model.
     *
     * @return array
     */
    public function loadableRelations()
    {
        $defaultRelations = ['roles.permissions'];

        // Get additional relations from config
        $additionalRelations = Config::get(
            'visns-packages.user_additional_loadable_relations',
            []
        );

        // Get dynamic relationships from config
        $dynamicRelationships = array_keys(
            Config::get('visns-packages.user_dynamic_relationships', [])
        );

        // Merge default, additional, and dynamic relations
        return array_merge(
            $defaultRelations,
            $additionalRelations,
            $dynamicRelationships
        );
    }

    /**
     * Get validation rules for this model.
     *
     * @param string $context The context of the validation (store, update)
     * @param array|null $requestData The request data
     * @return array
     */
    public function validationRules($context = 'store', $requestData = null)
    {
        $rules = [];

        // Add 'role' and 'password' rules only if the context is 'store'
        if ($context === 'store') {
            $rules['role'] = 'required';
            $rules['password'] = ['required', 'confirmed'];
        }

        return $rules;
    }

    protected $appends = ['settings'];

    /**
     * Get the settings attribute.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn($value, $attributes) => [
                'style' => [
                    'background' =>
                        env('VISNS_COMPONENT_HEADER_STYLE_BACKGROUND') !== null
                            ? env('VISNS_COMPONENT_HEADER_STYLE_BACKGROUND')
                            : null,
                    'color' =>
                        env('VISNS_COMPONENT_HEADER_STYLE_COLOR') !== null
                            ? env('VISNS_COMPONENT_HEADER_STYLE_COLOR')
                            : null,
                    'multi_select_height' =>
                        env('VISNS_COMPONENT_SELECT_STYLE_HEIGHT') !== null
                            ? env('VISNS_COMPONENT_SELECT_STYLE_HEIGHT')
                            : null,
                ],
                'api' => [
                    'tinymce' =>
                        env('VISNS_COMPONENT_TINY_MCE_API_KEY') !== null
                            ? env('VISNS_COMPONENT_TINY_MCE_API_KEY')
                            : null,
                ],
                'rows_per_page' => $this->getRowsPerPageFromAttributes($attributes),
            ]
        );
    }

    /**
     * Get rows per page from attributes.
     * Helper method for the settings attribute.
     *
     * @param array $attributes
     * @return int
     */
    protected function getRowsPerPageFromAttributes(array $attributes): int
    {
        // Check direct column first
        if (isset($attributes['rows_per_page']) && $attributes['rows_per_page'] !== null) {
            return (int) $attributes['rows_per_page'];
        }

        // Fall back to dashboard_settings if available
        if (isset($attributes['dashboard_settings'])) {
            $settings = is_string($attributes['dashboard_settings'])
                ? json_decode($attributes['dashboard_settings'], true)
                : $attributes['dashboard_settings'];

            if (isset($settings['rows_per_page'])) {
                return (int) $settings['rows_per_page'];
            }
        }

        return 25; // Default value
    }


    /**
     * Scope a query to search by name or email.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCustomSearch($query, $search)
    {
        $columns = ['name', 'email'];

        if (isset($search) && !empty($search)) {
            foreach ($columns as $key => $item) {
                if ($key == 0) {
                    $query->where($item, 'like', '%' . $search . '%');
                } else {
                    $query->orWhere($item, 'like', '%' . $search . '%');
                }
            }
        }
    }

    /**
     * Scope a query to only include active (not disabled) users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('disabled', false);
    }

    /**
     * Scope a query to only include disabled users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDisabled($query)
    {
        return $query->where('disabled', true);
    }

    /**
     * Get the two-factor remember tokens for the user.
     */
    public function twoFactorRememberTokens()
    {
        return $this->hasMany(TwoFactorRememberToken::class);
    }

    /**
     * Handle dynamic method calls to create relationships defined in config.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        // Check if the method is defined in the dynamic relationships config
        $dynamicRelationships = Config::get(
            'visns-packages.user_dynamic_relationships',
            []
        );

        if (array_key_exists($method, $dynamicRelationships)) {
            $relationConfig = $dynamicRelationships[$method];
            $type = $relationConfig['type'] ?? null;
            $model = $relationConfig['model'] ?? null;

            if ($type && $model) {
                switch ($type) {
                    case 'hasOne':
                        return $this->hasOne(
                            $model,
                            $relationConfig['foreign_key'] ?? null,
                            $relationConfig['local_key'] ?? null
                        );

                    case 'hasMany':
                        return $this->hasMany(
                            $model,
                            $relationConfig['foreign_key'] ?? null,
                            $relationConfig['local_key'] ?? null
                        );

                    case 'belongsTo':
                        return $this->belongsTo(
                            $model,
                            $relationConfig['foreign_key'] ?? null,
                            $relationConfig['owner_key'] ?? null,
                            $relationConfig['relation'] ?? null
                        );

                    case 'belongsToMany':
                        return $this->belongsToMany(
                            $model,
                            $relationConfig['pivot_table'] ?? null,
                            $relationConfig['pivot_foreign_key'] ?? null,
                            $relationConfig['pivot_related_key'] ?? null,
                            $relationConfig['parent_key'] ?? null,
                            $relationConfig['related_key'] ?? null
                        );

                    case 'morphOne':
                        return $this->morphOne(
                            $model,
                            $relationConfig['name'] ?? null,
                            $relationConfig['type'] ?? null,
                            $relationConfig['id'] ?? null,
                            $relationConfig['local_key'] ?? null
                        );

                    case 'morphMany':
                        return $this->morphMany(
                            $model,
                            $relationConfig['name'] ?? null,
                            $relationConfig['type'] ?? null,
                            $relationConfig['id'] ?? null,
                            $relationConfig['local_key'] ?? null
                        );

                    case 'morphToMany':
                        return $this->morphToMany(
                            $model,
                            $relationConfig['name'] ?? null,
                            $relationConfig['table'] ?? null,
                            $relationConfig['foreign_pivot_key'] ?? null,
                            $relationConfig['related_pivot_key'] ?? null,
                            $relationConfig['parent_key'] ?? null,
                            $relationConfig['related_key'] ?? null,
                            $relationConfig['inverse'] ?? false
                        );
                }
            }
        }

        // If not a dynamic relationship, call the parent method
        return parent::__call($method, $parameters);
    }

    /**
     * Get the sortable relationship fields for this model.
     * 
     * @return array
     */
    public function getSortableRelationshipFields()
    {
        return [
            // Explicit relationships
            'twoFactorRememberTokens.created_at',
            'twoFactorRememberTokens.name',
            
            // Inherited from traits (Spatie Permission)
            'roles.name',
            'roles.created_at',
            'permissions.name',
            
            // Inherited from traits (Sanctum)
            'tokens.name',
            'tokens.created_at',
            
            // Inherited from traits (Auditing)
            'audits.created_at',
            'audits.event',
            
            // Common dynamic relationships (configure in visns-packages config)
            'profile.name',
            'profile.title',
            'profile.created_at',
            'posts.title',
            'posts.created_at',
            'company.name',
            'department.name',
            
            // Example nested relationships
            'profile.company.name',
            'roles.permissions.name',
        ];
    }
}
