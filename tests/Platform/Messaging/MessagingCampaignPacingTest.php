<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Carbon;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Services\Sms\SmsBulkPacing;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignRenderer;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignSender;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;

/**
 * Not sending: the interval, the cooldown and the daily allowance.
 *
 * A file of its own rather than more of MessagingCampaignTest, because this one
 * runs with the pacing TURNED ON - the other turns it off so its couple of
 * hundred sends do not spend a minute asleep proving nothing.
 *
 * Every delay here is counted rather than taken. `CountingSender` below records
 * what it was asked to sleep for and returns immediately, so a test can assert
 * "two seconds between every send" in a run that takes no time at all - and a
 * regression that stopped sleeping fails rather than merely running faster.
 */
class MessagingCampaignPacingTest extends MessagingTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.messaging.bulk.enabled', true);
        $app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 2000);
        $app['config']->set('visns-packages.messaging.bulk.per_day', 100);
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();
        CountingSender::$slept = [];
    }

    private function useZoom(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());
    }

    private function useLog(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');
    }

    /**
     * The real sender with the one blocking call replaced by a counter.
     */
    private function sender(): CountingSender
    {
        return new CountingSender(
            app(SmsService::class),
            app(SmsOptOuts::class),
            app(SmsCampaignRenderer::class),
            app(SmsBulkPacing::class)
        );
    }

    private function pacing(): SmsBulkPacing
    {
        return app(SmsBulkPacing::class);
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

        $campaign = SmsCampaign::query()->orderByDesc('id')->first();

        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_SENDING,
            'started_at' => now(),
        ])->save();

        return $campaign;
    }

    /**
     * @param  int  $count
     * @return array<int, array<string, mixed>>
     */
    private function people(int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            // Distinct mobiles: the importer de-duplicates, so a repeated
            // number would quietly shorten the list the test thinks it made.
            $rows[] = ['name' => 'Person ' . $i, 'number' => '+6141234' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)];
        }

        return $rows;
    }

    /*
    |--------------------------------------------------------------------------
    | The interval
    |--------------------------------------------------------------------------
    */

    public function test_the_sender_waits_between_sends(): void
    {
        $this->useLog();

        $this->campaign($this->people(3));

        $counts = $this->sender()->run(10);

        $this->assertSame(3, $counts['sent']);

        // Two gaps for three messages. The sleep is AFTER an attempt and only
        // while there is another to make - a run that slept after its last
        // message would spend two seconds of the minute on nobody.
        $this->assertSame([2000, 2000], CountingSender::$slept);
    }

    public function test_a_skipped_recipient_costs_no_delay(): void
    {
        $this->useLog();

        $campaign = $this->campaign($this->people(2));

        // Opted out AFTER the import, which is the case the sender's per-
        // recipient check exists for.
        app(SmsOptOuts::class)->record($campaign->recipients()->first()->number, 'test');

        $counts = $this->sender()->run(10);

        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(1, $counts['sent']);

        // Nothing reached Zoom for the skip and nothing was sent afterwards, so
        // there is no gap to leave anywhere.
        $this->assertSame([], CountingSender::$slept);
    }

    public function test_an_interval_of_zero_sends_back_to_back(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $this->campaign($this->people(3));

        $this->sender()->run(10);

        $this->assertSame([], CountingSender::$slept);
    }

    /*
    |--------------------------------------------------------------------------
    | The run's budget
    |--------------------------------------------------------------------------
    */

    public function test_the_budget_is_capped_so_a_run_fits_inside_its_minute(): void
    {
        // 50 seconds of headroom at two a second: 25, not the 30 asked for.
        $this->assertSame(25, $this->pacing()->effectiveBudget(30));

        // Asking for fewer than fit is honoured exactly - the cap is a ceiling,
        // never a target.
        $this->assertSame(10, $this->pacing()->effectiveBudget(10));

        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);
        $this->assertSame(30, $this->pacing()->effectiveBudget(30));

        // An interval long enough to imply a budget of nought still sends one:
        // a campaign that never moves is a worse answer than a slow one.
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 120000);
        $this->assertSame(1, $this->pacing()->effectiveBudget(30));
    }

    public function test_a_run_attempts_no_more_than_the_capped_budget(): void
    {
        $this->useLog();

        $this->campaign($this->people(30));

        $counts = $this->sender()->run(30);

        $this->assertSame(25, $counts['budget']);
        $this->assertSame(25, $counts['sent']);
        $this->assertSame(24, count(CountingSender::$slept));
    }

    /*
    |--------------------------------------------------------------------------
    | The cooldown
    |--------------------------------------------------------------------------
    */

    public function test_a_429_cools_the_line_for_as_long_as_retry_after_asked(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
            'headers' => ['Retry-After' => ['120']],
        ];

        $campaign = $this->campaign($this->people(2));
        $line = $campaign->line;

        $counts = $this->sender()->run(10);

        $this->assertSame(1, $counts['retry_wait']);

        $until = $this->pacing()->cooldownUntil((int) $line->id);

        $this->assertNotNull($until);

        // Two minutes, give or take the second the test took to get here.
        $this->assertEqualsWithDelta(120, Carbon::now()->diffInSeconds($until), 5);
    }

    public function test_a_429_with_no_retry_after_cools_for_the_default_minute(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
        ];

        $campaign = $this->campaign($this->people(1));

        $this->sender()->run(10);

        $until = $this->pacing()->cooldownUntil((int) $campaign->line->id);

        $this->assertNotNull($until);
        $this->assertEqualsWithDelta(
            SmsBulkPacing::DEFAULT_COOLDOWN_SECONDS,
            Carbon::now()->diffInSeconds($until),
            5
        );
    }

    public function test_an_outlandish_retry_after_is_capped(): void
    {
        $until = $this->pacing()->cool(99, 86400);

        $this->assertEqualsWithDelta(
            SmsBulkPacing::MAX_COOLDOWN_SECONDS,
            Carbon::now()->diffInSeconds($until),
            5
        );
    }

    public function test_retry_after_is_read_as_an_http_date_too(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
            'headers' => ['retry-after' => [gmdate('D, d M Y H:i:s \G\M\T', time() + 300)]],
        ];

        $campaign = $this->campaign($this->people(1));

        $this->sender()->run(10);

        $until = $this->pacing()->cooldownUntil((int) $campaign->line->id);

        $this->assertNotNull($until);
        $this->assertEqualsWithDelta(300, Carbon::now()->diffInSeconds($until), 10);
    }

    public function test_a_503_does_not_cool_the_line(): void
    {
        $this->useZoom();

        // Retryable, and NOT an instruction about our pace: Zoom is having a
        // bad morning. The recipient waits; the line does not.
        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 503,
            'data' => ['message' => 'Service unavailable'],
            'headers' => ['Retry-After' => ['600']],
        ];

        $campaign = $this->campaign($this->people(1));

        $this->sender()->run(10);

        $this->assertNull($this->pacing()->cooldownUntil((int) $campaign->line->id));
    }

    public function test_a_cooling_line_is_skipped_without_touching_its_recipients(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
            'headers' => ['Retry-After' => ['120']],
        ];

        $campaign = $this->campaign($this->people(2));

        $this->sender()->run(10);

        $first = $campaign->recipients()->orderBy('id')->first();

        $this->assertSame(SmsCampaignRecipient::STATUS_PENDING, $first->status);
        $this->assertSame(1, (int) $first->retries, 'the attempt that met the 429 is still an attempt');

        FakeZoomSmsClient::$sends = [];

        $counts = $this->sender()->run(10);

        // Nothing was attempted at all.
        $this->assertSame([], FakeZoomSmsClient::$sends);
        $this->assertSame(0, $counts['sent']);
        $this->assertSame(0, $counts['failed']);
        $this->assertSame(1, $counts['retry_wait']);

        // And the recipient is exactly as it was: a run that never opened a
        // socket on somebody's behalf must not spend their retry budget.
        $first->refresh();
        $this->assertSame(SmsCampaignRecipient::STATUS_PENDING, $first->status);
        $this->assertSame(1, (int) $first->retries);

        // The campaign is still sending. A cooldown is a wait, not a pause, and
        // nobody should have to press Resume because of one.
        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->fresh()->status);
    }

    public function test_a_run_after_the_cooldown_sends(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
            'headers' => ['Retry-After' => ['120']],
        ];

        $campaign = $this->campaign($this->people(1));

        $this->sender()->run(10);

        FakeZoomSmsClient::$response = [
            'success' => true,
            'http_code' => 201,
            'data' => ['message_id' => 'zoom-message-1'],
        ];

        // Past the two minutes Zoom asked for.
        $this->travel(3)->minutes();

        $counts = $this->sender()->run(10);

        $this->assertSame(1, $counts['sent']);
        $this->assertSame(
            SmsCampaignRecipient::STATUS_SENT,
            $campaign->recipients()->first()->status
        );

        $this->travelBack();
    }

    public function test_a_cooldown_is_per_line_and_leaves_another_number_alone(): void
    {
        $this->useZoom();

        $admin = $this->admin();
        $cooling = $this->line([$admin]);
        $other = $this->line([$admin]);

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
            'headers' => ['Retry-After' => ['120']],
        ];

        $first = $this->campaign($this->people(1), ['user' => $admin, 'line' => $cooling]);

        $this->sender()->run(10);

        $second = $this->campaign(
            [['name' => 'Solo', 'number' => '+61498765432']],
            ['user' => $admin, 'line' => $other]
        );

        FakeZoomSmsClient::$response = [
            'success' => true,
            'http_code' => 201,
            'data' => ['message_id' => 'zoom-message-2'],
        ];

        $counts = $this->sender()->run(10);

        $this->assertSame(1, $counts['sent']);
        $this->assertSame(
            SmsCampaignRecipient::STATUS_SENT,
            $second->recipients()->first()->status
        );
        $this->assertSame(
            SmsCampaignRecipient::STATUS_PENDING,
            $first->recipients()->first()->status
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The daily allowance
    |--------------------------------------------------------------------------
    */

    public function test_sending_stops_at_the_daily_allowance_and_the_campaign_stays_sending(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 3);
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $campaign = $this->campaign($this->people(5));

        $counts = $this->sender()->run(10);

        $this->assertSame(3, $counts['sent']);
        $this->assertSame(1, $counts['retry_wait']);

        // NOT paused. Nobody should have to press Resume tomorrow morning.
        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame(2, $campaign->pendingCount());

        // And the next run of the same day attempts nobody.
        $counts = $this->sender()->run(10);

        $this->assertSame(0, $counts['sent']);
        $this->assertSame(1, $counts['retry_wait']);
        $this->assertSame(2, $campaign->pendingCount());
    }

    public function test_the_allowance_is_shared_by_every_campaign_on_the_line(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 2);
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $admin = $this->admin();
        $line = $this->line([$admin]);

        $first = $this->campaign($this->people(2), ['user' => $admin, 'line' => $line]);
        $second = $this->campaign(
            [['name' => 'Solo', 'number' => '+61498765432']],
            ['user' => $admin, 'line' => $line]
        );

        $counts = $this->sender()->run(10);

        // The first campaign - oldest start first - takes the whole of the
        // day's two, and the second gets none of them.
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(2, (int) $first->fresh()->sent);
        $this->assertSame(
            SmsCampaignRecipient::STATUS_PENDING,
            $second->recipients()->first()->status
        );
    }

    public function test_sending_resumes_after_midnight(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 2);
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $campaign = $this->campaign($this->people(4));

        $this->assertSame(2, $this->sender()->run(10)['sent']);

        // Tomorrow, and the allowance is a new one - the count is off `sent_at`
        // rather than off a counter somebody has to reset.
        $this->travelTo(Carbon::now()->addDay()->startOfDay()->addMinutes(5));

        $counts = $this->sender()->run(10);

        $this->assertSame(2, $counts['sent']);
        $this->assertSame(0, $campaign->pendingCount());
        $this->assertSame(SmsCampaign::STATUS_COMPLETED, $campaign->fresh()->status);

        $this->travelBack();
    }

    public function test_an_allowance_of_zero_is_unlimited(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 0);
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $this->campaign($this->people(6));

        $this->assertSame(6, $this->sender()->run(10)['sent']);
    }

    /*
    |--------------------------------------------------------------------------
    | Saying why
    |--------------------------------------------------------------------------
    */

    public function test_waiting_is_null_on_a_campaign_that_is_simply_sending(): void
    {
        $this->useLog();

        $campaign = $this->campaign($this->people(2));

        $body = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign');

        // Present and null - a missing key would read to a front end exactly
        // like a null, and the two must not be told apart by accident.
        $this->assertArrayHasKey('waiting', $body);
        $this->assertNull($body['waiting']);
    }

    public function test_waiting_names_the_cooldown_and_when_it_ends(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 429,
            'data' => ['message' => 'Too many requests'],
            'headers' => ['Retry-After' => ['120']],
        ];

        $campaign = $this->campaign($this->people(1));

        $this->sender()->run(10);

        $waiting = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign.waiting');

        $this->assertSame('cooldown', $waiting['reason']);
        $this->assertNotNull($waiting['until']);
        $this->assertStringContainsString('Zoom asked us to slow down', $waiting['message']);
        $this->assertStringContainsString(
            Carbon::parse($waiting['until'])->format('H:i'),
            $waiting['message']
        );
    }

    public function test_waiting_names_the_daily_allowance(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 2);
        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $campaign = $this->campaign($this->people(4));

        $this->sender()->run(10);

        $waiting = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign.waiting');

        $this->assertSame('daily_allowance', $waiting['reason']);
        $this->assertSame(
            "Today's allowance of 2 texts on this line is used; sending resumes after midnight.",
            $waiting['message']
        );

        // It says when, and when is midnight tonight.
        $this->assertSame(
            Carbon::now()->addDay()->startOfDay()->toIso8601String(),
            $waiting['until']
        );
    }

    public function test_a_draft_is_never_waiting(): void
    {
        $this->useLog();

        $campaign = $this->campaign($this->people(1));
        $campaign->forceFill(['status' => SmsCampaign::STATUS_DRAFT])->save();

        // Even with the line plainly cooling: a draft's status already says why
        // nothing is happening, and a second explanation beside it would be a
        // contradiction waiting to happen.
        $this->pacing()->cool((int) $campaign->line_id, 300);

        $body = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign');

        $this->assertNull($body['waiting']);
        $this->assertNull($body['estimated_minutes_remaining']);
        $this->assertNull($body['eta']['label']);
    }

    /*
    |--------------------------------------------------------------------------
    | The estimate
    |--------------------------------------------------------------------------
    */

    public function test_the_estimate_uses_the_paced_budget_rather_than_per_minute(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 0);

        // 60 is deliberately a length where the two answers DIFFER: three runs
        // at the paced 25, two at the configured 30. Most lengths give the same
        // number either way, and a test that picked one of those would pass
        // against an estimate that had never heard of the interval.
        $campaign = $this->campaign($this->people(60));

        $body = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign');

        $this->assertSame(3, $body['estimated_minutes_remaining']);
        $this->assertSame('about 3 minutes', $body['eta']['label']);

        $this->app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);

        $body = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign');

        $this->assertSame(2, $body['estimated_minutes_remaining']);
    }

    public function test_the_settings_block_quotes_the_paced_budget(): void
    {
        $settings = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns')
            ->assertOk()
            ->json('settings');

        $this->assertSame(30, $settings['per_minute']);
        $this->assertSame(25, $settings['per_run']);
        $this->assertSame(2000, $settings['send_interval_ms']);
        $this->assertSame(100, $settings['per_day']);
    }

    public function test_a_long_list_on_a_small_allowance_is_measured_in_days(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 100);

        // Frozen, because the answer legitimately depends on how much of today
        // is left - and a test that read the wall clock would be a different
        // test every afternoon.
        $this->travelTo(Carbon::parse('2026-09-11 09:00:00'));

        $campaign = $this->campaign($this->people(500));

        $body = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign');

        // Today's hundred goes now; the other four hundred wait out the 900
        // minutes to midnight and then take a day each, the last of them four
        // minutes. Said in DAYS, because "about 5224 minutes" is not an answer
        // anybody reads as three and a half days.
        $this->assertSame(900 + 1440 + 1440 + 1440 + 4, $body['estimated_minutes_remaining']);
        $this->assertSame('about 4 days', $body['eta']['label']);
        $this->assertNotNull($body['eta']['at']);

        $this->travelBack();
    }

    public function test_the_estimate_ignores_the_allowance_when_it_is_unlimited(): void
    {
        $this->useLog();
        $this->app['config']->set('visns-packages.messaging.bulk.per_day', 0);

        $campaign = $this->campaign($this->people(500));

        $body = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('campaign');

        // 500 at 25 a minute: 20 minutes, and no days about it.
        $this->assertSame(20, $body['estimated_minutes_remaining']);
        $this->assertSame('about 20 minutes', $body['eta']['label']);
    }
}

/**
 * The sender with its one blocking call replaced by a tally.
 *
 * A subclass rather than a mock because the point is to exercise the REAL
 * `work()` loop - where the sleep is placed, what it is skipped for - with only
 * the seconds taken out. `sleepBetweenSends` is `protected` for exactly this.
 */
class CountingSender extends SmsCampaignSender
{
    /** @var array<int, int> */
    public static array $slept = [];

    protected function sleepBetweenSends(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }

        self::$slept[] = $ms;
    }
}
