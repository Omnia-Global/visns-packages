<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class TwoFactorRememberToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'device_identifier',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns the token.
     */
    public function user()
    {
        $userModel = Config::get(
            'visns-packages.user_model',
            'App\\Models\\User'
        );
        return $this->belongsTo($userModel);
    }

    /**
     * Check if the token is expired.
     *
     * @return bool
     */
    public function isExpired()
    {
        return $this->expires_at->isPast();
    }

    /**
     * The browser cookie that carries a remembered device's token.
     */
    public const COOKIE_NAME = 'visns_2fa_remember';

    /**
     * How long a remembered device stays remembered, in days.
     */
    public const LIFETIME_DAYS = 30;

    /**
     * The SHA-256 a token is stored as. Only the hash is ever written, so a
     * database read hands nobody a working cookie.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a new remember token for a user.
     *
     * Returns the PLAIN token, which exists nowhere else: the caller hands it
     * to the browser (a cookie) or the API client (the response body) and the
     * row keeps only its hash. The device identifier is stored beside it as an
     * additional condition, never as the proof.
     *
     * @param  mixed  $user
     * @param  string|null  $deviceIdentifier
     * @return string
     */
    public static function createToken($user, $deviceIdentifier = null)
    {
        $token = bin2hex(random_bytes(32));

        static::create([
            'user_id' => $user->id,
            'token' => static::hashToken($token),
            'device_identifier' => $deviceIdentifier,
            'expires_at' => now()->addDays(static::LIFETIME_DAYS),
        ]);

        return $token;
    }

    /**
     * Find a valid token for a user, by the plain token the device presents.
     *
     * @param  mixed  $user
     * @param  string  $token
     * @return \Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken|null
     */
    public static function findValidToken($user, $token)
    {
        if (! is_string($token) || $token === '' || ! $user) {
            return null;
        }

        return static::where('user_id', $user->id)
            ->where('token', static::hashToken($token))
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Find a valid token for this user presented by this device.
     *
     * The plain token is the proof; the device identifier, when the row
     * carries one, must also match.
     *
     * @param  mixed  $user
     * @param  string|null  $token
     * @param  string|null  $deviceIdentifier
     * @return \Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken|null
     */
    public static function findValidTokenForDevice($user, $token, $deviceIdentifier = null)
    {
        $row = static::findValidToken($user, $token);

        if (! $row) {
            return null;
        }

        if (
            $row->device_identifier !== null &&
            $row->device_identifier !== '' &&
            ! hash_equals((string) $row->device_identifier, (string) $deviceIdentifier)
        ) {
            return null;
        }

        return $row;
    }

    /**
     * Find a valid token by user and device identifier.
     *
     * @deprecated 4.17.4 A device identifier (a hash of the User-Agent and the
     *             IP) contains no secret and cannot prove a device was
     *             remembered. Always answers null; use findValidTokenForDevice()
     *             with the token the device presents.
     *
     * @param  mixed  $user
     * @param  string  $deviceIdentifier
     * @return null
     */
    public static function findValidTokenByDevice($user, $deviceIdentifier)
    {
        return null;
    }

    /**
     * Forget every remembered device of a user.
     *
     * @param  mixed  $user
     */
    public static function revokeForUser($user): int
    {
        if (! $user || ! isset($user->id)) {
            return 0;
        }

        return static::where('user_id', $user->id)->delete();
    }

    /**
     * Clean up expired tokens.
     *
     * @return int
     */
    public static function cleanupExpiredTokens()
    {
        return static::where('expires_at', '<', now())->delete();
    }
}
