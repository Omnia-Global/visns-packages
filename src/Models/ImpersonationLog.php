<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per staff impersonation of a client portal account, written by
 * ImpersonationController::issue().
 *
 * Deliberately minimal: columns, casts, nothing else. Relations to a user or
 * contact model, display accessors and search scopes are all
 * application-domain - the package cannot know what those models are called or
 * which of their fields are safe to serialize. An application wanting them
 * subclasses this (or writes its own) and names it in
 * config('visns-packages.impersonation.log_model'); setting that key to false
 * writes no audit row at all.
 *
 * The plaintext token is never stored here. This table records that an
 * impersonation happened, not how to repeat it.
 */
class ImpersonationLog extends Model
{
    protected $table = 'impersonation_log';

    protected $fillable = [
        'staff_user_id',
        'client_user_id',
        'company_contact_id',
        'ip_address',
        'user_agent',
        'token_expires_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];
}
