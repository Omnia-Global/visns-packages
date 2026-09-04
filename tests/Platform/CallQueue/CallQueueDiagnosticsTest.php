<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Events\CallQueueDiagnosticPing;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The webhook ledger and the diagnostics endpoint behind it.
 *
 * What is under test is the answer to "the pop only shows up some of the time":
 * that every delivery leaves a row saying which of the four candidate causes it
 * was, that the broadcast leg is recorded separately from the rest, and that
 * none of this can itself become the reason a webhook fails - Zoom disables an
 * endpoint that errors, so a diagnostic that could 500 would cause the fault it
 * was installed to find.
 */
class CallQueueDiagnosticsTest extends TestCase
{
    private const SECRET = 'zoom-signing-secret';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set(
            'visns-packages.call_queue.webhook_secret_token',
            self::SECRET
        );
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration(
            '2026_08_19_210000_create_zoom_live_queue_calls_table.php'
        );
        // The live-call table's direct-call and missed-leg columns; every
        // ringing webhook writes `last_ringing_at`, and the snapshot's live
        // scope reads `last_missed_at`.
        $this->runPackageMigration(
            '2026_09_02_120000_add_kind_and_callee_to_zoom_live_queue_calls_table.php'
        );
        $this->runPackageMigration(
            '2026_08_19_210100_create_zoom_call_queue_settings_table.php'
        );
        $this->runPackageMigration(
            '2026_09_02_100000_create_zoom_webhook_events_table.php'
        );
    }

    private function staffWith(string ...$permissions): User
    {
        $user = User::create([
            'firstname' => 'Sam',
            'email' => 'sam@example.test',
            'password' => Hash::make('x'),
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        return $user;
    }

    /**
     * Post a payload signed the way Zoom signs it.
     */
    private function signedPost(array $body)
    {
        $json = json_encode($body);
        $timestamp = (string) time();

        $signature = 'v0=' . hash_hmac(
            'sha256',
            'v0:' . $timestamp . ':' . $json,
            self::SECRET
        );

        return $this->call(
            'POST',
            '/api/zoom/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ZM_SIGNATURE' => $signature,
                'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            ],
            $json
        );
    }

    private function ringingPayload(array $overrides = []): array
    {
        return [
            'event' => 'phone.callee_ringing',
            'payload' => [
                'object' => array_merge([
                    'call_id' => 'call-abc-123',
                    'caller' => [
                        'phone_number' => '+61412345678',
                        'name' => 'Cleo Client',
                    ],
                    'callee' => [
                        'extension_type' => 'callqueue',
                        'extension_id' => 'queue-1',
                        'extension_number' => '303',
                        'name' => 'Reception',
                    ],
                    'ringing_start_time' => '2026-08-19T03:04:05Z',
                ], $overrides),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The ledger
    |--------------------------------------------------------------------------
    */

    public function test_a_matched_ringing_call_is_recorded_with_its_broadcast(): void
    {
        $this->signedPost($this->ringingPayload())->assertOk();

        $row = ZoomWebhookEvent::sole();

        $this->assertSame('phone.callee_ringing', $row->event);
        $this->assertSame('ringing_recorded', $row->outcome);
        $this->assertSame('call-abc-123', $row->call_id);
        $this->assertSame('queue-1', $row->queue_id);
        $this->assertSame('Reception', $row->queue_name);
        $this->assertSame('+61412345678', $row->caller_number);
        $this->assertSame('ok', $row->broadcast);
        $this->assertNull($row->error);
        $this->assertNotNull($row->broadcast_ms);
        $this->assertNotNull($row->duration_ms);
        $this->assertNotNull($row->received_at);
    }

    public function test_the_row_keeps_the_routing_shape_and_no_names(): void
    {
        $this->signedPost($this->ringingPayload([
            'forwarded_by' => [
                'extension_type' => 'call_queue',
                'id' => 'queue-2',
                'name' => 'Overflow',
            ],
        ]))->assertOk();

        $meta = ZoomWebhookEvent::sole()->meta;

        // Enough to tune the matcher against real traffic; nothing that names a
        // human being.
        $this->assertSame(
            [
                'callee' => [
                    'extension_type' => 'callqueue',
                    'id' => 'queue-1',
                    'extension_number' => '303',
                ],
                'forwarded_by' => [
                    'extension_type' => 'call_queue',
                    'id' => 'queue-2',
                ],
            ],
            $meta
        );

        $this->assertStringNotContainsString(
            'Cleo Client',
            json_encode($meta)
        );
    }

    public function test_an_excluded_queue_says_so_rather_than_going_missing(): void
    {
        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-1',
            'excluded' => true,
        ]);
        ZoomCallQueueSetting::flushCache();

        $this->signedPost($this->ringingPayload())->assertOk();

        // The distinction the ledger exists for: deliberately dropped, not
        // lost.
        $this->assertSame(
            'ringing_excluded_queue',
            ZoomWebhookEvent::sole()->outcome
        );
    }

    public function test_a_call_on_a_personal_extension_is_recorded_as_a_direct_pop(): void
    {
        $this->signedPost($this->ringingPayload([
            'callee' => ['extension_type' => 'user', 'extension_id' => 'user-9'],
        ]))->assertOk();

        $row = ZoomWebhookEvent::sole();

        // These rows were the evidence: every `ringing_unmatched` on the live
        // account looked exactly like this, and they were all direct dials,
        // internal calls and transfers. They pop now, under their own outcome
        // word so the two kinds of recorded call stay countable apart.
        $this->assertSame('ringing_recorded_direct', $row->outcome);
        $this->assertSame('user', $row->meta['callee']['extension_type']);
        $this->assertSame('ok', $row->broadcast);
    }

    public function test_a_call_on_a_routing_object_is_recorded_as_unmatched(): void
    {
        $this->signedPost($this->ringingPayload([
            'callee' => [
                'extension_type' => 'autoReceptionist',
                'extension_id' => 'ar-1',
            ],
        ]))->assertOk();

        $row = ZoomWebhookEvent::sole();

        // Still the shape worth surveying: not a queue, and not somebody's
        // phone either.
        $this->assertSame('ringing_unmatched', $row->outcome);
        $this->assertSame(
            'autoReceptionist',
            $row->meta['callee']['extension_type']
        );
        $this->assertNull($row->broadcast);
    }

    public function test_a_closing_event_records_its_own_outcomes(): void
    {
        $this->signedPost($this->ringingPayload());

        $this->signedPost([
            'event' => 'phone.callee_answered',
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ])->assertOk();

        $this->signedPost([
            'event' => 'phone.caller_ended',
            'payload' => ['object' => ['call_id' => 'never-seen']],
        ])->assertOk();

        // Still `closed_no_match`: nothing here ever popped `never-seen`, so
        // there is no card anywhere to take down and nothing to announce.
        $this->assertSame(
            ['ringing_recorded', 'answered', 'closed_no_match'],
            ZoomWebhookEvent::orderBy('id')->pluck('outcome')->all()
        );
    }

    public function test_a_closing_event_for_a_popped_call_is_announced_after_its_row_has_gone(): void
    {
        Event::fake([CallQueueEnded::class]);

        $this->signedPost($this->ringingPayload());

        /*
        | The state that stranded cards on production. The live row goes — a
        | sweep took it, or the `queue.missed` publish that should have started
        | the browser's grace timer never landed — while the card is still up on
        | every open screen. The later `phone.caller_ended` is then the last
        | thing that will ever be said about this call, and it used to be
        | dropped in silence.
        */
        ZoomLiveQueueCall::where('call_id', 'call-abc-123')->delete();

        $this->signedPost([
            'event' => 'phone.caller_ended',
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        Event::assertDispatched(
            CallQueueEnded::class,
            fn(CallQueueEnded $event) => $event->callId === 'call-abc-123'
        );

        // The ledger keeps the two apart: the row was gone, and we said so
        // anyway. A run of these is the shape of "the card would not clear".
        $this->assertSame(
            'closed_untracked',
            ZoomWebhookEvent::orderByDesc('id')->first()->outcome
        );
    }

    public function test_an_unhandled_event_is_recorded_as_unhandled(): void
    {
        $this->signedPost([
            'event' => 'phone.something_else',
            'payload' => ['object' => []],
        ])->assertOk();

        $this->assertSame('unhandled', ZoomWebhookEvent::sole()->outcome);
    }

    public function test_the_url_validation_challenge_is_recorded(): void
    {
        $this->signedPost([
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'abc123'],
        ])->assertOk();

        $this->assertSame('url_validation', ZoomWebhookEvent::sole()->outcome);
    }

    /*
    |--------------------------------------------------------------------------
    | The broadcast leg
    |--------------------------------------------------------------------------
    */

    public function test_a_failing_broadcast_is_recorded_and_still_answers_zoom_200(): void
    {
        Event::listen(CallQueueRinging::class, function () {
            throw new \RuntimeException('reverb unreachable');
        });

        // 200 regardless: Zoom retries and then DISABLES an endpoint that
        // errors, so the failure has to be recorded rather than raised.
        $this->signedPost($this->ringingPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $row = ZoomWebhookEvent::sole();

        $this->assertSame('failed', $row->broadcast);
        $this->assertSame('reverb unreachable', $row->error);
        $this->assertNotNull($row->broadcast_ms);

        // The exception still travelled the old path — the call was recorded,
        // the outcome says so, and the outer catch turned it into a 200.
        $this->assertSame('failed', $row->outcome);
    }

    public function test_a_broadcast_failure_is_recorded_without_its_credentials(): void
    {
        Event::listen(CallQueueRinging::class, function () {
            // The shape the Pusher/Reverb client actually throws: the whole
            // request URL, credentials and all.
            throw new \RuntimeException(
                'Pusher error: cURL error 7: Failed to connect for '
                . 'http://127.0.0.1:8080/apps/1/events?auth_key=abc123'
                . '&auth_timestamp=1&auth_signature=deadbeef'
            );
        });

        $this->signedPost($this->ringingPayload())->assertOk();

        $error = ZoomWebhookEvent::sole()->error;

        // The diagnosis survives; the credentials do not.
        $this->assertStringContainsString('cURL error 7', $error);
        $this->assertStringContainsString('127.0.0.1:8080', $error);
        $this->assertStringNotContainsString('abc123', $error);
        $this->assertStringNotContainsString('deadbeef', $error);
    }

    /*
    |--------------------------------------------------------------------------
    | Rejections
    |--------------------------------------------------------------------------
    */

    public function test_a_rejected_delivery_leaves_a_row(): void
    {
        $this->postJson('/api/zoom/webhook', $this->ringingPayload())
            ->assertStatus(401);

        $row = ZoomWebhookEvent::sole();

        // Without this, a signing secret that has drifted out of step with the
        // Zoom app looks exactly like Zoom having gone quiet.
        $this->assertSame('rejected', $row->event);
        $this->assertSame('missing signature header', $row->outcome);
        $this->assertNull($row->call_id);
    }

    /*
    |--------------------------------------------------------------------------
    | The ledger can never be the fault
    |--------------------------------------------------------------------------
    */

    public function test_a_ledger_that_cannot_write_does_not_cost_the_pop(): void
    {
        config()->set(
            'visns-packages.call_queue.tables.webhook_events',
            'a_table_that_does_not_exist'
        );

        Event::fake([CallQueueRinging::class]);

        $this->signedPost($this->ringingPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        // The pop is the point; the diagnostic is not allowed to cost it one.
        Event::assertDispatched(CallQueueRinging::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The diagnostics endpoint
    |--------------------------------------------------------------------------
    */

    public function test_diagnostics_needs_the_settings_permission(): void
    {
        // It names the broadcast target and the excluded queues: administrator
        // material, even though no credential appears in the response.
        $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/diagnostics')
            ->assertStatus(403);
    }

    public function test_diagnostics_answers_with_the_server_state_and_the_ledger(): void
    {
        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-9',
            'excluded' => true,
        ]);
        ZoomCallQueueSetting::flushCache();

        $this->signedPost($this->ringingPayload());

        $body = $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/diagnostics')
            ->assertOk()
            ->json();

        $this->assertSame(
            ['server', 'summary', 'events'],
            array_keys($body)
        );

        $this->assertSame(
            [
                'app_env',
                'broadcast_driver',
                'publish_target',
                'channel',
                'queue_connection',
                'log_level',
                'webhook_secret_configured',
                'excluded_queue_ids',
                'stale_after_minutes',
                'live_rows',
                'retain_days',
            ],
            array_keys($body['server'])
        );

        $this->assertSame('null', $body['server']['broadcast_driver']);
        $this->assertSame(CallQueueChannel::name(), $body['server']['channel']);
        $this->assertTrue($body['server']['webhook_secret_configured']);
        $this->assertSame(['queue-9'], $body['server']['excluded_queue_ids']);
        $this->assertSame(1, $body['server']['live_rows']);
        $this->assertSame(7, $body['server']['retain_days']);

        $this->assertSame(
            ['ringing_recorded' => 1],
            $body['summary']['last_24h']
        );
        $this->assertSame(
            ['ringing_recorded' => 1],
            $body['summary']['last_7d']
        );
        $this->assertNotNull($body['summary']['last_event_at']);
        $this->assertNotNull($body['summary']['last_ringing_recorded_at']);
        $this->assertSame(0, $body['summary']['broadcast_failures_24h']);

        $this->assertCount(1, $body['events']);
        $this->assertSame(
            [
                'id',
                'event',
                'call_id',
                'queue_id',
                'queue_name',
                'caller_number',
                'outcome',
                'broadcast',
                'broadcast_ms',
                'duration_ms',
                'error',
                'received_at',
            ],
            array_keys($body['events'][0])
        );

        // An offset, not a bare wall clock: these rows get read from a laptop
        // in another timezone often enough for that to matter.
        $this->assertMatchesRegularExpression(
            '/[+-]\d{2}:\d{2}$/',
            $body['events'][0]['received_at']
        );
    }

    public function test_diagnostics_returns_the_newest_hundred_rows_first(): void
    {
        foreach (range(1, 105) as $i) {
            ZoomWebhookEvent::create([
                'event' => 'phone.callee_ringing',
                'outcome' => 'ringing_recorded',
                'received_at' => Carbon::now()->subMinutes(200 - $i),
            ]);
        }

        $body = $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/diagnostics')
            ->assertOk()
            ->json();

        $this->assertCount(100, $body['events']);
        $this->assertSame(105, $body['events'][0]['id']);
    }

    public function test_reading_diagnostics_prunes_rows_past_the_retention_window(): void
    {
        $old = ZoomWebhookEvent::create([
            'event' => 'phone.callee_ringing',
            'outcome' => 'ringing_recorded',
            'received_at' => Carbon::now()->subDays(30),
        ]);

        $recent = ZoomWebhookEvent::create([
            'event' => 'phone.callee_ringing',
            'outcome' => 'ringing_recorded',
            'received_at' => Carbon::now()->subHours(2),
        ]);

        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/diagnostics')
            ->assertOk();

        // A rolling window, not an audit trail — and pruned on read so an
        // application running no scheduler still gets it.
        $this->assertNull(ZoomWebhookEvent::find($old->id));
        $this->assertNotNull(ZoomWebhookEvent::find($recent->id));
    }

    public function test_the_retention_window_is_configurable(): void
    {
        config()->set('visns-packages.call_queue.diagnostics.retain_days', 30);

        $old = ZoomWebhookEvent::create([
            'event' => 'phone.callee_ringing',
            'outcome' => 'ringing_recorded',
            'received_at' => Carbon::now()->subDays(10),
        ]);

        $body = $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/diagnostics')
            ->assertOk()
            ->json();

        $this->assertSame(30, $body['server']['retain_days']);
        $this->assertNotNull(ZoomWebhookEvent::find($old->id));
    }

    /*
    |--------------------------------------------------------------------------
    | The ping
    |--------------------------------------------------------------------------
    */

    public function test_the_ping_needs_the_settings_permission(): void
    {
        $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->postJson('/ajax/call-queue/diagnostics/ping')
            ->assertStatus(403);
    }

    public function test_the_ping_broadcasts_on_the_pops_own_channel(): void
    {
        Event::fake([CallQueueDiagnosticPing::class]);

        $body = $this->actingAs($this->staffWith('Call Queue Settings'))
            ->postJson('/ajax/call-queue/diagnostics/ping')
            ->assertOk()
            ->json();

        $this->assertTrue($body['ok']);
        $this->assertNotEmpty($body['nonce']);
        $this->assertIsInt($body['ms']);
        $this->assertNull($body['error']);

        Event::assertDispatched(
            CallQueueDiagnosticPing::class,
            fn(CallQueueDiagnosticPing $event) => $event->nonce === $body['nonce']
        );
    }

    public function test_the_ping_event_rides_the_same_subscription_as_a_real_pop(): void
    {
        $event = new CallQueueDiagnosticPing('nonce-1');

        // Same private channel, same broadcast-now path: a ping the browser
        // receives means a real pop would have arrived too.
        $this->assertSame('queue.diagnostic-ping', $event->broadcastAs());
        $this->assertSame(
            'private-' . CallQueueChannel::name(),
            (string) $event->broadcastOn()
        );
        $this->assertSame(
            ['nonce', 'sent_at'],
            array_keys($event->broadcastWith())
        );
        $this->assertInstanceOf(
            \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class,
            $event
        );
    }

    public function test_the_module_ships_with_the_ledger_configured(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertSame(
            'zoom_webhook_events',
            $shipped['call_queue']['tables']['webhook_events']
        );
        $this->assertSame(
            7,
            $shipped['call_queue']['diagnostics']['retain_days']
        );
    }
}
