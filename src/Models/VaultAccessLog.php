<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Append-only record of who touched what.
 *
 * `$timestamps` is off and the table has no `updated_at`: a row here describes a
 * moment, and nothing is allowed to revise it. `created_at` is stamped by
 * record() rather than by Eloquent so that a frozen clock in a test - or an
 * application that has travelled time for a replay - produces the timestamp it
 * asked for.
 *
 * The actions the module writes:
 *
 *   view             an entry's detail was opened
 *   reveal_password  the plaintext password was handed to a browser
 *   otp              a one-time code was generated
 *   copy_username    the browser reported copying the username
 *   confirm_failed   a password confirmation was refused (entry is null)
 *   share_create     an external share link was created for the entry
 *   share_view       an external share link was opened and its fields read
 *   share_revoke     an external share link was closed
 *
 * `confirm_failed` is the one worth alerting on: a run of them against one user
 * is somebody working on a session they should not have.
 *
 * `share_view` is the one row in this table written by a request with no
 * authenticated user behind it. Its `user_id` is the CRM account that CREATED
 * the link, not the reader - the reader has no account, the column is not
 * nullable, and inventing a user would be worse than attributing the access to
 * the person who is genuinely accountable for it. The `ip` and `user_agent` on
 * the row are the reader's, so the pair reads as "the link this user created
 * was opened from that address", which is the sentence the log is for.
 */
class VaultAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vault_entry_id',
        'user_id',
        'action',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('vault.tables.access_logs', 'vault_access_logs');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function entry()
    {
        return $this->belongsTo(VaultEntry::class, 'vault_entry_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(ModuleConfig::userModel('vault'), 'user_id');
    }

    /**
     * Write one row.
     *
     * Takes the entry as a model, an id or null so that callers do not have to
     * load a row they already have the id of, and so the entry-less actions
     * (`confirm_failed`) go through exactly the same path as the rest.
     *
     * Returns null when there is no authenticated user: nothing in this module
     * reaches an unauthenticated request, but a log write is not the place to
     * throw if that ever changes.
     */
    public static function record(
        VaultEntry|int|null $entry,
        string $action,
        Request $request
    ): ?self {
        return static::recordAs($entry, $action, $request, $request->user()?->id);
    }

    /**
     * Write one row against a user who is not the one making the request.
     *
     * The one caller is the public share endpoint: an external reveal has a
     * real IP and a real user agent but no session, and the account accountable
     * for it is whoever created the link. Everything else goes through
     * record(), which is this with the request's own user filled in.
     *
     * Still returns null with no user id, for the same reason record() did: a
     * log write is not the place to throw.
     */
    public static function recordAs(
        VaultEntry|int|null $entry,
        string $action,
        Request $request,
        $userId
    ): ?self {
        $userId = is_numeric($userId) ? (int) $userId : null;

        if ($userId === null) {
            return null;
        }

        return static::create([
            'vault_entry_id' => $entry instanceof VaultEntry
                ? $entry->id
                : $entry,
            'user_id' => $userId,
            'action' => substr($action, 0, 32),
            'ip' => $request->ip(),
            // Truncated rather than rejected: a browser sending a 4KB
            // user-agent must not cost the caller its action.
            'user_agent' => $request->userAgent() === null
                ? null
                : substr($request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
