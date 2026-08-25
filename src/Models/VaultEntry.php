<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One stored credential.
 *
 * Three columns are encrypted at rest by the casts below - `password`,
 * `totp_secret` and `notes`. That encryption uses the application key, so the
 * honest description of what it buys is: a database dump alone is not enough,
 * a database dump plus APP_KEY is. Everything else on the row is plaintext on
 * purpose, so the list can search and sort in SQL without pulling every secret
 * through PHP.
 *
 * `$hidden` covers `password` and `totp_secret` as a backstop only. The
 * controller builds every payload field by field and never hands a model
 * straight to the serialiser; do the same in any application code that reaches
 * for these rows, because `$hidden` is one `makeVisible()` away from being off.
 *
 * AUDITING: this package does not depend on owen-it/laravel-auditing, so the
 * model deliberately implements no auditing contract. An application that audits
 * everything else it owns should extend this model, add the
 * `OwenIt\Auditing\Contracts\Auditable` interface and the `Auditable` trait, and
 * set `$auditExclude = ['password', 'totp_secret', 'notes']` - the audit table
 * is not encrypted, and an unexcluded change event would write the old and new
 * secret into it in the clear. Point `visns-packages.vault.*` at the subclass by
 * binding it in the container if you do.
 */
class VaultEntry extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'totp_secret'];

    protected $appends = ['has_totp'];

    protected $casts = [
        'password' => 'encrypted',
        'totp_secret' => 'encrypted',
        'notes' => 'encrypted',
        'tags' => 'array',
        'password_rotated_at' => 'datetime',
        'totp_digits' => 'integer',
        'totp_period' => 'integer',
    ];

    /**
     * Resolved here rather than in the constructor so static query builders see
     * the configured name too.
     */
    public function getTable()
    {
        return ModuleConfig::get('vault.tables.entries', 'vault_entries');
    }

    /**
     * Whether this entry can produce a one-time code.
     *
     * Deliberately a boolean derived from the secret rather than a flag column:
     * a flag can drift out of step with the seed, and the answer is only ever
     * needed on a row that has already been loaded.
     */
    public function getHasTotpAttribute(): bool
    {
        return trim((string) ($this->totp_secret ?? '')) !== '';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(ModuleConfig::userModel('vault'), 'owner_user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(
            ModuleConfig::userModel('vault'),
            'updated_by_user_id'
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function accessLogs()
    {
        return $this->hasMany(VaultAccessLog::class, 'vault_entry_id');
    }

    /**
     * Everything $user is allowed to see: the shared entries, plus their own
     * private ones.
     *
     * This is the module's whole visibility rule and every read path goes
     * through it - including the single-entry endpoints, which 404 rather than
     * 403 on a miss so that the response cannot be used to probe for which
     * titles exist.
     */
    public function scopeVisibleTo(Builder $query, $user): Builder
    {
        $id = is_object($user) ? ($user->id ?? null) : $user;

        return $query->where(function (Builder $q) use ($id) {
            $q->where($this->getTable() . '.visibility', 'shared');

            if ($id !== null) {
                $q->orWhere($this->getTable() . '.owner_user_id', $id);
            }
        });
    }

    /**
     * A LIKE across the configured search columns, plus the raw tag JSON.
     *
     * The tag match is a plain LIKE on the stored JSON text rather than
     * JSON_CONTAINS: this package runs on MySQL in production and SQLite in its
     * own suite, and only one of those has the JSON functions. The cost is that
     * searching "prod" also matches a tag "production", which for a search box
     * is the wanted behaviour anyway.
     *
     * Column names are whitelisted against the table's own non-secret columns,
     * so a typo - or a mischievous override - naming `password` or an
     * expression cannot widen what is searched.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // No escaping of % / _ here on purpose: MySQL and SQLite disagree on
        // the default LIKE escape character, so escaping portably would need an
        // explicit ESCAPE clause on every dialect. A user who types a wildcard
        // into a search box gets a wildcard, which is harmless.
        $like = '%' . $term . '%';

        $columns = $this->searchableColumns();

        return $query->where(function (Builder $q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhere($this->getTable() . '.' . $column, 'like', $like);
            }

            // Tags are stored as a JSON array of strings; matching the encoded
            // text is enough to find one.
            $q->orWhere($this->getTable() . '.tags', 'like', $like);
        });
    }

    /**
     * The configured search columns, intersected with the columns that are
     * actually safe to search.
     *
     * @return array<int, string>
     */
    public function searchableColumns(): array
    {
        // `client_label` is the client's name denormalised onto the entry (see
        // VaultController::assignClient). Searching it is what makes typing a
        // client's name into the search box find their credentials, which is
        // how most people look for one - they remember whose firewall it is
        // long before they remember what the entry was called.
        $allowed = ['title', 'username', 'url', 'visibility', 'client_label'];

        $configured = (array) ModuleConfig::get(
            'vault.search_columns',
            ['title', 'username', 'url', 'client_label']
        );

        $columns = array_values(
            array_intersect(
                array_map(fn($c) => is_string($c) ? trim($c) : '', $configured),
                $allowed
            )
        );

        return $columns ?: ['title'];
    }

    /**
     * The sort columns the list endpoint accepts. Anything else falls back to
     * `title` - an unbounded ORDER BY is a way to read a column you were never
     * shown.
     *
     * @return array<int, string>
     */
    public static function sortableColumns(): array
    {
        return ['title', 'username', 'updated_at'];
    }
}
