<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External share links for one vault entry - "send this password to the
 * client's IT contact" without emailing the password itself.
 *
 * THE LINK IS THE SECRET. There is no second factor on the public side: anyone
 * holding the URL, inside its expiry and its view budget, can read the fields
 * it was created with. That is the deal Bitwarden Send makes too, and it is
 * only safe because of the three limits stored here - an expiry, a view count
 * and a revoke - which is why none of them is optional to implement.
 *
 * WHAT IS STORED, AND WHAT IS NOT
 *
 * `token_hash` is the SHA-256 of the URL token and the raw token is never
 * written anywhere - not here, not in a log, not in the response to a second
 * request. The create endpoint returns the full URL once and that is the only
 * time it exists outside the recipient's address bar. A database dump therefore
 * does not yield working links, which is the entire reason the column is a hash
 * rather than the token.
 *
 * SHA-256 rather than bcrypt on purpose. The usual argument for a slow hash is
 * that the input is a human-chosen password with maybe 30 bits of entropy; this
 * input is 192 bits from random_bytes(), so there is nothing to brute force and
 * a slow hash would only buy the public endpoint a way to be DoSed. Fixed-cost
 * hashing also means the row can be found with an indexed lookup instead of a
 * table scan comparing every row.
 *
 * NO SNAPSHOT OF THE CREDENTIAL. The share points at the entry and the fields
 * are read live at reveal time. The trade-off is stated in full in the model.
 *
 * `fields_shared` is a JSON array of field names - which of username, password,
 * totp, url, notes the recipient gets. It is a whitelist consulted at reveal
 * time, so narrowing what a share exposes never depends on the front end having
 * asked for less.
 *
 * `views` is incremented by a single guarded UPDATE, never read-then-written -
 * see VaultShare::spend(). A one-view link that two people click at the same
 * moment must open for exactly one of them.
 *
 * Idempotent: an application that already owns this table is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('vault_entry_id')->index();

            // 64 hex characters, exactly - SHA-256 rendered by bin2hex. Unique
            // because a collision would mean one token opening two shares, and
            // indexed because every public request starts by looking a row up
            // by this and nothing else.
            $t->char('token_hash', 64)->unique();

            // Which of username / password / totp / url / notes this link
            // hands over. `json` rather than a set of booleans so a new
            // shareable field does not need a migration.
            $t->json('fields_shared');

            // Same width as users.id (see the entries migration), and no
            // ON DELETE rule below beyond the entry cascade: who created a
            // share must outlive their account leaving.
            $this->userKey($t, 'created_by_user_id')->nullable()->index();

            // Both nullable, and null means "no limit on this axis". A share
            // with neither is a permanent public URL, which is why the create
            // endpoint refuses one - the schema allows it so that a consuming
            // application can decide otherwise, the controller does not.
            $t->dateTime('expires_at')->nullable()->index();
            $t->unsignedInteger('max_views')->nullable();

            $t->unsignedInteger('views')->default(0);

            $t->dateTime('revoked_at')->nullable();
            $t->dateTime('last_viewed_at')->nullable();

            $t->timestamps();
        });

        $entries = (string) config(
            'visns-packages.vault.tables.entries',
            'vault_entries'
        );

        if (Schema::hasTable($entries)) {
            Schema::table($table, function (Blueprint $t) use ($entries) {
                // cascade, unlike the access log's nullOnDelete: a share is a
                // pointer at one credential and is meaningless without it,
                // where a log row is a statement about the past that has to
                // survive. Note this fires on a HARD delete only - the vault
                // soft-deletes, and VaultShare checks for that separately so
                // that deleting an entry closes its links immediately.
                $t->foreign('vault_entry_id')
                    ->references('id')
                    ->on($entries)
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.vault.tables.shares',
            'vault_shares'
        );
    }

    /**
     * A column able to reference users.id whatever width the consumer's users
     * table uses.
     */
    private function userKey(Blueprint $t, string $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        return $this->usersKeyIsBig()
            ? $t->unsignedBigInteger($column)
            : $t->unsignedInteger($column);
    }

    private function usersKeyIsBig(): bool
    {
        if (! Schema::hasTable('users')) {
            return true;
        }

        $type = strtolower((string) Schema::getColumnType('users', 'id'));

        return ! in_array(
            $type,
            ['int', 'integer', 'int unsigned', 'mediumint', 'smallint'],
            true
        );
    }
};
