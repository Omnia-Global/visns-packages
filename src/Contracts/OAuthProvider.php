<?php

namespace Visnsstudio\VisnsPackages\Contracts;

interface OAuthProvider
{
    /**
     * Get the provider name
     */
    public function getName(): string;

    /**
     * Get the display name for the provider
     */
    public function getDisplayName(): string;

    /**
     * Get the authorization URL for OAuth flow
     */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCodeForTokens(string $code): ?array;

    /**
     * Refresh access token using refresh token
     */
    public function refreshToken(string $refreshToken): ?array;

    /**
     * Test the connection with current tokens
     */
    public function testConnection(): bool;

    /**
     * Get the required OAuth scopes
     */
    public function getScopes(): array;

    /**
     * Get the data types this provider can sync
     */
    public function getSyncableDataTypes(): array;

    /**
     * Perform data sync for specified type
     */
    public function syncData(string $dataType, array $options = []): array;

    /**
     * Get provider configuration requirements
     */
    public function getConfigRequirements(): array;
}