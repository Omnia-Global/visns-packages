<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OAuthConnection extends Model
{
    use HasFactory;

    protected $table = 'oauth_connections';

    protected $fillable = [
        'provider',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'is_active',
        'metadata',
        'last_sync_at',
        'sync_status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'is_active' => 'boolean',
        'scopes' => 'array',
        'metadata' => 'array',
        // These were stored in PLAINTEXT. An access token is a bearer
        // credential for a live third-party account and a refresh token is a
        // standing one, so anything with read access to the database had the
        // keys to the connected CRM, helpdesk or mailbox.
        //
        // EncryptedOrPlain rather than Laravel's `encrypted`: this table
        // already has rows in consuming projects, and the built-in cast throws
        // on the first legacy value it reads — which would surface mid-sync
        // rather than at deploy. See the cast for the reasoning.
        'access_token' => \Visnsstudio\VisnsPackages\Casts\EncryptedOrPlain::class,
        'refresh_token' => \Visnsstudio\VisnsPackages\Casts\EncryptedOrPlain::class,
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Get the active connection for a provider
     */
    public static function getActiveConnection(string $provider)
    {
        return static::where('provider', $provider)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Check if the connection is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Deactivate this connection
     */
    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Update sync status
     */
    public function updateSyncStatus(string $status, array $metadata = []): bool
    {
        return $this->update([
            'sync_status' => $status,
            'last_sync_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ]);
    }

    /**
     * Update tokens from refresh
     */
    public function updateTokens(array $tokenData): bool
    {
        return $this->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $this->refresh_token,
            'expires_at' => isset($tokenData['expires_in']) 
                ? now()->addSeconds($tokenData['expires_in']) 
                : null,
            'scopes' => $tokenData['scope'] ?? $this->scopes,
        ]);
    }

    /**
     * Store new connection, deactivating old ones
     */
    public static function storeConnection(string $provider, array $tokenData, array $metadata = [])
    {
        // Deactivate existing connections
        static::where('provider', $provider)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return static::create([
            'provider' => $provider,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expires_at' => isset($tokenData['expires_in']) 
                ? now()->addSeconds($tokenData['expires_in']) 
                : null,
            'scopes' => $tokenData['scope'] ?? [],
            'is_active' => true,
            'metadata' => $metadata,
            'sync_status' => 'pending',
        ]);
    }
}