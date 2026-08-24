<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One external share link for one vault entry.
 *
 * LIVE VALUES, NOT A SNAPSHOT
 *
 * A share stores which fields it exposes and nothing else; the values are read
 * off the entry at the moment somebody reveals them. The alternative - copying
 * the password into this row when the link is created - was rejected, and the
 * trade-off is worth stating because it cuts both ways.
 *
 *   For live:  revoking a share, soft-deleting the entry or rotating the
 *              password takes effect on the next reveal, with nothing to hunt
 *              down. There is exactly ONE copy of every secret in the database,
 *              still under the entry's `encrypted` casts, so the vault's threat
 *              model does not change shape because somebody sent a link. And a
 *              password corrected an hour after the link went out is the
 *              password the recipient gets, which is almost always what was
 *              meant.
 *
 *   Against:   a rotation between creating the link and the recipient opening
 *              it silently changes what they receive - they get the NEW
 *              password, not the one the sender was looking at. For a
 *              deliberate "here is the credential as of today, do not let it
 *              move under them" a snapshot would be right, and that is a
 *              second mode this table has room for rather than something live
 *              mode is pretending to be.
 *
 * THE LINK IS THE SECRET. There is no password on the public side. Everything
 * protecting a share is in these columns - `expires_at`, `max_views` against
 * `views`, and `revoked_at` - which is why `isOpen()` is written as one
 * expression and `spend()` enforces the same conditions again inside a single
 * UPDATE.
 *
 * TOTP is a special case: a share that includes `totp` hands over the CURRENT
 * SIX-DIGIT CODE computed at reveal time, never the seed. A seed is a permanent
 * second factor and mailing one out is worse than mailing the password; a code
 * is worth thirty seconds. See VaultPublicShareController.
 */
class VaultShare extends Model
{
    protected $guarded = [];

    /**
     * The fields a share may expose.
     *
     * `totp` means "the current code", not the seed - see the class docblock.
     *
     * @var array<int, string>
     */
    public const FIELDS = ['username', 'password', 'totp', 'url', 'notes'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'fields_shared' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'max_views' => 'integer',
        'views' => 'integer',
    ];

    public function getTable()
    {
        return ModuleConfig::get('vault.tables.shares', 'vault_shares');
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
    public function createdBy()
    {
        return $this->belongsTo(
            ModuleConfig::userModel('vault'),
            'created_by_user_id'
        );
    }

    /* ---------------------------------------------------------------------
     | Tokens
     | ------------------------------------------------------------------- */

    /**
     * A fresh URL token: 48 hex characters from 24 random bytes.
     *
     * Hex rather than base64url so the token is unambiguously URL-safe with no
     * padding to strip, survives being pasted into a chat client that helpfully
     * "corrects" punctuation, and can be compared case-insensitively without
     * any of that mattering. 192 bits is far past the point where guessing is a
     * consideration; the length is chosen to be obviously excessive rather than
     * arguably sufficient.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * The stored form of a token.
     *
     * Deliberately unsalted SHA-256: the input is 192 bits of entropy, so there
     * is no dictionary to defend against, and a lookup has to be able to find
     * the row by hash in one indexed query rather than by hashing the candidate
     * against every row in the table.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Find a share by its raw URL token, or null.
     *
     * Returns null for anything malformed before it touches the database - a
     * token is a fixed shape and a request carrying something else is not a
     * near miss worth a query.
     */
    public static function findByToken(?string $token): ?self
    {
        $token = is_string($token) ? trim($token) : '';

        if ($token === '' || ! preg_match('/^[a-f0-9]{40,128}$/i', $token)) {
            return null;
        }

        return static::query()
            ->where('token_hash', static::hashToken(strtolower($token)))
            ->first();
    }

    /* ---------------------------------------------------------------------
     | State
     | ------------------------------------------------------------------- */

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isSpent(): bool
    {
        return $this->max_views !== null
            && (int) $this->views >= (int) $this->max_views;
    }

    /**
     * Whether this link would open right now.
     *
     * Advisory only. Nothing may hand a secret out on the strength of this -
     * between the check and the read another request can spend the last view -
     * which is what spend() is for. It exists for the list endpoint, which
     * needs a word to put next to each row.
     */
    public function isOpen(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->isSpent();
    }

    /**
     * A single word for the list: active, revoked, expired or spent.
     *
     * Revoked wins over expired and expired over spent, because that is the
     * order a person would say them in - "I revoked it" is the more useful
     * answer than "it had also run out".
     */
    public function status(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isSpent()) {
            return 'spent';
        }

        return 'active';
    }

    /**
     * Take one view off this share, atomically, and say whether it was there.
     *
     * This is the only function in the module allowed to authorise an external
     * reveal, and the reason it is one statement rather than a check followed
     * by a save is a one-view link clicked twice at the same instant. Read the
     * row, decide it is open, increment, save - and both requests read `views
     * = 0`, both decide, both write `views = 1`, and both see the password. The
     * WHERE clause below re-states every condition, so the database decides,
     * once, and the loser gets zero affected rows.
     *
     * Returns true exactly once per available view.
     */
    public function spend(): bool
    {
        $now = now();

        $affected = DB::table($this->getTable())
            ->where('id', $this->id)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where(function ($q) {
                // The comparison is column-to-column, so it is evaluated
                // against the row as the database finds it, not against the
                // value this PHP process happens to be holding.
                $q->whereNull('max_views')
                    ->orWhereColumn('views', '<', 'max_views');
            })
            ->update([
                'views' => DB::raw('views + 1'),
                'last_viewed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            return false;
        }

        // Bring this instance back in step with what the database now holds,
        // so a caller that renders the share after spending it does not print
        // a stale count.
        $this->refresh();

        return true;
    }

    /**
     * Whether this share was created with a given field.
     *
     * Reads the stored whitelist, so narrowing a share never depends on the
     * caller having asked for less than it holds.
     */
    public function shares(string $field): bool
    {
        return in_array($field, (array) ($this->fields_shared ?? []), true);
    }

    /**
     * Normalise a requested field list down to the ones that exist.
     *
     * @param  mixed  $fields
     * @return array<int, string>
     */
    public static function cleanFields($fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $clean = [];

        foreach ($fields as $field) {
            $field = strtolower(trim((string) $field));

            if (in_array($field, self::FIELDS, true) && ! in_array($field, $clean, true)) {
                $clean[] = $field;
            }
        }

        // Kept in the module's own order rather than the caller's, so two
        // shares of the same fields store the same array and the reveal page
        // lists them the same way round every time.
        return array_values(array_intersect(self::FIELDS, $clean));
    }
}
