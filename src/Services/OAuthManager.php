<?php

namespace Visnsstudio\VisnsPackages\Services;

use Visnsstudio\VisnsPackages\Contracts\OAuthProvider;
use Visnsstudio\VisnsPackages\Models\OAuthConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OAuthManager
{
    protected array $providers = [];

    /**
     * Register an OAuth provider
     */
    public function registerProvider(string $name, OAuthProvider $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * Get a registered provider
     */
    public function getProvider(string $name): ?OAuthProvider
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Get all registered providers
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get available providers list
     */
    public function getAvailableProviders(): array
    {
        $providers = [];
        foreach ($this->providers as $name => $provider) {
            $providers[$name] = [
                'name' => $name,
                'display_name' => $provider->getDisplayName(),
                'scopes' => $provider->getScopes(),
                'data_types' => $provider->getSyncableDataTypes(),
                'config_requirements' => $provider->getConfigRequirements(),
                'is_connected' => $this->isProviderConnected($name),
                'connection_status' => $this->getConnectionStatus($name),
            ];
        }
        return $providers;
    }

    /**
     * Check if a provider is connected
     */
    public function isProviderConnected(string $provider): bool
    {
        $connection = OAuthConnection::getActiveConnection($provider);
        return $connection && !$connection->isExpired();
    }

    /**
     * Get connection status for a provider
     */
    public function getConnectionStatus(string $provider): array
    {
        $connection = OAuthConnection::getActiveConnection($provider);
        
        if (!$connection) {
            return [
                'status' => 'not_connected',
                'message' => 'Not connected',
            ];
        }

        if ($connection->isExpired()) {
            return [
                'status' => 'expired',
                'message' => 'Connection expired',
                'expires_at' => $connection->expires_at,
            ];
        }

        return [
            'status' => 'connected',
            'message' => 'Connected',
            'expires_at' => $connection->expires_at,
            'last_sync_at' => $connection->last_sync_at,
            'sync_status' => $connection->sync_status,
        ];
    }

    /**
     * Generate authorization URL
     */
    public function getAuthorizationUrl(string $provider): ?string
    {
        $providerInstance = $this->getProvider($provider);
        if (!$providerInstance) {
            return null;
        }

        $state = $this->generateState($provider);
        return $providerInstance->getAuthorizationUrl($state);
    }

    /**
     * Handle OAuth callback
     */
    public function handleCallback(string $provider, string $code, string $state): array
    {
        try {
            if (!$this->validateState($state, $provider)) {
                throw new \Exception('Invalid state parameter');
            }

            $providerInstance = $this->getProvider($provider);
            if (!$providerInstance) {
                throw new \Exception('Provider not found');
            }

            $tokens = $providerInstance->exchangeCodeForTokens($code);
            if (!$tokens) {
                throw new \Exception('Failed to exchange code for tokens');
            }

            // Store connection
            $connection = OAuthConnection::storeConnection($provider, $tokens, [
                'connected_at' => now(),
                'provider_name' => $providerInstance->getDisplayName(),
            ]);

            Log::info("OAuth connection established for {$provider}", [
                'connection_id' => $connection->id,
            ]);

            return [
                'success' => true,
                'message' => "Successfully connected to {$providerInstance->getDisplayName()}",
                'connection' => $connection,
            ];

        } catch (\Exception $e) {
            Log::error("OAuth callback failed for {$provider}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test provider connection
     */
    public function testConnection(string $provider): array
    {
        try {
            $providerInstance = $this->getProvider($provider);
            if (!$providerInstance) {
                throw new \Exception('Provider not found');
            }

            $connection = $this->ensureValidConnection($provider);

            $isWorking = $providerInstance->testConnection();
            
            $connection->updateSyncStatus($isWorking ? 'connected' : 'error', [
                'last_test_at' => now(),
                'test_result' => $isWorking,
            ]);

            return [
                'success' => $isWorking,
                'message' => $isWorking ? 'Connection test successful' : 'Connection test failed',
            ];

        } catch (\Exception $e) {
            Log::error("Connection test failed for {$provider}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Disconnect a provider
     */
    public function disconnect(string $provider): bool
    {
        $connection = OAuthConnection::getActiveConnection($provider);
        if ($connection) {
            $connection->deactivate();
            Log::info("OAuth connection disconnected for {$provider}");
            return true;
        }
        return false;
    }

    /**
     * Sync data for a provider
     */
    public function syncData(string $provider, string $dataType, array $options = []): array
    {
        try {
            $providerInstance = $this->getProvider($provider);
            if (!$providerInstance) {
                throw new \Exception('Provider not found');
            }

            $connection = $this->ensureValidConnection($provider);

            $connection->updateSyncStatus('syncing', [
                'sync_started_at' => now(),
                'data_type' => $dataType,
            ]);

            $result = $providerInstance->syncData($dataType, $options);

            $connection->updateSyncStatus('completed', [
                'sync_completed_at' => now(),
                'sync_result' => $result,
            ]);

            return [
                'success' => true,
                'result' => $result,
            ];

        } catch (\Exception $e) {
            if (isset($connection)) {
                $connection->updateSyncStatus('error', [
                    'sync_error_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }

            Log::error("Data sync failed for {$provider}.{$dataType}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate state parameter for OAuth
     */
    protected function generateState(string $provider): string
    {
        $state = Str::random(32);
        cache()->put("oauth_state_{$provider}_{$state}", $provider, 600); // 10 minutes
        return $state;
    }

    /**
     * Preview data before sync
     */
    public function previewData(string $provider, string $dataType, array $options = []): array
    {
        try {
            $providerInstance = $this->getProvider($provider);
            if (!$providerInstance) {
                throw new \Exception('Provider not found');
            }

            // Check if provider supports preview
            if (!method_exists($providerInstance, 'previewData')) {
                throw new \Exception('Provider does not support data preview');
            }

            $connection = $this->ensureValidConnection($provider);

            return $providerInstance->previewData($dataType, $options);
        } catch (\Exception $e) {
            Log::error('OAuth preview failed', [
                'provider' => $provider,
                'data_type' => $dataType,
                'options' => $options,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data_type' => $dataType,
            ];
        }
    }

    /**
     * Refresh OAuth token for a connection
     */
    protected function refreshConnectionToken(string $provider, OAuthConnection $connection): array
    {
        try {
            $providerInstance = $this->getProvider($provider);
            if (!$providerInstance) {
                throw new \Exception('Provider not found');
            }

            if (!$connection->refresh_token) {
                throw new \Exception('No refresh token available');
            }

            Log::info("Attempting to refresh OAuth token for {$provider}", [
                'connection_id' => $connection->id,
                'expires_at' => $connection->expires_at,
            ]);

            $newTokens = $providerInstance->refreshToken($connection->refresh_token);
            if (!$newTokens) {
                throw new \Exception('Token refresh failed - provider returned no tokens');
            }

            // Update the connection with new tokens
            $connection->updateTokens($newTokens);
            $connection->updateSyncStatus('connected', [
                'token_refreshed_at' => now(),
            ]);

            Log::info("OAuth token refreshed successfully for {$provider}", [
                'connection_id' => $connection->id,
                'new_expires_at' => $connection->expires_at,
            ]);

            return [
                'success' => true,
                'message' => 'Token refreshed successfully',
            ];

        } catch (\Exception $e) {
            Log::error("Token refresh failed for {$provider}", [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $connection->updateSyncStatus('error', [
                'token_refresh_failed_at' => now(),
                'token_refresh_error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate and refresh token if needed
     */
    protected function ensureValidConnection(string $provider): OAuthConnection
    {
        $connection = OAuthConnection::getActiveConnection($provider);
        if (!$connection) {
            throw new \Exception('No active connection found. Please connect first.');
        }

        // If token is expired or expires within 5 minutes, refresh it
        if ($connection->isExpired() || $connection->expires_at <= now()->addMinutes(5)) {
            $refreshResult = $this->refreshConnectionToken($provider, $connection);
            if (!$refreshResult['success']) {
                throw new \Exception('Connection token is invalid and refresh failed: ' . $refreshResult['message']);
            }
            // Get the updated connection
            $connection = OAuthConnection::getActiveConnection($provider);
        }

        return $connection;
    }

    /**
     * Validate state parameter
     */
    protected function validateState(string $state, string $provider): bool
    {
        $cachedProvider = cache()->get("oauth_state_{$provider}_{$state}");
        if ($cachedProvider === $provider) {
            cache()->forget("oauth_state_{$provider}_{$state}");
            return true;
        }
        return false;
    }
}