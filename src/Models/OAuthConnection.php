<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Visnsstudio\VisnsPackages\Support\SchemaColumns;

/**
 * A stored OAuth authorisation for one provider.
 *
 * ## The house and its clients (4.19.0)
 *
 * `client_id` NULL is the house's connection - the application's own, which is
 * every row there was before 4.19.0, and what every caller that names no client
 * still reads and writes. An id is one of the host application's clients who
 * has authorised the same provider for themselves (a client's own Zoom SMS
 * user, say - see SmsLine). The two never meet: a house read cannot return a
 * client's token, and storing a client's connection deactivates only that
 * client's previous one.
 */
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
        'client_id',
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
     * Get the active connection for a provider.
     *
     * @param  int|null  $clientId  Null = the house's connection (every caller
     *                              written before 4.19.0); an id = that client's.
     */
    public static function getActiveConnection(string $provider, ?int $clientId = null)
    {
        return static::query()
            ->forClient($clientId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * One owner's connections: null = the house's, an id = that client's.
     *
     * Until the 4.19.0 migration has run there is no `client_id` and every row
     * is the house's, so a house read is unscoped and a client read finds
     * nothing - see Support\SchemaColumns. Without that a package update would
     * take Settings -> Integrations down until somebody migrated.
     */
    public function scopeForClient(Builder $query, ?int $clientId): Builder
    {
        if (! SchemaColumns::has($this->getTable(), 'client_id')) {
            return $clientId === null ? $query : $query->whereRaw('1 = 0');
        }

        return $clientId === null
            ? $query->whereNull($this->qualifyColumn('client_id'))
            : $query->where($this->qualifyColumn('client_id'), $clientId);
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
     * Store a new connection, deactivating the old ones OF THE SAME OWNER.
     *
     * @param  int|null  $clientId  Null = the house's (every caller written
     *                              before 4.19.0); an id = that client's. A
     *                              client connecting never disconnects the
     *                              house, nor another client.
     */
    public static function storeConnection(string $provider, array $tokenData, array $metadata = [], ?int $clientId = null)
    {
        // A client's connection with nowhere to record whose it is would be
        // stored as the HOUSE's - and then sent on as the house. Refused
        // loudly instead; it means 4.19.0's migration has not been run.
        if ($clientId !== null && ! SchemaColumns::has((new static())->getTable(), 'client_id')) {
            throw new \RuntimeException(
                'oauth_connections has no client_id column; run the visns-packages 4.19.0 migration.'
            );
        }

        // Deactivate existing connections
        static::query()
            ->forClient($clientId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $owner = $clientId !== null ? ['client_id' => $clientId] : [];

        return static::create($owner + [
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