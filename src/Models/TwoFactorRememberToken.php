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
     * Create a new remember token for a user.
     *
     * @param  mixed  $user
     * @param  string|null  $deviceIdentifier
     * @return string
     */
    public static function createToken($user, $deviceIdentifier = null)
    {
        // Generate a random token
        $token = bin2hex(random_bytes(32));

        // Calculate expiration date (30 days from now)
        $expiresAt = now()->addDays(30);

        // Create the token record
        static::create([
            'user_id' => $user->id,
            'token' => $token,
            'device_identifier' => $deviceIdentifier,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Find a valid token for a user.
     *
     * @param  mixed  $user
     * @param  string  $token
     * @return \Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken|null
     */
    public static function findValidToken($user, $token)
    {
        return static::where('user_id', $user->id)
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Find a valid token by user and device identifier.
     *
     * @param  mixed  $user
     * @param  string  $deviceIdentifier
     * @return \Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken|null
     */
    public static function findValidTokenByDevice($user, $deviceIdentifier)
    {
        return static::where('user_id', $user->id)
            ->where('device_identifier', $deviceIdentifier)
            ->where('expires_at', '>', now())
            ->first();
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
