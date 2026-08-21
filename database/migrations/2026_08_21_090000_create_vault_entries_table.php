<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vault's entries: one row per stored credential.
 *
 * `password`, `totp_secret` and `notes` are TEXT because they hold Laravel's
 * encrypted payload, not the value - the ciphertext of even a short password is
 * a couple of hundred bytes, and the column must never be sized to the secret.
 * They are the only three columns that carry anything sensitive; everything
 * else on the row is deliberately readable so the list can be searched and
 * sorted in the database without decrypting anything.
 *
 * `visibility` is 'shared' or 'private'. A private entry is visible to its owner
 * alone; a shared entry is visible to everyone holding the access permission,
 * which is why creating or editing one requires the manage permission.
 *
 * The user foreign keys are added only when the application actually has a
 * `users` table at migration time - this package is installed into applications
 * whose user table may be named something else or created later, and a hard
 * dependency would make the migration unrunnable there.
 *
 * Idempotent: an application that already owns this table is left as it is.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->string('title', 191)->index();
            $t->string('username', 191)->nullable()->index();

            // A URL is not indexed and is generously sized: what goes in here
            // is whatever the login page's address happens to be.
            $t->string('url', 2048)->nullable();

            // Encrypted at rest by the model's casts.
            $t->text('password')->nullable();
            $t->text('totp_secret')->nullable();
            $t->text('notes')->nullable();

            // TOTP parameters travel with the seed: an entry copied from a
            // provider using 8 digits or a 60-second period is useless without
            // them.
            $t->unsignedTinyInteger('totp_digits')->default(6);
            $t->unsignedTinyInteger('totp_period')->default(30);
            $t->string('totp_algorithm', 8)->default('sha1');

            $t->json('tags')->nullable();

            $t->string('visibility', 16)->default('shared')->index();

            // Nullable only because of the foreign key below: MySQL refuses
            // ON DELETE SET NULL against a NOT NULL column outright, so a
            // non-null owner column could not carry the nullOnDelete rule at
            // all. The application always sets an owner on create; a row whose
            // owner has since been deleted is orphaned deliberately, and a
            // private orphan is then visible to nobody rather than to
            // everybody.
            // Sized to match users.id: legacy schemas carry an `int unsigned`
            // key, newer ones `bigint unsigned`, and MySQL refuses a foreign
            // key between the two (errno 150) - leaving the table created,
            // the FK missing and the migration unrecorded.
            $this->userKey($t, 'owner_user_id')->nullable()->index();
            $this->userKey($t, 'updated_by_user_id')->nullable();

            // Rotation reporting: set whenever the password itself changes, not
            // when the row is touched.
            $t->dateTime('password_rotated_at')->nullable();

            $t->timestamps();
            $t->softDeletes();
        });

        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            // nullOnDelete rather than cascade: a departing staff member must
            // not take the shared credentials they happened to create with
            // them.
            $t->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $t->foreign('updated_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.vault.tables.entries',
            'vault_entries'
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

        return ! in_array($type, ['int', 'integer', 'int unsigned', 'mediumint', 'smallint'], true);
    }
};
