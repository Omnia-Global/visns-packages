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

class User extends Authenticatable implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

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
        return $this->hasMany(TwoFactorRememberToken::class);
    }
}
