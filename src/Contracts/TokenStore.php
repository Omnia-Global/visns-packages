<?php

namespace Visnsstudio\VisnsPackages\Contracts;

interface TokenStore
{
    /**
     * Store OAuth tokens
     */
    public function storeTokens(string $provider, array $tokens, array $metadata = []): bool;

    /**
     * Get active tokens for provider
     */
    public function getActiveTokens(string $provider): ?array;

    /**
     * Check if tokens are expired
     */
    public function areTokensExpired(string $provider): bool;

    /**
     * Revoke/deactivate tokens
     */
    public function revokeTokens(string $provider): bool;

    /**
     * Get token metadata
     */
    public function getTokenMetadata(string $provider): ?array;
}