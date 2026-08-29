<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Test-only user model, standing in for the one a consuming application
 * provides. Deliberately close to the Throughlife CRM's shape - the columns the
 * auth, OTP and impersonation modules read (`disabled`, `two_factor_token`,
 * `last_logged_ip_address`, `dateLastLogged`, `company_contact_id`) all exist
 * here, so a test can prove the module writes them.
 */
class User extends Authenticatable implements WebAuthnAuthenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    // Passkeys. A consuming application adds the same interface and trait to
    // its own user model - the package cannot do it from the outside, which is
    // why config/visns-packages.php's `passkeys` block says so in as many
    // words.
    use WebAuthnAuthentication;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'two_factor_token_sent_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'dateLastLogged' => 'datetime',
        'otp_sent_at' => 'datetime',
        'otp_locked_until' => 'datetime',
        'disabled' => 'boolean',
        // No 'hashed' cast on the password: the auth controller hashes with
        // Hash::make itself, and a cast would hash the digest a second time.
    ];
}
