<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person on one campaign's list, and what happened to them.
 *
 * This table IS the campaign's audit trail. Every row ends in one of four
 * states and says why:
 *
 *   pending  not yet attempted, or attempted and worth attempting again
 *   sent     handed to the transport, `message_id` and `thread_id` point at the
 *            ordinary inbox rows it produced
 *   failed   the transport refused it, or it ran out of retries - `error` says
 *   skipped  never attempted: opted out, or the campaign was cancelled
 *
 * `(campaign_id, status)` is the index the sender runs on - "the next pending
 * recipient of this campaign" is the only query it makes in its inner loop, and
 * without it every minute would scan the whole table.
 *
 * `(campaign_id, number)` is UNIQUE. The controller de-duplicates an imported
 * list before it writes anything, so this is a backstop rather than the
 * mechanism - but it is the backstop that matters, because a list pasted out of
 * a spreadsheet twice is the commonest way a client gets the same text twice.
 *
 * `extra` keeps every OTHER column of the imported row, keyed by its header, so
 * a body can say "{suburb}" or "{renewal_date}" without this package knowing
 * what either means. Unknown placeholders resolve to an empty string rather
 * than being left on screen - see Services\Sms\SmsCampaignRenderer.
 *
 * `retries` counts RETRYABLE transport failures only (a 429, a 5xx, a request
 * that never completed). A refused number is failed on the first answer; asking
 * Zoom thirty times whether a landline is still a landline is not a retry
 * policy.
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

        $campaigns = (string) config('visns-packages.messaging.tables.campaigns', 'sms_campaigns');

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('campaign_id')->index();

            // As imported. Null is allowed: a list of bare numbers is a list,
            // and "{first_name}" simply resolves to nothing for those rows.
            $t->string('name', 191)->nullable();

            // E.164, normalised and checked before the row is written - see
            // Support\PhoneNumber. A number that could not be read never
            // becomes a recipient; it is reported back to the person importing.
            $t->string('number', 32);

            // Every other imported column, keyed by header.
            $t->json('extra')->nullable();

            $t->string('status', 16)->default('pending');
            $t->text('error')->nullable();

            // Retryable transport failures so far. smallint: `max_retries`
            // defaults to 30 and anything approaching 65535 is a provider that
            // has been broken for six weeks.
            $t->unsignedSmallInteger('retries')->default(0);

            // The ordinary inbox rows this send produced. No foreign keys: a
            // message is never deleted by this module anyway, and a cascade
            // would rewrite the campaign's own history if one ever were.
            $t->unsignedBigInteger('message_id')->nullable();
            $t->unsignedBigInteger('thread_id')->nullable();

            $t->dateTime('sent_at')->nullable();

            $t->timestamps();

            // The sender's inner loop: the next pending recipient of one
            // campaign.
            $t->index(['campaign_id', 'status']);

            // The backstop against a list pasted twice.
            $t->unique(['campaign_id', 'number']);
        });

        if (! Schema::hasTable($campaigns)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($campaigns) {
            // Cascade here and only here: a recipient row is meaningless without
            // its campaign, and a deleted campaign is a draft nobody sent.
            $t->foreign('campaign_id')
                ->references('id')
                ->on($campaigns)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.campaign_recipients',
            'sms_campaign_recipients'
        );
    }
};
