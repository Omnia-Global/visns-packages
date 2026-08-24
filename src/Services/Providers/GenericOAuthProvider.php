<?php

namespace Visnsstudio\VisnsPackages\Services\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;

/**
 * An OAuth2 provider defined entirely by config.
 *
 * Every provider before this was a PHP class (ZohoDeskProvider, and the
 * HubSpot/Salesforce classes the old config referenced but which never
 * existed). That means adding an integration meant writing a class whose only
 * distinct content was four URLs and a scope list — and it meant a config
 * entry pointing at a missing class failed silently, because
 * `registerOAuthProviders` skips anything that is not `class_exists`.
 *
 * This reads those four URLs from `visns-packages.integrations.<name>.oauth`
 * and its credentials through IntegrationRegistry, so an integration somebody
 * configured in the UI is connectable without a deploy.
 *
 * URL templates may interpolate any credential as `{key}` — Zoho's endpoints
 * differ per data centre (`accounts.zoho.com.au` vs `.com`), and the data
 * centre is a field the user picks, so it cannot be baked into a constant.
 */
class GenericOAuthProvider extends AbstractOAuthProvider
{
    public function __construct(
        private string $providerKey,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    protected function getProviderName(): string
    {
        return $this->providerKey;
    }

    protected function getProviderDisplayName(): string
    {
        return $this->definition()['name'] ?? ucfirst($this->providerKey);
    }

    protected function getBaseApiUrl(): string
    {
        return $this->oauthUrl('api_url');
    }

    protected function getAuthUrl(): string
    {
        return $this->oauthUrl('authorize_url');
    }

    protected function getTokenUrl(): string
    {
        return $this->oauthUrl('token_url');
    }

    public function getScopes(): array
    {
        return (array) ($this->definition()['scopes'] ?? []);
    }

    /**
     * The callback URL.
     *
     * Overridden because the inherited version calls `route('oauth.callback')`,
     * which throws RouteNotFoundException anywhere the package's routes are not
     * loaded — a console command, a queue worker, tinker. The registry builds
     * the same URL with `url()` and honours an explicitly configured
     * `redirect_uri`, so it works in every context.
     */
    protected function getRedirectUri(): string
    {
        return $this->registry()->redirectUri($this->providerKey)
            ?? url("/integrations/oauth/{$this->providerKey}/callback");
    }

    public function getSyncableDataTypes(): array
    {
        // Syncing is the job of this app's own import commands, which know the
        // schema. Reporting none keeps the old DataSyncWizard from offering a
        // sync it cannot perform.
        return [];
    }

    public function syncData(string $dataType, array $options = []): array
    {
        return [
            'success' => false,
            'message' => 'This integration is pulled by its own console command, not from here.',
        ];
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = array_merge([
            'response_type' => 'code',
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'state' => $state,
        ], $this->extraAuthorizeParams());

        $scopes = $this->getScopes();

        if ($scopes) {
            $params['scope'] = implode(
                $this->definition()['oauth']['scope_separator'] ?? ' ',
                $scopes
            );
        }

        return $this->getAuthUrl() . '?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): ?array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri' => $this->getRedirectUri(),
        ]);
    }

    public function refreshToken(string $refreshToken): ?array
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        ]);

        if ($tokens === null) {
            return null;
        }

        // A refresh response usually omits the refresh token; losing it would
        // turn the next expiry into a re-consent.
        $tokens['refresh_token'] = $tokens['refresh_token'] ?? $refreshToken;

        return $tokens;
    }

    private function tokenRequest(array $payload): ?array
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($this->getTokenUrl(), $payload);
        } catch (\Throwable $e) {
            Log::error('OAuth token request threw', [
                'provider' => $this->providerKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $body = $response->json();

        // Zoho answers 200 with {"error":"invalid_code"}, so the status alone
        // is not the test.
        if (!$response->successful() || !is_array($body) || isset($body['error'])) {
            Log::error('OAuth token request refused', [
                'provider' => $this->providerKey,
                'status' => $response->status(),
                // The body carries no secret on an error path, and without it
                // there is nothing to diagnose from.
                'error' => is_array($body) ? ($body['error'] ?? null) : null,
            ]);

            return null;
        }

        if (empty($body['access_token'])) {
            return null;
        }

        return $body;
    }

    private function extraAuthorizeParams(): array
    {
        // Zoho will not issue a refresh token without `access_type=offline`,
        // and will not re-issue one on a second consent without
        // `prompt=consent` — which is why a re-connect appears to work and
        // then expires an hour later.
        return (array) ($this->definition()['oauth']['extra_authorize_params'] ?? []);
    }

    /** A URL from the oauth block, with `{credential}` placeholders filled. */
    private function oauthUrl(string $key): string
    {
        $template = $this->definition()['oauth'][$key] ?? '';

        if ($template === '') {
            throw new \RuntimeException(
                "Integration [{$this->providerKey}] has no oauth.{$key} configured."
            );
        }

        return $this->interpolate($template);
    }

    private function interpolate(string $template): string
    {
        if (!str_contains($template, '{')) {
            return $template;
        }

        $credentials = $this->registry()->credentials($this->providerKey);

        return preg_replace_callback(
            '/\{(\w+)\}/',
            fn($m) => (string) ($credentials[$m[1]] ?? $m[0]),
            $template
        );
    }

    private function definition(): array
    {
        return $this->registry()->definition($this->providerKey) ?? [];
    }

    private function registry(): IntegrationRegistry
    {
        return app(IntegrationRegistry::class);
    }
}
