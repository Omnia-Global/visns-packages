<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Test-only user model, standing in for the one a consuming application
 * provides. Deliberately close to the Throughlife CRM's shape - the columns the
 * auth, OTP and impersonation modules read (`disabled`, `two_factor_token`,
 * `last_logged_ip_address`, `dateLastLogged`, `company_contact_id`) all exist
 * here, so a test can prove the module writes them.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

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
