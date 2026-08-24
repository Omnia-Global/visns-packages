<?php

namespace Visnsstudio\VisnsPackages\Services\Providers;

use Visnsstudio\VisnsPackages\Contracts\OAuthProvider;
use Visnsstudio\VisnsPackages\Models\OAuthConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractOAuthProvider implements OAuthProvider
{
    protected array $config;
    protected string $name;
    protected string $displayName;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->name = $this->getProviderName();
        $this->displayName = $this->getProviderDisplayName();
    }

    /**
     * Get the internal provider name (override in child classes)
     */
    abstract protected function getProviderName(): string;

    /**
     * Get the display name (override in child classes)
     */
    abstract protected function getProviderDisplayName(): string;

    /**
     * Get the base API URL (override in child classes)
     */
    abstract protected function getBaseApiUrl(): string;

    /**
     * Get the OAuth authorization URL (override in child classes)
     */
    abstract protected function getAuthUrl(): string;

    /**
     * Get the OAuth token URL (override in child classes)
     */
    abstract protected function getTokenUrl(): string;

    public function getName(): string
    {
        return $this->name;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * Get configuration value
     */
    protected function getConfig(string $key, $default = null)
    {
        // The integrations registry FIRST, because that is where a credential
        // somebody typed into Settings -> Integrations lives. Without this the
        // UI could store a client id that nothing ever read, and the only way
        // to change a key would still be a deploy.
        //
        // It resolves database -> env -> config default internally, so the two
        // fallbacks below remain the answer for a provider that is not in the
        // integrations catalogue at all.
        try {
            $registry = app(\Visnsstudio\VisnsPackages\Services\IntegrationRegistry::class);

            if ($registry->exists($this->name)) {
                $value = $registry->credential($this->name, $key);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
            // No container, or the settings table does not exist yet. Fall
            // through to the static config rather than break the provider.
        }

        return $this->config[$key] ?? config("oauth-providers.{$this->name}.{$key}", $default);
    }

    /**
     * Get client ID
     */
    protected function getClientId(): string
    {
        return $this->getConfig('client_id') ?? throw new \Exception('Client ID not configured');
    }

    /**
     * Get client secret
     */
    protected function getClientSecret(): string
    {
        return $this->getConfig('client_secret') ?? throw new \Exception('Client secret not configured');
    }

    /**
     * Get redirect URI
     */
    protected function getRedirectUri(): string
    {
        return $this->getConfig('redirect_uri') ?? route('oauth.callback', ['provider' => $this->name]);
    }

    /**
     * Get active connection
     */
    protected function getActiveConnection(): ?OAuthConnection
    {
        return OAuthConnection::getActiveConnection($this->name);
    }

    /**
     * Get valid access token (refresh if needed)
     */
    protected function getAccessToken(): string
    {
        $connection = $this->getActiveConnection();
        
        if (!$connection) {
            throw new \Exception('No active connection found');
        }

        if ($connection->isExpired() && $connection->refresh_token) {
            $this->refreshConnectionToken($connection);
            $connection->refresh();
        }

        if ($connection->isExpired()) {
            throw new \Exception('Connection has expired and cannot be refreshed');
        }

        return $connection->access_token;
    }

    /**
     * Refresh connection token
     */
    protected function refreshConnectionToken(OAuthConnection $connection): void
    {
        $tokens = $this->refreshToken($connection->refresh_token);
        
        if ($tokens) {
            $connection->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => isset($tokens['expires_in']) 
                    ? now()->addSeconds($tokens['expires_in']) 
                    : null,
            ]);

            Log::info("Refreshed OAuth token for {$this->name}");
        } else {
            throw new \Exception('Failed to refresh access token');
        }
    }

    /**
     * Make authenticated API request
     */
    protected function makeApiRequest(string $method, string $endpoint, array $data = []): array
    {
        $accessToken = $this->getAccessToken();
        $url = rtrim($this->getBaseApiUrl(), '/') . '/' . ltrim($endpoint, '/');

        $request = Http::withHeaders([
            'Authorization' => $this->getAuthorizationHeader($accessToken),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        // Add any provider-specific headers
        $additionalHeaders = $this->getAdditionalHeaders();
        if ($additionalHeaders) {
            $request = $request->withHeaders($additionalHeaders);
        }

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => throw new \Exception('Unsupported HTTP method: ' . $method),
        };

        if (!$response->successful()) {
            Log::error("API request failed for {$this->name}", [
                'method' => $method,
                'url' => $url,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \Exception("API request failed: {$response->status()} - {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Get authorization header format (override if needed)
     */
    protected function getAuthorizationHeader(string $accessToken): string
    {
        return "Bearer {$accessToken}";
    }

    /**
     * Get additional headers (override if needed)
     */
    protected function getAdditionalHeaders(): array
    {
        return [];
    }

    /**
     * Default implementation of getConfigRequirements
     */
    public function getConfigRequirements(): array
    {
        return [
            'client_id' => 'OAuth Client ID',
            'client_secret' => 'OAuth Client Secret',
            'redirect_uri' => 'OAuth Redirect URI',
        ];
    }

    /**
     * Default test connection implementation
     */
    public function testConnection(): bool
    {
        try {
            $this->getAccessToken();
            return true;
        } catch (\Exception $e) {
            Log::error("Connection test failed for {$this->name}", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}