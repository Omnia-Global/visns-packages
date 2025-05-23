<?php

namespace Visnsstudio\VisnsPackages\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Config;

/**
 * Trait UsePackageUser
 *
 * This trait can be used by project User models to inherit functionality
 * from the package User model.
 */
trait UsePackageUser
{
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
            ]
        );
    }

    /**
     * Scope a query to order by a given column.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $orderBy
     * @param string|null $order
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCustomOrder($query, $orderBy, $order)
    {
        if (isset($orderBy) && isset($order)) {
            $query->orderBy($orderBy, $order);
        }

        return $query;
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
        return $this->hasMany(
            \Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken::class
        );
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
        if (method_exists(get_parent_class($this), '__call')) {
            return parent::__call($method, $parameters);
        }

        throw new \BadMethodCallException(
            sprintf('Call to undefined method %s::%s()', static::class, $method)
        );
    }
}
