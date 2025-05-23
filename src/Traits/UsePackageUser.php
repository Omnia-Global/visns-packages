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

        // Merge default and additional relations
        return array_merge($defaultRelations, $additionalRelations);
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
}
