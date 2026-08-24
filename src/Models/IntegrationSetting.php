<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One integration's stored configuration.
 *
 * `credentials` is encrypted at rest and NEVER leaves the server. The API
 * reports which keys are set, not what they are — see `setKeys()`. A UI that
 * can read a secret back is a UI that leaks it to anyone who can open the
 * settings page.
 */
class IntegrationSetting extends Model
{
    protected $table = 'integration_settings';

    protected $fillable = [
        'provider',
        'credentials',
        'options',
        'is_enabled',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'updated_by_user_id',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'options' => 'array',
        'is_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    // Belt and braces: even a careless `->toArray()` cannot expose these.
    protected $hidden = [
        'credentials',
    ];

    public static function forProvider(string $provider): ?self
    {
        return static::where('provider', $provider)->first();
    }

    /**
     * The credential keys that hold a non-empty value.
     *
     * This is what the UI gets instead of the values themselves, so it can
     * render "set" against a field without ever receiving the secret.
     *
     * @return string[]
     */
    public function setKeys(): array
    {
        $credentials = $this->credentials ?? [];

        return array_values(array_keys(array_filter(
            $credentials,
            fn($value) => $value !== null && $value !== ''
        )));
    }

    public function credential(string $key, $default = null)
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Merge new values in, treating a null as "leave this one alone".
     *
     * The form posts every field on the integration, but sends null for the
     * secrets the user did not retype — because it was never given their
     * current values to send back. Overwriting on null would wipe a working
     * client secret every time somebody corrected a region code.
     */
    public function mergeCredentials(array $incoming): array
    {
        $merged = $this->credentials ?? [];

        foreach ($incoming as $key => $value) {
            if ($value === null) {
                continue;
            }

            // An explicit empty string IS a clear — that is how a field gets
            // emptied, since null already means "unchanged".
            if ($value === '') {
                unset($merged[$key]);

                continue;
            }

            $merged[$key] = is_string($value) ? trim($value) : $value;
        }

        return $merged;
    }
}
