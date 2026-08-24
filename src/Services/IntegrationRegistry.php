<?php

namespace Visnsstudio\VisnsPackages\Services;

use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\IntegrationSetting;
use Visnsstudio\VisnsPackages\Models\OAuthConnection;

/**
 * What integrations exist, what each one needs, and whether it is working.
 *
 * The catalogue is config (`visns-packages.integrations`), not code, so adding
 * an integration is a config block rather than a class. Two drivers:
 *
 *   oauth2   — a consent redirect and a token exchange. Delegates the actual
 *              flow to OAuthManager, which already does this; this class only
 *              supplies the client credentials and reports status.
 *   api_key  — a set of fields somebody types in. No redirect.
 *
 * CREDENTIAL RESOLUTION ORDER, and it matters: database, then env, then the
 * config default. A practice that already has keys in .env keeps working
 * untouched; typing a key into the UI overrides it from then on. The reverse
 * order would mean a stale .env silently beating what the user just saved.
 */
class IntegrationRegistry
{
    /** @var array<string, IntegrationSetting|null> */
    private array $settingCache = [];

    /** Every configured integration, enabled or not. */
    public function all(): array
    {
        return config('visns-packages.integrations', []);
    }

    public function definition(string $provider): ?array
    {
        $definition = $this->all()[$provider] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function exists(string $provider): bool
    {
        return $this->definition($provider) !== null;
    }

    public function driver(string $provider): string
    {
        return $this->definition($provider)['driver'] ?? 'api_key';
    }

    /**
     * The field specs for an integration, with the secret values stripped.
     *
     * `is_set` is the only thing said about a secret's value. `source` tells
     * the user WHERE a value came from, which is the difference between "I
     * need to type this" and "this is already in the server's .env".
     */
    public function fields(string $provider): array
    {
        $definition = $this->definition($provider) ?? [];
        $setting = $this->setting($provider);
        $stored = $setting?->credentials ?? [];

        $fields = [];

        foreach ($definition['fields'] ?? [] as $key => $spec) {
            $spec = is_array($spec) ? $spec : ['label' => (string) $spec];
            $secret = (bool) ($spec['secret'] ?? false);

            $inDb = isset($stored[$key]) && $stored[$key] !== '';
            $inEnv = $this->fromEnv($spec) !== null;

            $field = [
                'key' => $key,
                'label' => $spec['label'] ?? $key,
                'help' => $spec['help'] ?? null,
                'secret' => $secret,
                'required' => (bool) ($spec['required'] ?? false),
                'placeholder' => $spec['placeholder'] ?? null,
                'type' => $spec['type'] ?? ($secret ? 'password' : 'text'),
                'options' => $spec['options'] ?? null,
                'is_set' => $inDb || $inEnv,
                'source' => $inDb ? 'database' : ($inEnv ? 'env' : null),
            ];

            // A non-secret is safe to show, and showing it is the difference
            // between an editable field and a guess.
            if (!$secret) {
                $field['value'] = $this->credential($provider, $key);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * One credential, resolved through the whole chain.
     */
    public function credential(string $provider, string $key, $default = null)
    {
        $setting = $this->setting($provider);
        $fromDb = $setting?->credential($key);

        if ($fromDb !== null) {
            return $fromDb;
        }

        $spec = $this->definition($provider)['fields'][$key] ?? [];
        $fromEnv = $this->fromEnv($spec);

        if ($fromEnv !== null) {
            return $fromEnv;
        }

        $configured = is_array($spec) ? ($spec['default'] ?? null) : null;

        return $configured ?? $default;
    }

    /**
     * A field's value from the environment, or null.
     *
     * `env` may name ONE variable or a list of them, tried in order. Real
     * deployments accumulate aliases — this app's Zoho client id lives under
     * `ZOHO_DESK_CLIENT_ID` because Desk was integrated first, and
     * `config/zoho.php` already cascades `ZOHO_CLIENT_ID` to it. A single name
     * here would report a configured integration as unconfigured and invite
     * somebody to retype a credential that was never missing.
     *
     * @param  array|string  $spec  the field spec
     */
    private function fromEnv($spec)
    {
        if (!is_array($spec)) {
            return null;
        }

        foreach ((array) ($spec['env'] ?? []) as $name) {
            $value = env($name);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Every resolved credential for an integration, for the client to use. */
    public function credentials(string $provider): array
    {
        $out = [];

        foreach (array_keys($this->definition($provider)['fields'] ?? []) as $key) {
            $value = $this->credential($provider, $key);

            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Is every required field filled in?
     *
     * Separate from "connected": an OAuth integration can be fully configured
     * and still not connected, and the UI has to tell those apart or the
     * Connect button appears before there is a client id to connect with.
     */
    public function isConfigured(string $provider): bool
    {
        foreach ($this->definition($provider)['fields'] ?? [] as $key => $spec) {
            $required = is_array($spec) ? ($spec['required'] ?? false) : false;

            if (!$required) {
                continue;
            }

            $value = $this->credential($provider, $key);

            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The status of one integration, as the UI renders it.
     *
     * States are deliberately few: `connected`, `expired`, `configured`
     * (ready, not yet connected), `incomplete` (missing a required field),
     * `disabled`.
     */
    public function status(string $provider): array
    {
        $definition = $this->definition($provider);

        if (!$definition) {
            return ['state' => 'unknown', 'label' => 'Unknown integration'];
        }

        $setting = $this->setting($provider);
        $enabled = $setting ? $setting->is_enabled : ($definition['enabled'] ?? true);

        if (!$enabled) {
            return ['state' => 'disabled', 'label' => 'Turned off'];
        }

        if (!$this->isConfigured($provider)) {
            return ['state' => 'incomplete', 'label' => 'Needs configuring'];
        }

        if ($this->driver($provider) === 'oauth2') {
            $connection = $this->connection($provider);

            if (!$connection) {
                return ['state' => 'configured', 'label' => 'Ready to connect'];
            }

            if ($connection->isExpired() && !$connection->refresh_token) {
                // Without a refresh token an expiry is terminal — it needs the
                // consent screen again, not a retry.
                return ['state' => 'expired', 'label' => 'Authorisation expired'];
            }

            return [
                'state' => 'connected',
                'label' => 'Connected',
                'connected_at' => $connection->created_at?->toIso8601String(),
                'expires_at' => $connection->expires_at?->toIso8601String(),
                'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
                'scopes' => $connection->scopes ?? [],
            ];
        }

        // An api_key integration is "connected" once its keys are present;
        // whether they WORK is what Test connection is for.
        return ['state' => 'connected', 'label' => 'Configured'];
    }

    /**
     * Everything the settings page needs, for every integration.
     */
    public function summary(): array
    {
        $out = [];

        foreach ($this->all() as $provider => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $setting = $this->setting($provider);

            $out[] = [
                'provider' => $provider,
                'name' => $definition['name'] ?? $provider,
                'description' => $definition['description'] ?? null,
                'category' => $definition['category'] ?? 'Other',
                'docs_url' => $definition['docs_url'] ?? null,
                'driver' => $this->driver($provider),
                'icon' => $definition['icon'] ?? null,
                'services' => $definition['services'] ?? [],
                'scopes' => $definition['scopes'] ?? [],
                'redirect_uri' => $this->redirectUri($provider),
                'is_enabled' => $setting ? $setting->is_enabled : ($definition['enabled'] ?? true),
                'can_test' => (bool) ($definition['test'] ?? false),
                'fields' => $this->fields($provider),
                'status' => $this->status($provider),
                'last_test' => $setting && $setting->last_tested_at ? [
                    'at' => $setting->last_tested_at->toIso8601String(),
                    'status' => $setting->last_test_status,
                    'message' => $setting->last_test_message,
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * The callback URL to register with the provider.
     *
     * Shown on the card, because it has to be pasted into the provider's own
     * console and getting it wrong is the single most common reason an OAuth
     * setup fails.
     */
    public function redirectUri(string $provider): ?string
    {
        if ($this->driver($provider) !== 'oauth2') {
            return null;
        }

        $configured = $this->credential($provider, 'redirect_uri');

        if ($configured) {
            return $configured;
        }

        return url("/integrations/oauth/{$provider}/callback");
    }

    public function connection(string $provider): ?OAuthConnection
    {
        return OAuthConnection::getActiveConnection($provider);
    }

    public function setting(string $provider): ?IntegrationSetting
    {
        if (!array_key_exists($provider, $this->settingCache)) {
            try {
                $this->settingCache[$provider] = IntegrationSetting::forProvider($provider);
            } catch (\Throwable $e) {
                // Before the migration has run, or on a broken decrypt (a
                // changed APP_KEY), the settings page must still render — it
                // is the only place the user can fix the problem from.
                Log::warning('Integration settings unreadable', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);

                $this->settingCache[$provider] = null;
            }
        }

        return $this->settingCache[$provider];
    }

    public function forget(string $provider): void
    {
        unset($this->settingCache[$provider]);
    }
}
