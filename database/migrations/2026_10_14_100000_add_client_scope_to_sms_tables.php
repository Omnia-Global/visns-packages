<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lines a CLIENT owns, alongside the ones the house does.
 *
 * Everything in the messaging module hangs off a line - threads, campaigns,
 * pacing, system messages - so giving a line an owner is what lets a host
 * application hand one of its own clients an SMS number of their own, sending
 * from the client's Zoom tenant rather than ours. `client_id` NULL is the house
 * (the application itself), which is every row that exists today, so nothing
 * already installed changes meaning.
 *
 *   sms_lines.client_id             whose line it is; NULL = the house
 *   sms_lines.oauth_connection_id   the client's own user-level Zoom token.
 *                                   A client line NEVER sends on the house
 *                                   connection or the Server-to-Server app -
 *                                   see Services\Zoom\ZoomSmsClient.
 *   sms_opt_outs.client_id          0 = the house register. NOT NULL, because
 *                                   the unique key below is (client_id, number)
 *                                   and MySQL lets any number of NULLs through
 *                                   a unique index - which would let the house
 *                                   register grow duplicate rows the day two
 *                                   STOPs raced each other.
 *   sms_templates.client_id         NULL = the house's canned bodies
 *   sms_campaigns.created_by_name   who built it when `user_id` is null - a
 *                                   person signed in to a client portal is not
 *                                   a row in `users`, and a campaign nobody can
 *                                   be named against is the first thing asked
 *                                   about one that should not have gone
 *   oauth_connections.client_id     NULL = the house's connection for that
 *                                   provider, an id = that client's
 *
 * No foreign keys on any of the client ids. The package does not know what the
 * host calls its clients table, and a cascade from it would be the wrong
 * direction for an opt-out in particular (see the opt-out migration).
 *
 * THE OPT-OUT KEY CHANGES SHAPE, and that is the one statement here that is not
 * just an added column. An unsubscribe is still per handset - but per handset
 * PER SENDER: somebody who texts STOP to a client's number has asked THAT
 * business to stop, not this one, and the house register must not quietly grow
 * a row on their behalf (nor a client's campaign skip somebody because they
 * once unsubscribed from ours). So the unique on `number` is replaced by one on
 * (client_id, number). Every existing row becomes a house row by the default.
 *
 * Idempotent and guarded column by column and index by index: an application
 * that has already run part of it, or that owns one of these tables in a shape
 * of its own, is left alone where it already agrees. Runs on SQLite and MySQL;
 * on SQLite the index changes are plain CREATE/DROP INDEX statements.
 */
