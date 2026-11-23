<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignSender;

/**
 * Scheduled campaigns (4.24.0): `start` with `send_at`, `unschedule`, and the
 * sender promoting a campaign when its time comes - or pausing it when it
 * finds the time long gone.
 *
 * The clock is pinned to Monday 23 November 2026 09:00 UTC unless a test
 * moves it.
 */
class MessagingCampaignScheduleTest extends MessagingTestCase
{
    private const NOW = '2026-11-23 09:00:00';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.messaging.bulk.enabled', true);
        $app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);
        $app['config']->set('visns-packages.messaging.bulk.per_day', 0);
        $app['config']->set('visns-packages.messaging.transport', 'log');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
    }

    private function draft(int $recipients = 2): SmsCampaign
    {
        $admin = $this->admin();
        $line = $this->line([$admin]);

        $rows = [];

        for ($i = 0; $i < $recipients; $i++) {
            $rows[] = ['name' => 'Client ' . $i, 'number' => '+6141234567' . $i];
        }

        $this->actingAs($admin)
            ->postJson(self::BASE . '/campaigns', [
                'line_id' => $line->id,
                'name' => 'Reminders',
                'body' => 'Hi {first_name}.',
                'recipients' => $rows,
            ])
            ->assertStatus(201);

        return SmsCampaign::query()->orderByDesc('id')->first();
    }

    private function act(SmsCampaign $campaign, string $action, array $body = [])
    {
        return $this->postJson(self::BASE . '/campaigns/' . $campaign->id . '/' . $action, $body);
    }

    private function sender(): SmsCampaignSender
    {
        return app(SmsCampaignSender::class);
    }

    /*
    | Scheduling
    */

    public function test_a_draft_is_scheduled_and_the_payload_describes_the_time(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SCHEDULED)
            ->assertJsonPath('campaign.scheduled_at', '2026-11-24T09:00:00+00:00')
            ->assertJsonPath('campaign.schedule.at', '2026-11-24T09:00:00+00:00')
            ->assertJsonPath('campaign.schedule.local', '2026-11-24T09:00')
            ->assertJsonPath('campaign.schedule.label', 'Tuesday 24 November 2026 at 9:00 am')
            ->assertJsonPath('campaign.schedule.zone', 'UTC time')
            ->assertJsonPath('campaign.started_at', null)
            ->assertJsonPath('campaign.last_error', null)
            ->assertJsonPath('campaign.estimated_minutes_remaining', null)
            ->assertJsonPath('campaign.eta.minutes', null)
            ->assertJsonPath('campaign.eta.at', null)
            ->assertJsonPath('campaign.waiting', null);

        $campaign->refresh();

        $this->assertSame('2026-11-24 09:00:00', $campaign->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertTrue($campaign->isActive());
        $this->assertSame(2, $campaign->recipients()->where('status', SmsCampaignRecipient::STATUS_PENDING)->count());
    }

    public function test_an_unscheduled_campaign_carries_null_schedule_fields(): void
    {
        $campaign = $this->draft();

        $this->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->assertJsonPath('campaign.scheduled_at', null)
            ->assertJsonPath('campaign.schedule', null);
    }

    public function test_the_app_timezone_is_the_wall_clock_typed_and_read(): void
    {
        config(['app.timezone' => 'Australia/Perth']);

        $campaign = $this->draft();

        // 09:00 Perth is 01:00 UTC, and the clock runs in UTC here.
        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])
            ->assertOk()
            ->assertJsonPath('campaign.schedule.local', '2026-11-24T09:00')
            ->assertJsonPath('campaign.schedule.label', 'Tuesday 24 November 2026 at 9:00 am')
            ->assertJsonPath('campaign.schedule.zone', 'Perth time')
            ->assertJsonPath('campaign.scheduled_at', '2026-11-24T01:00:00+00:00');

        $this->assertSame('2026-11-24 01:00:00', $campaign->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));

        // And it is promoted at 01:00 UTC, not at 09:00 UTC.
        $this->travelTo(Carbon::parse('2026-11-24 00:59:00'));
        $this->sender()->run(30);
        $this->assertSame(SmsCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-24 01:00:00'));
        $this->sender()->run(30);
        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function badTimes(): array
    {
        $format = 'Choose a date and time to send at.';
        $past = 'Choose a time in the future.';

        return [
            'garbage' => ['tomorrow', $format],
            'seconds' => ['2026-11-24T09:00:00', $format],
            'trailing' => ['2026-11-24T09:00Z', $format],
            'space' => ['2026-11-24 09:00', $format],
            'impossible day' => ['2026-02-30T09:00', $format],
            'impossible hour' => ['2026-11-24T25:00', $format],
            'array' => [['2026-11-24T09:00'], $format],
            'now' => ['2026-11-23T09:00', $past],
            'past' => ['2026-11-22T09:00', $past],
            'too far' => ['2027-02-22T09:01', 'Choose a time within the next 90 days.'],
        ];
    }

    #[DataProvider('badTimes')]
    public function test_a_bad_send_at_is_refused_with_a_sentence($value, string $sentence): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => $value])
            ->assertStatus(422)
            ->assertJsonPath('message', $sentence)
            ->assertJsonPath('errors.send_at.0', $sentence);

        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->scheduled_at);
    }

    public function test_the_edges_of_the_window_are_accepted_and_the_limit_is_configurable(): void
    {
        $campaign = $this->draft();

        // One minute ahead: the minimum.
        $this->act($campaign, 'start', ['send_at' => '2026-11-23T09:01'])->assertOk();

        // Exactly 90 days ahead: the maximum.
        $this->act($campaign, 'start', ['send_at' => '2027-02-21T09:00'])->assertOk();

        config(['visns-packages.messaging.bulk.schedule_max_days' => 7]);

        $this->act($campaign, 'start', ['send_at' => '2026-12-01T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.send_at.0', 'Choose a time within the next 7 days.');
    }

    public function test_a_scheduled_campaign_is_rescheduled(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->act($campaign, 'start', ['send_at' => '2026-11-25T14:30'])
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SCHEDULED)
            ->assertJsonPath('campaign.schedule.label', 'Wednesday 25 November 2026 at 2:30 pm');
    }

    public function test_a_scheduled_campaign_is_sent_now(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        // A blank send_at is "now", exactly like no send_at.
        $this->act($campaign, 'start', ['send_at' => ''])
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SENDING)
            ->assertJsonPath('campaign.scheduled_at', null)
            ->assertJsonPath('campaign.schedule', null)
            ->assertJsonPath('campaign.started_at', '2026-11-23T09:00:00+00:00');
    }

    public function test_a_paused_campaign_cannot_be_scheduled(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start')->assertOk();
        $this->act($campaign, 'pause')->assertOk();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.send_at.0', 'A paused campaign is already part sent: resume it instead of scheduling it.');

        $this->assertSame(SmsCampaign::STATUS_PAUSED, $campaign->fresh()->status);
    }

    public function test_a_sending_or_finished_campaign_cannot_be_scheduled(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start')->assertOk();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A sending campaign cannot be scheduled.');

        $this->act($campaign, 'cancel')->assertOk();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A cancelled campaign cannot be scheduled.');
    }

    /*
    | Unscheduling, cancelling, deleting
    */

    public function test_unschedule_returns_a_draft_that_can_be_deleted_again(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->deleteJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Only a draft can be deleted. Unschedule this campaign first, then delete it.');

        $this->act($campaign, 'unschedule')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_DRAFT)
            ->assertJsonPath('campaign.scheduled_at', null)
            ->assertJsonPath('campaign.schedule', null);

        $this->deleteJson(self::BASE . '/campaigns/' . $campaign->id)->assertNoContent();

        $this->assertNull(SmsCampaign::find($campaign->id));
    }

    public function test_unschedule_is_refused_from_any_other_status(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'unschedule')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A draft campaign cannot be unscheduled.');

        $this->act($campaign, 'start')->assertOk();

        $this->act($campaign, 'unschedule')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A sending campaign cannot be unscheduled.');

        $this->act($campaign, 'pause')->assertOk();

        $this->act($campaign, 'unschedule')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A paused campaign cannot be unscheduled.');
    }

    public function test_unschedule_is_a_404_for_a_campaign_out_of_reach(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/campaigns/999/unschedule')
            ->assertNotFound();
    }

    public function test_a_scheduled_campaign_is_cancelled_and_its_recipients_skipped(): void
    {
        $campaign = $this->draft(3);

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->act($campaign, 'cancel')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_CANCELLED)
            ->assertJsonPath('campaign.counts.skipped', 3)
            ->assertJsonPath('campaign.counts.pending', 0)
            // Kept as the record of what was asked for.
            ->assertJsonPath('campaign.scheduled_at', '2026-11-24T09:00:00+00:00');

        $this->assertSame(3, $campaign->recipients()->where('error', SmsCampaignRecipient::ERROR_CANCELLED)->count());

        // And the sender never touches it at its time.
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00'));
        $counts = $this->sender()->run(30);
        $this->assertSame(0, $counts['sent']);
    }

    public function test_pause_stays_sending_only(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->act($campaign, 'pause')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A scheduled campaign cannot be paused.');
    }

    /*
    | The sender
    */

    public function test_the_sender_waits_for_the_time_then_promotes_and_sends_in_one_run(): void
    {
        $campaign = $this->draft(2);

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-24 08:59:00'));

        $counts = $this->sender()->run(30);

        $this->assertSame(0, $counts['sent']);
        $this->assertSame(SmsCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
        $this->assertSame(2, $campaign->recipients()->where('status', SmsCampaignRecipient::STATUS_PENDING)->count());

        $this->travelTo(Carbon::parse('2026-11-24 09:00:30'));

        $counts = $this->sender()->run(30);

        $this->assertSame(2, $counts['sent']);

        $campaign->refresh();

        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame('2026-11-24 09:00:30', $campaign->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-24 09:00:00', $campaign->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertNull($campaign->last_error);
    }

    public function test_the_command_counts_a_due_scheduled_campaign_as_work(): void
    {
        $campaign = $this->draft(1);

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-24 09:05:00'));

        $this->artisan('sms:send-campaigns')->assertSuccessful();

        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_a_campaign_found_past_its_grace_is_paused_not_sent(): void
    {
        $campaign = $this->draft(2);

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        // One minute past the 60-minute grace.
        $this->travelTo(Carbon::parse('2026-11-24 10:01:00'));

        $counts = $this->sender()->run(30);

        $this->assertSame(0, $counts['sent']);

        $campaign->refresh();

        $this->assertSame(SmsCampaign::STATUS_PAUSED, $campaign->status);
        $this->assertNull($campaign->started_at);
        $this->assertSame(
            'This campaign was scheduled for Tuesday 24 November 2026 at 9:00 am but could not start then. Press Resume to send it now, or Cancel.',
            $campaign->last_error
        );
        $this->assertSame(2, $campaign->recipients()->where('status', SmsCampaignRecipient::STATUS_PENDING)->count());

        // Resume sends it.
        $this->act($campaign, 'start')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SENDING)
            ->assertJsonPath('campaign.last_error', null);

        $this->assertSame(2, $this->sender()->run(30)['sent']);
    }

    public function test_a_campaign_inside_its_grace_is_still_sent(): void
    {
        $campaign = $this->draft(1);

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-24 10:00:00'));

        $this->assertSame(1, $this->sender()->run(30)['sent']);
        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_the_missed_sentence_reads_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Australia/Perth']);

        $campaign = $this->draft(1);

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        // 01:00 UTC was the time; 05:00 UTC is four hours late.
        $this->travelTo(Carbon::parse('2026-11-24 05:00:00'));
        $this->sender()->run(30);

        $this->assertSame(
            'This campaign was scheduled for Tuesday 24 November 2026 at 9:00 am but could not start then. Press Resume to send it now, or Cancel.',
            $campaign->fresh()->last_error
        );
    }

    /*
    | The list
    */

    public function test_the_list_carries_the_schedule_window_and_lists_scheduled_campaigns(): void
    {
        $campaign = $this->draft();

        $this->act($campaign, 'start', ['send_at' => '2026-11-24T09:00'])->assertOk();

        $this->getJson(self::BASE . '/campaigns')
            ->assertOk()
            ->assertJsonPath('campaigns.0.id', $campaign->id)
            ->assertJsonPath('campaigns.0.status', SmsCampaign::STATUS_SCHEDULED)
            ->assertJsonPath('settings.schedule', [
                'enabled' => true,
                'zone' => 'UTC time',
                'zone_note' => 'Times are UTC time.',
                'min' => '2026-11-23T09:05',
                'max' => '2027-02-21T09:00',
            ]);

        config(['app.timezone' => 'Australia/Perth']);

        $this->getJson(self::BASE . '/campaigns')
            ->assertJsonPath('settings.schedule.zone', 'Perth time')
            ->assertJsonPath('settings.schedule.zone_note', 'Times are Perth time.')
            ->assertJsonPath('settings.schedule.min', '2026-11-23T17:05')
            ->assertJsonPath('settings.schedule.max', '2027-02-21T17:00');
    }
}
