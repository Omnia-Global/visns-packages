<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a scheduled campaign is to start (4.24.0).
 *
 * A campaign in the `scheduled` status waits for this time and is then moved to
 * `sending` by the sender's next run (Models\SmsCampaign::promoteDue). NULL on
 * every campaign that was never scheduled. Kept once the campaign has started -
 * it is the record of what was asked for, and `started_at` says when it
 * actually began - and cleared only by unscheduling or by starting it by hand.
 *
 * Stored in the application's clock like every other timestamp here; the
 * browser's wall-clock time is converted at the edge (Support\SmsCampaignSchedule).
 *
 * Read and written only when the column exists (SchemaColumns), so the package
 * runs unchanged between an update and this migration. Idempotent and guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'scheduled_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dateTime('scheduled_at')->nullable();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'scheduled_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('scheduled_at');
        });
    }

    private function table(): string
    {
        return (string) config('visns-packages.messaging.tables.campaigns', 'sms_campaigns');
    }
};
