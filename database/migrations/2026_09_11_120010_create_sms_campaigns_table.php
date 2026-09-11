<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One bulk send: a line, a body with placeholders, and a list of recipients.
 *
 * `body` holds the template EXACTLY as it was typed and WITHOUT the footer.
 * `footer` holds what will be appended, snapshotted from config at create time.
 * Keeping them apart is what lets the campaign screen show somebody their own
 * words back, while keeping the snapshot is what makes the record honest: a
 * config change next month must not rewrite what was actually sent last month.
 *
 * `status` is the campaign's whole life:
 *   draft      created, nothing sent, still editable and deletable
 *   sending    the scheduled command is working through it
 *   paused     stopped - by a person, or by the sender because the transport
 *              is not connected. Resumable; nothing is lost.
 *   completed  no pending recipients left
 *   cancelled  abandoned; the pending recipients were marked `skipped`
 *
 * A string rather than an enum for the same reason `sms_messages.direction` is
 * one: an enum change is a table rebuild in MySQL.
 *
 * `total` / `sent` / `failed` / `skipped` are DENORMALISED counters. A campaign
 * list showing progress for twenty campaigns would otherwise be twenty grouped
 * queries over a table with hundreds of thousands of rows in it. They are
 * written by the sender as it goes and recomputed from the recipients at the
 * end of every run (Models\SmsCampaign::syncCounts), which is cheap and keeps
 * them honest - a counter nothing ever reconciles is a counter that drifts.
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

        $lines = (string) config('visns-packages.messaging.tables.lines', 'sms_lines');

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            // The number every message in this campaign goes out from, chosen
            // when it is created and never changed: half a campaign sent from
            // one number and half from another is two conversations per client.
            $t->unsignedBigInteger('line_id')->index();

            // Who built it. Attribution on every message the campaign sends, and
            // the first question asked about one that should not have gone.
            $this->userKey($t, 'user_id')->nullable()->index();

            $t->string('name', 191);

            // The template. Placeholders are resolved per recipient by
            // Services\Sms\SmsCampaignRenderer; the footer is NOT in here.
            $t->text('body');

            // What will be appended, as config had it when this was created.
            $t->string('footer', 191)->nullable();

            $t->string('status', 16)->default('draft')->index();

            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('skipped')->default(0);

            // The last thing that went wrong, in words. What the screen shows
            // when a campaign paused itself, so somebody can tell "Zoom is not
            // connected" from "somebody pressed pause".
            $t->text('last_error')->nullable();

            // Stamped once, on the first start. A resume after a pause keeps the
            // original: "when did this campaign begin" has one answer.
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();

            $t->timestamps();
        });

        if (! Schema::hasTable($lines)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($lines) {
            $t->foreign('line_id')
                ->references('id')
                ->on($lines)
                ->cascadeOnDelete();
        });

        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            // nullOnDelete, never cascade: a staff member leaving must not take
            // the record of what the practice sent with them.
            $t->foreign('user_id')
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
            'visns-packages.messaging.tables.campaigns',
            'sms_campaigns'
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