return new class extends Migration {
    /** The composite unique the opt-out register is keyed on from now on. */
    private const OPT_OUT_UNIQUE = 'sms_opt_outs_client_number_unique';

    public function up(): void
    {
        $lines = $this->table('lines', 'sms_lines');

        if (Schema::hasTable($lines)) {
            if (! Schema::hasColumn($lines, 'client_id')) {
                Schema::table($lines, function (Blueprint $t) {
                    $t->unsignedBigInteger('client_id')->nullable()->index();
                });
            }

            if (! Schema::hasColumn($lines, 'oauth_connection_id')) {
                Schema::table($lines, function (Blueprint $t) {
                    $t->unsignedBigInteger('oauth_connection_id')->nullable()->index();
                });
            }
        }

        $this->upOptOuts();

        $templates = $this->table('templates', 'sms_templates');

        if (Schema::hasTable($templates) && ! Schema::hasColumn($templates, 'client_id')) {
            Schema::table($templates, function (Blueprint $t) {
                $t->unsignedBigInteger('client_id')->nullable()->index();
            });
        }

        $campaigns = $this->table('campaigns', 'sms_campaigns');

        if (Schema::hasTable($campaigns) && ! Schema::hasColumn($campaigns, 'created_by_name')) {
            Schema::table($campaigns, function (Blueprint $t) {
                $t->string('created_by_name', 191)->nullable();
            });
        }

        // Not a messaging table, and not always installed: an application that
        // never published the OAuth framework has nothing to add a column to.
        if (Schema::hasTable('oauth_connections') && ! Schema::hasColumn('oauth_connections', 'client_id')) {
            Schema::table('oauth_connections', function (Blueprint $t) {
                $t->unsignedBigInteger('client_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('oauth_connections') && Schema::hasColumn('oauth_connections', 'client_id')) {
            $this->dropIndexedColumn('oauth_connections', 'client_id');
        }

        $campaigns = $this->table('campaigns', 'sms_campaigns');

        if (Schema::hasTable($campaigns) && Schema::hasColumn($campaigns, 'created_by_name')) {
            Schema::table($campaigns, function (Blueprint $t) {
                $t->dropColumn('created_by_name');
            });
        }

        $templates = $this->table('templates', 'sms_templates');

        if (Schema::hasTable($templates) && Schema::hasColumn($templates, 'client_id')) {
            $this->dropIndexedColumn($templates, 'client_id');
        }

        $this->downOptOuts();

        $lines = $this->table('lines', 'sms_lines');

        // One column per statement: SQLite rebuilds the table for each drop, and
        // a combined drop is where its grammar has historically tripped.
        foreach (['oauth_connection_id', 'client_id'] as $column) {
            if (Schema::hasTable($lines) && Schema::hasColumn($lines, $column)) {
                $this->dropIndexedColumn($lines, $column);
            }
        }
    }

    /**
     * Column first, then the old unique off, then the new one on - in that
     * order so there is never a moment the table has no unique key AND a column
     * the new one could not yet be built over.
     */
    private function upOptOuts(): void
    {
        $table = $this->table('opt_outs', 'sms_opt_outs');

        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'client_id')) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('client_id')->default(0);
            });
        }

        foreach ($this->uniqueIndexesOn($table, ['number']) as $name) {
            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropUnique($name);
            });
        }

        if ($this->uniqueIndexesOn($table, ['client_id', 'number']) === []) {
            Schema::table($table, function (Blueprint $t) {
                $t->unique(['client_id', 'number'], self::OPT_OUT_UNIQUE);
            });
        }
    }

    /**
     * Back to one row per number - but only when that is still TRUE of the
     * data. A number opted out of the house and of a client's line is two rows,
     * and the old unique could not be put back over them; the composite stays
     * and the column stays, rather than a down() that deletes somebody's
     * unsubscribe to make an index fit.
     */
    private function downOptOuts(): void
    {
        $table = $this->table('opt_outs', 'sms_opt_outs');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'client_id')) {
            return;
        }

        $duplicated = \Illuminate\Support\Facades\DB::table($table)
            ->select('number')
            ->groupBy('number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicated) {
            return;
        }

        foreach ($this->uniqueIndexesOn($table, ['client_id', 'number']) as $name) {
            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropUnique($name);
            });
        }

        if ($this->uniqueIndexesOn($table, ['number']) === []) {
            Schema::table($table, function (Blueprint $t) {
                $t->unique('number');
            });
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('client_id');
        });
    }

    /**
     * The names of every UNIQUE index (never the primary key) over exactly these
     * columns, in this order.
     *
     * Looked up rather than assumed: the old index's name is derived from the
     * table name, which is configurable, and an application that built the
     * table itself may have called it anything.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function uniqueIndexesOn(string $table, array $columns): array
    {
        $names = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) || ! ($index['unique'] ?? false)) {
                continue;
            }

            if (array_map('strtolower', $index['columns'] ?? []) === $columns) {
                $names[] = (string) $index['name'];
            }
        }

        return $names;
    }

    /**
     * Drop a column added with `->index()`, its index first - MySQL refuses
     * nothing here, but SQLite keeps an index naming a column it has just been
     * told to drop and then refuses the rebuild.
     */
    private function dropIndexedColumn(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) || ($index['columns'] ?? []) !== [$column]) {
                continue;
            }

            $name = (string) $index['name'];

            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropIndex($name);
            });
        }

        Schema::table($table, function (Blueprint $t) use ($column) {
            $t->dropColumn($column);
        });
    }

    private function table(string $key, string $default): string
    {
        return (string) config('visns-packages.messaging.tables.' . $key, $default);
    }
};
