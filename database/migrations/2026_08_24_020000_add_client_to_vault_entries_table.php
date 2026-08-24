<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a vault entry belong to a client.
 *
 * The vault was built as a flat list of the practice's own logins. In a CRM
 * most credentials are not the practice's at all - they are the CLIENT's:
 * their firewall, their NVR, their vendor portal. Without a link, finding
 * "everything we hold for Acme" means searching the title and hoping whoever
 * created it used the client's name.
 *
 * Deliberately NOT a foreign key constraint. The vault ships in a package that
 * cannot know the consuming application's client table - it might be
 * `customers`, `clients` or `accounts` - so the column is a plain indexed
 * reference and `vault.client` in config names the model. A constraint would
 * make the migration unrunnable anywhere the table happened to be called
 * something else.
 *
 * Nullable, because an entry that belongs to the practice rather than to any
 * client is still the common case.
 */
return new class extends Migration
{
    private function table(): string
    {
        return (string) config('visns-packages.vault.tables.entries', 'vault_entries');
    }

    public function up(): void
    {
        $table = $this->table();

        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'client_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->unsignedBigInteger('client_id')->nullable()->after('visibility')->index();
            // Denormalised on purpose: the list endpoint shows the client on
            // every row, and joining the consuming app's client table from a
            // package that does not know its name is not something this module
            // can do. Refreshed whenever the entry is written.
            $t->string('client_label', 191)->nullable();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        foreach (['client_id', 'client_label'] as $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn(Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
