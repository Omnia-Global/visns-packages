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
 *
 * `confirm_failed` is the one worth alerting on: a run of them against one user
 * is somebody working on a session they should not have.
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
        $userId = $request->user()?->id;

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
