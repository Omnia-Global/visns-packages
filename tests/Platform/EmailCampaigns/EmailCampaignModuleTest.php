<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\EmailCampaigns;

use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailCampaignEvent;
use Visnsstudio\VisnsPackages\Models\EmailList;
use Visnsstudio\VisnsPackages\Models\EmailListMember;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailCampaignRenderer;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailListSync;
use Visnsstudio\VisnsPackages\Tests\Fixtures\EmailCampaigns\FakeContactSource;

class EmailCampaignModuleTest extends EmailCampaignTestCase
{
    private function crmList(array $groups = ['c1', 'c2'], array $options = []): EmailList
    {
        $response = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', [
            'name' => 'Clients',
            'kind' => 'crm',
            'groups' => $groups,
            'options' => $options,
        ])->assertCreated();

        return EmailList::findOrFail($response->json('list.id'));
    }

    private function sync(): array
    {
        return app(EmailListSync::class)->sleepUsing(fn () => null)->tick();
    }

    private function readyCampaign(array $overrides = []): EmailCampaign
    {
        $list = $this->crmList();
        $this->sync();

        return EmailCampaign::create(array_merge([
            'name' => 'September news',
            'subject' => 'What is new, {first_name}',
            'preview_text' => 'Three things worth knowing',
            'from_name' => 'Omnia Global',
            'from_email' => 'news@omnia.test',
            'list_id' => $list->id,
            'content' => [
                ['type' => 'heading', 'text' => 'Hello {first_name}'],
                ['type' => 'text', 'html' => '<p>Welcome to <strong>{company}</strong>.</p>'],
                ['type' => 'button', 'label' => 'Read more', 'url' => 'https://omnia.test/news'],
            ],
        ], $overrides));
    }

    /* -- lists ------------------------------------------------------------ */

    public function test_a_crm_list_takes_everyone_with_a_valid_email_once(): void
    {
        $list = $this->crmList();

        $emails = $list->members()->pluck('email')->sort()->values()->all();
        $this->assertSame(['kim@acura.test', 'lee@acura.test', 'sam@bethesda.test'], $emails);
        $this->assertSame(3, $list->members()->where('status', 'pending')->count());
    }

    public function test_a_refresh_adds_the_new_and_takes_off_the_gone(): void
    {
        $list = $this->crmList(['c1']);
        $this->sync();

        FakeContactSource::$people['c1'][1]['email'] = 'lee.new@acura.test';

        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $list->id . '/refresh')
            ->assertOk()
            ->assertJsonPath('counts.added', 1)
            ->assertJsonPath('counts.removed', 1);

        $this->assertSame('removing', EmailListMember::where('email', 'lee@acura.test')->value('status'));

        $this->sync();

        $this->assertNull(EmailListMember::where('email', 'lee@acura.test')->first());
        $this->assertContains('DELETE contacts/lee@acura.test/segments/' . $list->fresh()->resend_segment_id, $this->calls);
    }

    public function test_an_option_narrows_the_list(): void
    {
        $list = $this->crmList(['c1', 'c2'], ['primary_only' => true]);

        $this->assertSame(['kim@acura.test', 'sam@bethesda.test'], $list->members()->orderBy('email')->pluck('email')->all());
    }

    public function test_an_import_reports_every_row_it_skipped(): void
    {
        $response = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', [
            'name' => 'Expo leads',
            'kind' => 'import',
            'rows' => [
                ['email' => 'a@x.test', 'first_name' => 'Ann'],
                ['email' => 'nope'],
                ['email' => 'A@X.test'],
            ],
        ])->assertCreated();

        $this->assertSame(1, $response->json('counts.total'));
        $this->assertCount(2, $response->json('counts.rejected'));
        $this->assertStringContainsString('2 rows skipped', $response->json('message'));
    }

    /* -- sync ------------------------------------------------------------- */

    public function test_the_sync_makes_the_segment_and_creates_or_updates_each_contact(): void
    {
        $this->contacts['sam@bethesda.test'] = ['email' => 'sam@bethesda.test'];
        $list = $this->crmList();

        $report = $this->sync();

        $list->refresh();
        $this->assertNotNull($list->resend_segment_id);
        $this->assertSame(3, $report['synced']);
        $this->assertSame(0, $list->members()->where('status', '!=', 'synced')->count());
        $this->assertNotNull($list->synced_at);

        // New people are created INTO the segment; an existing one is updated and added.
        $this->assertSame([['id' => $list->resend_segment_id]], $this->contacts['kim@acura.test']['segments']);
        $this->assertSame(['company' => 'Acura'], $this->contacts['kim@acura.test']['properties']);
        $this->assertContains('POST contacts/sam@bethesda.test/segments/' . $list->resend_segment_id, $this->calls);

        // Never writes Resend's own unsubscribe flag.
        foreach ($this->contacts as $contact) {
            $this->assertArrayNotHasKey('unsubscribed', $contact);
        }
    }

    public function test_a_429_ends_the_tick_and_fails_nobody(): void
    {
        $list = $this->crmList();
        $this->throttleAfter = 3;

        $report = $this->sync();

        $this->assertTrue($report['throttled']);
        $this->assertSame(0, $list->members()->where('status', 'failed')->count());
        $this->assertGreaterThan(0, $list->members()->where('status', 'pending')->count());
    }

    public function test_a_refused_contact_is_failed_with_resends_sentence(): void
    {
        $list = $this->crmList();
        $list->forceFill(['resend_segment_id' => 'seg_x'])->save();
        $this->failWith = 422;

        $this->sync();

        $this->assertSame(3, $list->members()->where('status', 'failed')->count());
        $this->assertSame('Resend refused it.', $list->members()->value('error'));
    }

    /* -- rendering -------------------------------------------------------- */

    public function test_the_renderer_cleans_rich_text_and_always_adds_the_footer(): void
    {
        $html = app(EmailCampaignRenderer::class)->render([
            ['type' => 'text', 'html' => '<p onclick="x()">Hi<script>alert(1)</script> <a href="javascript:alert(1)">bad</a> <a href="https://ok.test">good</a></p><style>p{}</style>'],
            ['type' => 'image', 'url' => 'javascript:alert(1)'],
            ['type' => 'nonsense', 'text' => 'dropped'],
        ]);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://ok.test"', $html);
        $this->assertStringContainsString('{{{RESEND_UNSUBSCRIBE_URL}}}', $html);
        $this->assertStringContainsString('1 Hay St, Perth WA 6000', $html);
        $this->assertStringNotContainsString('dropped', $html);
    }

    public function test_merge_tags_become_resend_syntax_or_the_sample(): void
    {
        $renderer = app(EmailCampaignRenderer::class);
        $blocks = [['type' => 'heading', 'text' => 'Hi {first_name} at {company}']];

        $this->assertStringContainsString('Hi {{{contact.first_name|there}}} at {{{contact.company|}}}', $renderer->render($blocks));
        $this->assertStringContainsString('Hi Kim &lt;b&gt; at Acura', $renderer->render($blocks, [], ['first_name' => 'Kim <b>', 'company' => 'Acura']));
    }

    /* -- sending ---------------------------------------------------------- */

    public function test_every_blocker_is_named_at_once(): void
    {
        $campaign = EmailCampaign::create(['name' => 'Empty', 'content' => []]);
        config(['visns-packages.email_campaigns.from_email' => null]);

        $this->actingAs($this->manager())
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')
            ->assertStatus(422)
            ->assertJsonCount(4, 'blockers');
    }

    public function test_a_list_still_copying_to_resend_blocks_the_send(): void
    {
        $campaign = $this->readyCampaign();
        $campaign->list->members()->limit(1)->update(['status' => 'pending']);

        $this->actingAs($this->manager())
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')
            ->assertStatus(422)
            ->assertJsonFragment(['The list "Clients" is still being copied to Resend. It carries on in the background; try again in a minute or two.']);
    }

    public function test_send_now_creates_and_sends_the_broadcast_and_snapshots_the_html(): void
    {
        $campaign = $this->readyCampaign();

        $this->actingAs($this->manager())
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')
            ->assertOk()
            ->assertJsonPath('message', 'Sending to 3 people now.');

        $broadcast = $this->sent('POST', 'broadcasts');
        $this->assertSame($campaign->list->resend_segment_id, $broadcast['segment_id']);
        $this->assertSame('Omnia Global <news@omnia.test>', $broadcast['from']);
        $this->assertStringContainsString('{{{contact.first_name|there}}}', $broadcast['html']);
        $this->assertContains('POST broadcasts/bc_1/send', $this->calls);

        $fresh = $campaign->fresh();
        $this->assertSame('sending', $fresh->status);
        $this->assertSame('bc_1', $fresh->resend_broadcast_id);
        $this->assertSame(3, $fresh->recipient_count);
        $this->assertStringContainsString('Hello', $fresh->html);
        $this->assertFalse($fresh->isEditable());
    }

    public function test_a_scheduled_campaign_can_be_cancelled_and_edited_again(): void
    {
        $campaign = $this->readyCampaign();
        $when = now()->addDay()->startOfHour();

        $this->actingAs($this->manager())
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send', ['scheduled_at' => $when->toIso8601String()])
            ->assertOk();

        $this->assertSame($when->toIso8601String(), $this->sent('POST', 'broadcasts/bc_1/send')['scheduled_at']);
        $this->assertSame('scheduled', $campaign->fresh()->status);

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/cancel')->assertOk();

        $this->assertContains('POST broadcasts/bc_1/cancel', $this->calls);
        $this->assertTrue($campaign->fresh()->isEditable());
    }

    public function test_the_test_send_fills_the_tags_from_the_person_sending_it(): void
    {
        $campaign = $this->readyCampaign();
        $me = $this->manager();

        $this->actingAs($me)->postJson(self::BASE . '/campaigns/' . $campaign->id . '/test')->assertOk();

        $email = $this->sent('POST', 'emails');
        $this->assertSame([$me->email], $email['to']);
        $this->assertStringStartsWith('[Test]', $email['subject']);
        $this->assertStringContainsString('Hello Dana', $email['html']);
        $this->assertStringNotContainsString('{{{', $email['html']);
    }

    public function test_the_sync_settles_a_sending_campaign(): void
    {
        $campaign = $this->readyCampaign();
        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')->assertOk();

        $this->sync();

        $this->assertSame('sent', $campaign->fresh()->status);
    }

    /* -- webhook ---------------------------------------------------------- */

    public function test_an_unsigned_stale_or_forged_delivery_is_refused(): void
    {
        $payload = ['type' => 'email.opened', 'data' => ['broadcast_id' => 'bc_1']];

        $this->webhook($payload, null, time() - 600)->assertStatus(401);
        $this->webhook($payload, null, null, 'whsec_' . base64_encode('another-secret-entirely'))->assertStatus(401);

        config(['visns-packages.email_campaigns.webhook_secret' => null]);
        $this->webhook($payload)->assertStatus(401);
    }

    public function test_events_are_stored_once_and_reported(): void
    {
        $campaign = $this->readyCampaign();
        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')->assertOk();

        $data = fn (string $to) => ['broadcast_id' => 'bc_1', 'email_id' => 'e_' . $to, 'to' => [$to]];

        $this->webhook(['type' => 'email.delivered', 'data' => $data('kim@acura.test')])->assertOk();
        $this->webhook(['type' => 'email.delivered', 'data' => $data('lee@acura.test')])->assertOk();
        $this->webhook(['type' => 'email.opened', 'data' => $data('kim@acura.test')], 'msg_open')->assertOk();
        // Svix retrying the same delivery is not a second open.
        $this->webhook(['type' => 'email.opened', 'data' => $data('kim@acura.test')], 'msg_open')->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->webhook(['type' => 'email.clicked', 'data' => $data('kim@acura.test') + ['click' => ['link' => 'https://omnia.test/news', 'ipAddress' => '203.0.113.9']]])->assertOk();
        // The host's own transactional mail is none of this module's business.
        $this->webhook(['type' => 'email.opened', 'data' => ['email_id' => 'tx', 'to' => ['x@y.test']]])->assertOk()->assertJsonPath('outcome', 'not_a_campaign');

        $this->assertSame('sent', $campaign->fresh()->status);

        $report = $this->actingAs($this->manager())->getJson(self::BASE . '/campaigns/' . $campaign->id)->assertOk()->json('campaign.report');

        $this->assertSame(2, $report['delivered']);
        $this->assertSame(1, $report['opened']);
        $this->assertEquals(50.0, $report['open_rate']);
        $this->assertEquals(50.0, $report['click_rate']);
        $this->assertSame([['url' => 'https://omnia.test/news', 'clicks' => 1, 'people' => 1]], $report['links']);
        $this->assertSame(4, EmailCampaignEvent::count());
    }

    public function test_an_unsubscribe_in_resend_marks_the_person_on_every_list(): void
    {
        $this->crmList(['c1']);
        $this->crmList(['c2']);

        $this->webhook(['type' => 'contact.updated', 'data' => ['email' => 'kim@acura.test', 'unsubscribed' => true]])
            ->assertOk()->assertJsonPath('outcome', 'unsubscribed');

        $this->assertSame(2, EmailListMember::where('email', 'kim@acura.test')->whereNotNull('unsubscribed_at')->count());

        // And a refresh does not bring them back.
        $list = EmailList::first();
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $list->id . '/refresh')->assertOk();
        $this->assertNotNull(EmailListMember::where('list_id', $list->id)->where('email', 'kim@acura.test')->value('unsubscribed_at'));
    }

    /* -- the gate --------------------------------------------------------- */

    public function test_reading_and_writing_are_two_permissions(): void
    {
        $reader = $this->staff('Email Campaigns Access');

        $this->actingAs($reader)->getJson(self::BASE)->assertOk()->assertJsonPath('settings.connected', true);
        $this->actingAs($reader)->postJson(self::BASE . '/campaigns', ['name' => 'Nope'])->assertForbidden();

        $this->actingAs($this->staff())->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_sent_campaign_cannot_be_edited_or_deleted(): void
    {
        $campaign = $this->readyCampaign();
        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')->assertOk();

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, ['subject' => 'Changed'])->assertStatus(422);
        $this->actingAs($this->manager())->deleteJson(self::BASE . '/campaigns/' . $campaign->id)->assertStatus(422);
    }
}
