<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignSender;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;

/**
 * Bulk campaigns: the endpoints, the transitions and the sender.
 *
 * The sub-module is turned ON here, which MessagingCampaignDisabledTest below
 * is the counterweight to - the shipped default is off, and "off means no
 * routes" is the property that makes it safe to ship at all.
 */
class MessagingCampaignTest extends MessagingTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.messaging.bulk.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();
    }

    /**
     * The Zoom transport with a fake client bound in its place - the seam the
     * real transport resolves through, so nothing can reach a live tenant.
     */
    private function useZoom(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());
    }

    /**
     * The dev transport: reports `sent` and texts back. Used where a test needs
     * a send to succeed without pretending Zoom is involved.
     */
    private function useLog(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function campaign(array $recipients, array $overrides = []): SmsCampaign
    {
        $admin = $overrides['user'] ?? $this->admin();
        $line = $overrides['line'] ?? $this->line([$admin]);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', array_merge([
                'line_id' => $line->id,
                'name' => 'August review reminders',
                'body' => 'Hi {first_name}, your review is due.',
                'recipients' => $recipients,
            ], $overrides['payload'] ?? []))
            ->assertStatus(201);

        return SmsCampaign::query()->orderByDesc('id')->first();
    }

    private function sender(): SmsCampaignSender
    {
        return app(SmsCampaignSender::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The switch
    |--------------------------------------------------------------------------
    */

    public function test_the_endpoints_exist_while_the_sub_module_is_on(): void
    {
        $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns')
            ->assertOk()
            ->assertJsonPath('campaigns', [])
            ->assertJsonPath('settings.per_minute', 30)
            ->assertJsonPath('settings.max_recipients', 500)
            ->assertJsonPath('settings.footer', 'Reply STOP to opt out.')
            ->assertJsonPath('settings.footer_required', true)
            ->assertJsonPath('settings.max_body_length', 1600);
    }

    public function test_a_member_without_the_permission_is_refused(): void
    {
        // Gated in the route on the bulk permission, which falls back to manage.
        // "May run the inbox" and "may text every client at once" are different
        // risks, and the second one is not undoable.
        $this->actingAs($this->member())
            ->getJson(self::BASE . '/campaigns')
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Importing a list
    |--------------------------------------------------------------------------
    */

    public function test_a_list_is_imported_and_every_refusal_is_named(): void
    {
        $admin = $this->admin();
        $line = $this->line([$admin]);

        $this->app->make(SmsOptOuts::class)->record('+61411111111', SmsOptOut::SOURCE_MANUAL);

        $response = $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'August review reminders',
                'body' => 'Hi {first_name}, your review is due {renewal_date}.',
                'recipients' => [
                    ['name' => 'Cleo Client', 'number' => '0412 345 678', 'extra' => ['renewal_date' => '3 September']],
                    ['name' => 'Same Person Again', 'number' => '+61412345678'],
                    ['name' => 'Landline Larry', 'number' => '08 9375 2549'],
                    ['name' => 'Typo Terry', 'number' => 'n/a'],
                    ['name' => 'Gone Away', 'number' => '0411 111 111'],
                    ['name' => 'Second Good One', 'number' => '0498 765 432'],
                ],
            ])
            ->assertStatus(201);

        $response
            ->assertJsonPath('report.accepted', 2)
            ->assertJsonPath('report.duplicates', 1)
            ->assertJsonPath('report.opted_out.0.number', '+61411111111')
            ->assertJsonPath('report.opted_out.0.name', 'Gone Away')
            // The row number is 1-based, so it names the row a person is looking
            // at in their spreadsheet.
            ->assertJsonPath('report.invalid.0.row', 3)
            ->assertJsonPath('report.invalid.0.reason', 'not a mobile number')
            ->assertJsonPath('report.invalid.1.row', 4)
            ->assertJsonPath('report.invalid.1.reason', 'could not be read')
            // As TYPED, not normalised: it could not be normalised, and a
            // cleaned-up echo would be impossible to find in the spreadsheet.
            ->assertJsonPath('report.invalid.1.number', 'n/a')
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_DRAFT)
            ->assertJsonPath('campaign.counts.total', 2)
            ->assertJsonPath('campaign.counts.pending', 2)
            ->assertJsonPath('campaign.progress', 0)
            // Null unless it is actually sending: for a draft the honest answer
            // is "until somebody presses start".
            ->assertJsonPath('campaign.estimated_minutes_remaining', null)
            ->assertJsonPath('campaign.footer', 'Reply STOP to opt out.');

        $campaign = SmsCampaign::first();

        $this->assertSame(2, $campaign->recipients()->count());
        $this->assertSame(
            ['+61412345678', '+61498765432'],
            $campaign->recipients()->orderBy('id')->pluck('number')->all()
        );

        // The imported columns survive, keyed by header.
        $this->assertSame(
            ['renewal_date' => '3 September'],
            $campaign->recipients()->orderBy('id')->first()->extra
        );
    }

    public function test_a_row_with_no_name_keeps_its_place_in_the_list(): void
    {
        $admin = $this->admin();
        $line = $this->line([$admin]);

        // Laravel rebuilds `validated()` rule by rule, so a row missing an
        // OPTIONAL key - a bare number with no name, which is most of a pasted
        // column - comes back APPENDED after the rows that have one. Reading the
        // rows off the validated array would therefore report this bad number as
        // row 2 when it is row 3, and point somebody at the wrong line of their
        // spreadsheet. See SmsCampaignController::recipientRows().
        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Order matters',
                'body' => 'Hello',
                'recipients' => [
                    ['name' => 'Cleo', 'number' => '0412 345 671'],
                    ['number' => '0412 345 672'],
                    ['name' => 'Typo Terry', 'number' => 'n/a'],
                    ['number' => '0412 345 673'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('report.invalid.0.row', 3)
            ->assertJsonPath('report.invalid.0.name', 'Typo Terry');

        // And the accepted rows are stored in the order they were pasted.
        $this->assertSame(
            ['+61412345671', '+61412345672', '+61412345673'],
            SmsCampaignRecipient::query()->orderBy('id')->pluck('number')->all()
        );
    }

    public function test_an_opted_out_number_is_not_stored_as_a_recipient_at_all(): void
    {
        $admin = $this->admin();
        $line = $this->line([$admin]);

        $this->app->make(SmsOptOuts::class)->record('+61411111111', SmsOptOut::SOURCE_MANUAL);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Reminders',
                'body' => 'Hello',
                'recipients' => [
                    ['name' => 'Gone Away', 'number' => '0411 111 111'],
                    ['name' => 'Cleo', 'number' => '0412 345 678'],
                ],
            ])
            ->assertStatus(201);

        // A skipped row on the list would look, on the campaign screen, exactly
        // like somebody the practice intended to text - and the whole point is
        // that it did not.
        $this->assertSame(1, SmsCampaignRecipient::count());
        $this->assertSame('+61412345678', SmsCampaignRecipient::first()->number);
    }

    public function test_a_list_with_nothing_usable_on_it_is_refused(): void
    {
        $admin = $this->admin();
        $line = $this->line([$admin]);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Reminders',
                'body' => 'Hello',
                'recipients' => [['number' => 'n/a'], ['number' => '08 9375 2549']],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'None of those rows can be texted — every number was unreadable, a duplicate, or already opted out.'
            );

        $this->assertSame(0, SmsCampaign::count());
    }

    public function test_the_cap_is_refused_and_names_itself(): void
    {
        $this->app['config']->set('visns-packages.messaging.bulk.max_recipients', 3);

        $admin = $this->admin();
        $line = $this->line([$admin]);

        $recipients = [];

        for ($i = 0; $i < 5; $i++) {
            $recipients[] = ['number' => '+6141234567' . $i];
        }

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Too many',
                'body' => 'Hello',
                'recipients' => $recipients,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'That list has 5 usable numbers on it and a campaign takes at most 3. Split it.'
            );

        // Nothing is written until the whole list has been judged.
        $this->assertSame(0, SmsCampaign::count());
        $this->assertSame(0, SmsCampaignRecipient::count());
    }

    public function test_an_inactive_line_is_refused(): void
    {
        $admin = $this->admin();
        $line = $this->line([$admin], ['active' => false]);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Reminders',
                'body' => 'Hello',
                'recipients' => [['number' => '0412 345 678']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That line is not active, so nothing can be sent from it.');
    }

    public function test_the_body_cap_leaves_room_for_the_footer(): void
    {
        $admin = $this->admin();
        $line = $this->line([$admin]);

        // The footer is 22 characters, so a campaign body may be
        // 1600 - (22 + 1) = 1577. A body typed right up to the module's limit
        // would be over it the moment the footer went on, and the refusal would
        // otherwise arrive from the transport, one recipient at a time, after
        // the campaign had started.
        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Long',
                'body' => str_repeat('a', 1578),
                'recipients' => [['number' => '0412 345 678']],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Long',
                'body' => str_repeat('a', 1577),
                'recipients' => [['number' => '0412 345 678']],
            ])
            ->assertStatus(201);
    }

    /*
    |--------------------------------------------------------------------------
    | The preview
    |--------------------------------------------------------------------------
    */

    public function test_the_preview_renders_the_first_three_exactly_as_the_sender_would(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/campaigns/preview', [
                'body' => 'Hi {first_name} {last_name}, your review is due {renewal_date}. {unknown}',
                'recipients' => [
                    ['name' => 'Cleo Client', 'number' => '0412 345 678', 'extra' => ['Renewal Date' => '3 September']],
                    ['name' => 'Solo', 'number' => '0498 765 432'],
                    ['number' => '0455 555 555'],
                    ['name' => 'Fourth', 'number' => '0466 666 666'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'previews')
            // The header is matched case-insensitively and with spaces folded to
            // underscores: somebody typing a placeholder is reading it off a
            // spreadsheet, not reproducing its capitalisation.
            ->assertJsonPath(
                'previews.0.body',
                // rtrim'd before the newline: a footer on its own line should
                // not be preceded by whatever whitespace an unfilled
                // placeholder left behind.
                "Hi Cleo Client, your review is due 3 September.\nReply STOP to opt out."
            )
            ->assertJsonPath('previews.0.number', '+61412345678')
            ->assertJsonPath('previews.0.segments', 1)
            // An unknown placeholder resolves to nothing rather than being left
            // on screen: "Hi , your review" reads as a typo, "Hi {frist_name},"
            // reads as an organisation that does not know what it is doing.
            ->assertJsonPath('previews.1.body', "Hi Solo , your review is due .\nReply STOP to opt out.")
            ->assertJsonPath('previews.2.name', null);
    }

    public function test_the_preview_counts_segments_the_way_the_carrier_will(): void
    {
        $body = "It%ss time for your annual review, {first_name}. Please call to book.";
        $recipients = [['name' => 'Cleo', 'number' => '0412 345 678']];

        // The same sentence, one character apart. This is what the preview is
        // FOR: the curly apostrophe that Word and every phone keyboard produce
        // takes the whole message to UCS-2, and nobody has to know why.
        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/campaigns/preview', [
                'body' => sprintf($body, "'"),
                'recipients' => $recipients,
            ])
            ->assertOk()
            ->assertJsonPath('previews.0.segments', 1);

        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/campaigns/preview', [
                'body' => sprintf($body, "\u{2019}"),
                'recipients' => $recipients,
            ])
            ->assertOk()
            ->assertJsonPath('previews.0.segments', 2);
    }

    public function test_a_body_that_already_says_it_is_not_given_the_footer_twice(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/campaigns/preview', [
                'body' => 'Your review is due. reply stop to opt out.',
                'recipients' => [['name' => 'Cleo', 'number' => '0412 345 678']],
            ])
            ->assertOk()
            ->assertJsonPath('previews.0.body', 'Your review is due. reply stop to opt out.');
    }

    public function test_footer_required_false_leaves_the_body_alone(): void
    {
        $this->app['config']->set('visns-packages.messaging.bulk.footer_required', false);

        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/campaigns/preview', [
                'body' => 'Your appointment is Thursday.',
                'recipients' => [['name' => 'Cleo', 'number' => '0412 345 678']],
            ])
            ->assertOk()
            ->assertJsonPath('previews.0.body', 'Your appointment is Thursday.');
    }

    /*
    |--------------------------------------------------------------------------
    | Transitions
    |--------------------------------------------------------------------------
    */

    public function test_start_pause_and_resume(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/start')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SENDING);

        $startedAt = $campaign->fresh()->started_at;

        $this->assertNotNull($startedAt);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/pause')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_PAUSED);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/start')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SENDING);

        // Stamped once. "When did this campaign begin" has one answer, and a
        // screen showing the time somebody pressed resume would be answering a
        // question nobody asked.
        $this->assertEquals($startedAt, $campaign->fresh()->started_at);
    }

    public function test_an_illegal_transition_is_refused_with_a_sentence(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        // A draft is not sending, so it cannot be paused.
        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/pause')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A draft campaign cannot be paused.');

        $campaign->forceFill(['status' => SmsCampaign::STATUS_COMPLETED])->save();

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/start')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A completed campaign cannot be started.');
    }

    public function test_cancelling_skips_what_is_left_and_keeps_it_on_the_record(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Cleo', 'number' => '0412 345 678'],
            ['name' => 'Sam', 'number' => '0498 765 432'],
        ], ['user' => $admin]);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/cancel')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_CANCELLED)
            ->assertJsonPath('campaign.counts.skipped', 2)
            ->assertJsonPath('campaign.counts.pending', 0);

        // Skipped, never deleted: "we cancelled before these went out" is a fact
        // somebody will need, and a row that vanished cannot state it.
        $this->assertSame(2, SmsCampaignRecipient::query()
            ->where('status', SmsCampaignRecipient::STATUS_SKIPPED)
            ->where('error', SmsCampaignRecipient::ERROR_CANCELLED)
            ->count());
    }

    public function test_retry_failed_requeues_only_the_failures(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Cleo', 'number' => '0412 345 678'],
            ['name' => 'Sam', 'number' => '0498 765 432'],
        ], ['user' => $admin]);

        $recipients = $campaign->recipients()->orderBy('id')->get();

        $recipients[0]->forceFill([
            'status' => SmsCampaignRecipient::STATUS_FAILED,
            'error' => 'Zoom refused it',
            'retries' => 7,
        ])->save();

        $recipients[1]->forceFill([
            'status' => SmsCampaignRecipient::STATUS_SKIPPED,
            'error' => SmsCampaignRecipient::ERROR_OPTED_OUT,
        ])->save();

        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/retry-failed')
            ->assertOk()
            ->assertJsonPath('requeued', 1)
            // A campaign holding pending rows while claiming to be finished
            // would simply never be picked up again.
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SENDING)
            ->assertJsonPath('campaign.completed_at', null);

        $first = $recipients[0]->fresh();

        $this->assertSame(SmsCampaignRecipient::STATUS_PENDING, $first->status);
        // A retry budget is spent against one episode of trouble, and this is a
        // person deciding the trouble is over.
        $this->assertSame(0, (int) $first->retries);
        $this->assertNull($first->error);

        // The opted-out row is NOT requeued: re-queueing it because somebody
        // pressed "retry failed" is exactly the accident the register exists to
        // prevent.
        $this->assertSame(SmsCampaignRecipient::STATUS_SKIPPED, $recipients[1]->fresh()->status);
    }

    public function test_only_a_draft_can_be_deleted(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING])->save();

        $this->actingAs($admin)
            ->deleteJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertStatus(422);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_DRAFT])->save();

        $this->actingAs($admin)
            ->deleteJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertNoContent();

        $this->assertSame(0, SmsCampaign::count());
        $this->assertSame(0, SmsCampaignRecipient::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Reading one
    |--------------------------------------------------------------------------
    */

    public function test_the_recipients_are_paged_and_filterable_by_status(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Cleo', 'number' => '0412 345 678'],
            ['name' => 'Sam', 'number' => '0498 765 432'],
        ], ['user' => $admin]);

        $campaign->recipients()->orderBy('id')->first()
            ->forceFill(['status' => SmsCampaignRecipient::STATUS_FAILED, 'error' => 'Refused'])
            ->save();

        $this->actingAs($admin)
            ->getJson(self::BASE . '/campaigns/' . $campaign->id . '/recipients')
            ->assertOk()
            ->assertJsonCount(2, 'recipients')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('recipients.0.display_number', '0412 345 678');

        $this->actingAs($admin)
            ->getJson(self::BASE . '/campaigns/' . $campaign->id . '/recipients?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.error', 'Refused');

        // An unknown status matches nothing, which is the right answer for a
        // filter and would break a bookmarked link if it were a 422.
        $this->actingAs($admin)
            ->getJson(self::BASE . '/campaigns/' . $campaign->id . '/recipients?status=nonsense')
            ->assertOk()
            ->assertJsonCount(0, 'recipients');
    }

    public function test_an_unknown_campaign_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/9999')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | The sender
    |--------------------------------------------------------------------------
    */

    public function test_a_run_spends_its_budget_and_stops(): void
    {
        $this->useLog();

        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'One', 'number' => '0412 345 671'],
            ['name' => 'Two', 'number' => '0412 345 672'],
            ['name' => 'Three', 'number' => '0412 345 673'],
        ], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $counts = $this->sender()->run(2);

        $this->assertFalse($counts['locked']);
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(1, $counts['campaigns']);

        $campaign->refresh();

        $this->assertSame(2, (int) $campaign->sent);
        // Still sending: a budget is a ceiling on damage, not the end of the
        // list.
        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->status);
        $this->assertSame(1, $campaign->pendingCount());
    }

    public function test_a_sent_recipient_carries_its_message_and_thread(): void
    {
        $this->useLog();

        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo Client', 'number' => '0412 345 678']], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->sender()->run(10);

        $recipient = $campaign->recipients()->first();

        $this->assertSame(SmsCampaignRecipient::STATUS_SENT, $recipient->status);
        $this->assertNotNull($recipient->message_id);
        $this->assertNotNull($recipient->thread_id);
        $this->assertNotNull($recipient->sent_at);

        $message = SmsMessage::find($recipient->message_id);

        // The placeholders were resolved and the footer appended - by the same
        // renderer the preview used.
        $this->assertStringContainsString('Hi Cleo,', (string) $message->body);
        $this->assertStringContainsString('Reply STOP to opt out.', (string) $message->body);
        // Attributed to whoever built the campaign.
        $this->assertSame($admin->id, (int) $message->user_id);

        // Completed: nothing pending left.
        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->completed_at);
    }

    public function test_a_campaign_thread_is_archived_until_the_client_replies(): void
    {
        // Zoom (faked), not the dev transport: that one TEXTS BACK, so the
        // thread would have an inbound message by the time the archive was
        // considered - which is the correct behaviour and the wrong fixture.
        $this->useZoom();

        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->sender()->run(10);

        $thread = SmsThread::query()->where('external_number', '+61412345678')->first();

        $this->assertNotNull($thread);
        $this->assertNotNull($thread->archived_at);

        // And the bargain: a reply brings it straight back.
        app(\Visnsstudio\VisnsPackages\Services\Sms\SmsService::class)
            ->recordInbound($thread, 'Thanks, see you then');

        $this->assertNull($thread->fresh()->archived_at);
    }

    public function test_an_existing_conversation_is_never_archived_by_a_campaign(): void
    {
        // Faked Zoom rather than the dev transport, whose auto-reply would make
        // this pass whether or not the guard existed.
        $this->useZoom();

        $admin = $this->admin();
        $line = $this->line([$admin]);
        $thread = $this->thread($line, '+61412345678');

        // Somebody the practice has been texting all week, who happens to be on
        // the list. Archiving it would hide a live exchange as a side effect of
        // a mail-out.
        app(\Visnsstudio\VisnsPackages\Services\Sms\SmsService::class)
            ->recordInbound($thread, 'Morning');

        $campaign = $this->campaign(
            [['name' => 'Cleo', 'number' => '0412 345 678']],
            ['user' => $admin, 'line' => $line]
        );

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->sender()->run(10);

        $this->assertNull($thread->fresh()->archived_at);
    }

    public function test_archive_threads_false_leaves_the_thread_alone(): void
    {
        $this->useZoom();
        $this->app['config']->set('visns-packages.messaging.bulk.archive_threads', false);

        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->sender()->run(10);

        $thread = SmsThread::query()->where('external_number', '+61412345678')->first();

        $this->assertNull($thread->archived_at);
    }

    public function test_a_number_that_opted_out_mid_campaign_is_skipped_and_costs_no_budget(): void
    {
        $this->useLog();

        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Gone', 'number' => '0411 111 111'],
            ['name' => 'Cleo', 'number' => '0412 345 678'],
        ], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        // Arrived AFTER the import, which is why the sender checks as well as
        // the controller.
        $this->app->make(SmsOptOuts::class)->record('+61411111111', SmsOptOut::SOURCE_KEYWORD);

        // A budget of one. The skip must not spend it, or the campaign would
        // look stuck.
        $counts = $this->sender()->run(1);

        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(1, $counts['sent']);

        $skipped = $campaign->recipients()->where('number', '+61411111111')->first();

        $this->assertSame(SmsCampaignRecipient::STATUS_SKIPPED, $skipped->status);
        $this->assertSame(SmsCampaignRecipient::ERROR_OPTED_OUT, $skipped->error);

        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_a_not_connected_transport_pauses_the_campaign(): void
    {
        // The shipped production default while the SMS number is being
        // provisioned. Nothing was tried, so nothing is marked failed.
        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Cleo', 'number' => '0412 345 678'],
            ['name' => 'Sam', 'number' => '0498 765 432'],
        ], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $counts = $this->sender()->run(10);

        $campaign->refresh();

        $this->assertSame(SmsCampaign::STATUS_PAUSED, $campaign->status);
        $this->assertSame(SmsCampaignSender::NOT_CONNECTED, $campaign->last_error);
        $this->assertSame(0, $counts['sent']);
        $this->assertSame(0, $counts['failed']);
        // Both still pending; the one that was attempted is not marked failed,
        // because it was never tried against anything.
        $this->assertSame(2, $campaign->pendingCount());
    }

    public function test_a_retryable_failure_leaves_the_recipient_pending_and_stops_the_run(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
        ];

        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Cleo', 'number' => '0412 345 678'],
            ['name' => 'Sam', 'number' => '0498 765 432'],
        ], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $counts = $this->sender()->run(10);

        $this->assertSame(1, $counts['retry_wait']);
        $this->assertSame(0, $counts['sent']);
        $this->assertSame(0, $counts['failed']);

        // ONE attempt, then the run stopped: carrying on would be making the
        // same request to the same rate-limited account.
        $this->assertCount(1, FakeZoomSmsClient::$sends);

        $recipient = $campaign->recipients()->orderBy('id')->first();

        $this->assertSame(SmsCampaignRecipient::STATUS_PENDING, $recipient->status);
        $this->assertSame(1, (int) $recipient->retries);
        $this->assertSame('Too many requests', $recipient->error);

        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame('Too many requests', $campaign->fresh()->last_error);
    }

    public function test_a_server_error_and_an_unreachable_provider_are_both_retryable(): void
    {
        $this->useZoom();

        foreach ([['http_code' => 503], ['http_code' => 0]] as $shape) {
            FakeZoomSmsClient::$sends = [];
            FakeZoomSmsClient::$response = [
                'success' => false,
                'http_code' => $shape['http_code'],
                'data' => ['message' => 'Nope'],
            ];

            $admin = $this->admin();
            $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

            $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

            $counts = $this->sender()->run(10);

            $this->assertSame(1, $counts['retry_wait'], 'HTTP ' . $shape['http_code']);
            $this->assertSame(
                SmsCampaignRecipient::STATUS_PENDING,
                $campaign->recipients()->first()->status
            );

            $campaign->forceFill(['status' => SmsCampaign::STATUS_CANCELLED])->save();
        }
    }

    public function test_a_refusal_that_is_not_retryable_fails_the_recipient_and_carries_on(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 400,
            'data' => ['message' => 'Invalid recipient'],
        ];

        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'Cleo', 'number' => '0412 345 678'],
            ['name' => 'Sam', 'number' => '0498 765 432'],
        ], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $counts = $this->sender()->run(10);

        // A 4xx is exactly as true in a minute's time; retrying it would keep
        // the whole campaign stuck behind one bad row.
        $this->assertSame(2, $counts['failed']);
        $this->assertSame(0, $counts['retry_wait']);
        $this->assertCount(2, FakeZoomSmsClient::$sends);

        $campaign->refresh();

        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame(2, (int) $campaign->failed);
        $this->assertSame(
            'Invalid recipient',
            $campaign->recipients()->first()->error
        );
    }

    public function test_a_recipient_out_of_retries_becomes_a_failure(): void
    {
        $this->useZoom();
        $this->app['config']->set('visns-packages.messaging.bulk.max_retries', 2);

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
        ];

        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->sender()->run(10);
        $this->assertSame(SmsCampaignRecipient::STATUS_PENDING, $campaign->recipients()->first()->status);

        $this->sender()->run(10);

        $recipient = $campaign->recipients()->first();

        $this->assertSame(SmsCampaignRecipient::STATUS_FAILED, $recipient->status);
        $this->assertSame(2, (int) $recipient->retries);
        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_a_deleted_line_pauses_rather_than_texting_from_somewhere_else(): void
    {
        $this->useLog();

        $admin = $this->admin();
        $line = $this->line([$admin]);
        $other = $this->line([$admin]);

        $campaign = $this->campaign(
            [['name' => 'Cleo', 'number' => '0412 345 678']],
            ['user' => $admin, 'line' => $line]
        );

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $line->delete();

        $counts = $this->sender()->run(10);

        $this->assertSame(0, $counts['sent']);

        $campaign->refresh();

        // A client receiving this from a number they have never seen, in reply
        // to nothing, is worse than a campaign that stopped and said why.
        $this->assertSame(SmsCampaign::STATUS_PAUSED, $campaign->status);
        $this->assertStringContainsString('no longer exists', (string) $campaign->last_error);
        $this->assertSame(0, SmsThread::query()->where('line_id', $other->id)->count());
    }

    public function test_only_sending_campaigns_are_touched(): void
    {
        $this->useLog();

        $admin = $this->admin();

        // Draft, never started.
        $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $counts = $this->sender()->run(10);

        $this->assertSame(0, $counts['campaigns']);
        $this->assertSame(0, $counts['sent']);
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_a_second_run_cannot_start_while_one_holds_the_lock(): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock(
            SmsCampaignSender::LOCK,
            SmsCampaignSender::LOCK_SECONDS
        );

        $this->assertTrue($lock->get());

        try {
            // Two runs at once would both read the same pending recipient and
            // both send to them - the one failure mode a client actually
            // notices.
            $counts = $this->sender()->run(10);

            $this->assertTrue($counts['locked']);
            $this->assertSame(0, $counts['sent']);
        } finally {
            $lock->release();
        }
    }

    public function test_the_progress_figure_never_reads_100_before_it_is_done(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'A', 'number' => '0412 345 671'],
            ['name' => 'B', 'number' => '0412 345 672'],
            ['name' => 'C', 'number' => '0412 345 673'],
        ], ['user' => $admin]);

        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_SENDING,
            'started_at' => now(),
            'sent' => 2,
        ])->save();

        $this->actingAs($admin)
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->assertJsonPath('campaign.progress', 66)
            ->assertJsonPath('campaign.counts.pending', 1)
            // pending / per_minute, rounded up.
            ->assertJsonPath('campaign.estimated_minutes_remaining', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | The command
    |--------------------------------------------------------------------------
    */

    public function test_the_command_runs_and_prints_its_counts(): void
    {
        $this->useLog();

        $admin = $this->admin();
        $campaign = $this->campaign([['name' => 'Cleo', 'number' => '0412 345 678']], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->artisan('sms:send-campaigns')
            ->expectsOutputToContain('Campaigns 1 · sent 1 · failed 0 · skipped 0')
            ->assertSuccessful();

        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_the_command_budget_option_overrides_config(): void
    {
        $this->useLog();

        $admin = $this->admin();
        $campaign = $this->campaign([
            ['name' => 'A', 'number' => '0412 345 671'],
            ['name' => 'B', 'number' => '0412 345 672'],
        ], ['user' => $admin]);

        $campaign->forceFill(['status' => SmsCampaign::STATUS_SENDING, 'started_at' => now()])->save();

        $this->artisan('sms:send-campaigns', ['--budget' => 1])->assertSuccessful();

        $this->assertSame(1, (int) $campaign->fresh()->sent);
        $this->assertSame(1, $campaign->pendingCount());
    }

    public function test_the_command_refuses_a_budget_of_zero(): void
    {
        // "--budget=0" reads as "send nothing" to whoever typed it, and quietly
        // sending thirty would be a surprise nobody could explain.
        $this->artisan('sms:send-campaigns', ['--budget' => 0])->assertFailed();
    }
}
