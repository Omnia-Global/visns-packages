<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email campaign module's five tables (Resend Broadcasts).
 *
 * No foreign keys, the messaging module's rule: a campaign is evidence of what
 * was sent to whom, and a cascade from a deleted list or user would take that
 * history with it. Every table is `hasTable`-guarded so a re-publish is safe.
 */
return new class extends Migration {
    public function up(): void
    {
        $lists = $this->table('lists', 'email_lists');
        $members = $this->table('list_members', 'email_list_members');
        $campaigns = $this->table('campaigns', 'email_campaigns');
        $events = $this->table('events', 'email_campaign_events');
        $templates = $this->table('templates', 'email_templates');

        if (! Schema::hasTable($lists)) {
            Schema::create($lists, function (Blueprint $t) {
                $t->id();
                $t->string('name', 191);
                $t->string('description', 500)->nullable();

                // `crm` (a contact-source filter, re-read on every refresh) or
                // `import` (a CSV, fixed at upload).
                $t->string('kind', 16)->default('crm');
                $t->json('filters')->nullable();

                // The Resend segment this list is mirrored into. Null until the
                // first sync makes it.
                $t->string('resend_segment_id', 64)->nullable();
                $t->timestamp('synced_at')->nullable();
                $t->string('sync_error', 500)->nullable();

                $this->userKey($t, 'user_id')->nullable()->index();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable($members)) {
            Schema::create($members, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('list_id')->index();

                // Lower-cased. Resend identifies a contact by email, so two rows
                // for "Kim@x" and "kim@x" would be one person twice.
                $t->string('email', 191);
                $t->string('first_name', 100)->nullable();
                $t->string('last_name', 100)->nullable();
                $t->string('company', 191)->nullable();

                // The host's own id for this person, where there is one: what a
                // CRM filter re-reads by, and how a report links back to them.
                $t->string('contact_key', 64)->nullable()->index();

                // pending -> synced; `removing` -> gone from the segment, then the
                // row is deleted; `failed` carries its reason.
                $t->string('status', 16)->default('pending')->index();
                $t->timestamp('synced_at')->nullable();
                $t->string('error', 500)->nullable();

                // Mirrored from Resend (contact.updated). Resend enforces it; this
                // is so the screen can say so.
                $t->timestamp('unsubscribed_at')->nullable();

                $t->timestamps();

                $t->unique(['list_id', 'email']);
                $t->index('email');
            });
        }

        if (! Schema::hasTable($campaigns)) {
            Schema::create($campaigns, function (Blueprint $t) {
                $t->id();
                $t->string('name', 191);
                $t->string('subject', 255)->nullable();
                $t->string('preview_text', 255)->nullable();
                $t->string('from_name', 100)->nullable();
                $t->string('from_email', 191)->nullable();
                $t->string('reply_to', 191)->nullable();
                $t->unsignedBigInteger('list_id')->nullable()->index();

                // The editor's blocks, and the HTML they rendered to AT SEND: the
                // record of what went out, which later edits to the renderer or
                // the brand must not rewrite.
                $t->json('content')->nullable();
                $t->longText('html')->nullable();

                // draft | scheduled | sending | sent | cancelled | failed
                $t->string('status', 16)->default('draft')->index();
                $t->timestamp('scheduled_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->string('resend_broadcast_id', 64)->nullable()->unique();
                $t->unsignedInteger('recipient_count')->nullable();
                $t->string('error', 500)->nullable();

                $this->userKey($t, 'user_id')->nullable()->index();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable($events)) {
            Schema::create($events, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->nullable()->index();
                $t->string('broadcast_id', 64)->nullable()->index();
                $t->string('email_id', 64)->nullable();

                // delivered | opened | clicked | bounced | complained |
                // delivery_delayed | failed | suppressed | sent
                $t->string('type', 32)->index();
                $t->string('email', 191)->nullable()->index();
                $t->string('link', 2000)->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 500)->nullable();
                $t->timestamp('occurred_at')->nullable();

                // Svix delivers at-least-once; this is how a retry is not a
                // second open.
                $t->string('webhook_id', 100)->nullable()->unique();

                $t->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable($templates)) {
            Schema::create($templates, function (Blueprint $t) {
                $t->id();
                $t->string('name', 191);
                $t->json('content');
                $this->userKey($t, 'user_id')->nullable()->index();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['templates', 'email_templates'],
            ['events', 'email_campaign_events'],
            ['campaigns', 'email_campaigns'],
            ['list_members', 'email_list_members'],
            ['lists', 'email_lists'],
        ] as [$key, $default]) {
            Schema::dropIfExists($this->table($key, $default));
        }
    }

    private function table(string $key, string $default): string
    {
        return (string) config('visns-packages.email_campaigns.tables.' . $key, $default);
    }

    /** A column able to reference users.id whatever width the host's users table uses. */
    private function userKey(Blueprint $t, string $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        $big = true;

        if (Schema::hasTable('users')) {
            $type = strtolower((string) Schema::getColumnType('users', 'id'));
            $big = ! in_array($type, ['integer', 'int', 'mediumint', 'smallint'], true);
        }

        return $big ? $t->unsignedBigInteger($column) : $t->unsignedInteger($column);
    }
};
