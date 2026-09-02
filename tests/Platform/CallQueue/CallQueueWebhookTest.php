<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\CallQueueAnswered;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueMissed;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\StubCallerEnrichment;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The Zoom Phone webhook: signature, event handling, and the broadcast contract
 * a live front end already parses.
 */
class CallQueueWebhookTest extends TestCase
{
    private const SECRET = 'zoom-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        StubCallerEnrichment::reset();
    }

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
    }

    /**
     * Post a payload signed the way Zoom signs it.
     */
    private function signedPost(array $body, ?string $secret = null, ?string $timestamp = null)
    {
        $json = json_encode($body);
        $timestamp ??= (string) time();

        $signature = 'v0=' . hash_hmac(
            'sha256',
            'v0:' . $timestamp . ':' . $json,
            $secret ?? self::SECRET
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
                        'name' => 'Reception',
                    ],
                    'ringing_start_time' => '2026-08-19T03:04:05Z',
                ], $overrides),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Signature
    |--------------------------------------------------------------------------
    */

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $this->postJson('/api/zoom/webhook', $this->ringingPayload())
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Unauthorized']);

        $this->assertSame(0, ZoomLiveQueueCall::count());
    }

    public function test_a_wrongly_signed_delivery_is_rejected(): void
    {
        $this->signedPost($this->ringingPayload(), 'not-the-secret')
            ->assertStatus(401);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        // Replay guard: a captured delivery must not stay valid forever.
        $this->signedPost(
            $this->ringingPayload(),
            null,
            (string) (time() - 3600)
        )->assertStatus(401);
    }

    public function test_an_unset_secret_fails_closed(): void
    {
        config()->set('visns-packages.call_queue.webhook_secret_token', null);

        // Inert rather than open: until the Zoom app exists there is no secret,
        // and an endpoint that accepted anything in the meantime would be a
        // public write into the live-call table.
        $this->signedPost($this->ringingPayload())->assertStatus(401);
    }

    public function test_the_url_validation_challenge_is_answered(): void
    {
        $response = $this->signedPost([
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'abc123'],
        ])->assertOk();

        $response->assertJsonPath('plainToken', 'abc123');
        $response->assertJsonPath(
            'encryptedToken',
            hash_hmac('sha256', 'abc123', self::SECRET)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Ringing
    |--------------------------------------------------------------------------
    */

    public function test_a_queue_call_is_recorded_and_broadcast(): void
    {
        Event::fake([CallQueueRinging::class]);

        $this->signedPost($this->ringingPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        $this->assertNotNull($call);
        $this->assertSame('queue-1', $call->queue_id);
        $this->assertSame('Reception', $call->queue_name);
        $this->assertSame('+61412345678', $call->caller_number);
        $this->assertSame('ringing', $call->status);

        Event::assertDispatched(CallQueueRinging::class);
    }

    public function test_the_broadcast_payload_shape_is_the_one_the_front_end_parses(): void
    {
        $this->signedPost($this->ringingPayload());

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');
        $event = new CallQueueRinging($call);

        $this->assertSame('queue.ringing', $event->broadcastAs());

        $payload = $event->broadcastWith()['call'];

        // The first seven keys, in that order, are the contract a live front
        // end already parses; the direct-call keys were appended after them so
        // nothing that reads this had to change. See DirectCallPopTest.
        $this->assertSame(
            [
                'call_id',
                'queue_id',
                'queue_name',
                'caller_number',
                'caller_name',
                'client',
                'started_at',
                'kind',
                'callee_name',
                'callee_extension',
                'forwarded_by_name',
                'pickup_key',
            ],
            array_keys($payload)
        );

        $this->assertSame('call-abc-123', $payload['call_id']);
        $this->assertSame('queue-1', $payload['queue_id']);
        $this->assertSame('Reception', $payload['queue_name']);
        $this->assertSame('queue', $payload['kind']);
        $this->assertSame('queue-1', $payload['pickup_key']);
    }

    public function test_the_channel_is_private_and_configurable(): void
    {
        $this->assertSame('call-queue-monitor', CallQueueChannel::name());

        config()->set('visns-packages.call_queue.append_env_suffix', true);

        // Deployments that share one Pusher app between environments need the
        // suffix, or a dev broadcast lands in a production browser.
        // The suffix is the CONFIGURED environment name, which is not always
        // the same string as app()->environment().
        $env = config('app.env');

        $this->assertSame(
            'call-queue-monitor.' . $env,
            CallQueueChannel::name()
        );

        config()->set('visns-packages.call_queue.channel', 'phones');
        $this->assertSame('phones.' . $env, CallQueueChannel::name());
    }

    public function test_a_queue_named_only_under_forwarded_by_still_matches(): void
    {
        // Zoom's queue events are inconsistently shaped: sometimes the callee IS
        // the queue, sometimes the callee is the member's own extension and the
        // queue only appears under forwarded_by.
        $this->signedPost($this->ringingPayload([
            'callee' => ['extension_type' => 'user', 'extension_id' => 'user-9'],
            'forwarded_by' => [
                'extension_type' => 'call_queue',
                'extension_id' => 'queue-2',
                'name' => 'Overflow',
            ],
        ]))->assertOk();

        $this->assertSame(
            'queue-2',
            ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123')->queue_id
        );
    }

    public function test_a_call_ringing_on_a_personal_extension_pops_as_a_direct_call(): void
    {
        // It used to be dropped. Every unmatched ringing delivery in the live
        // ledger turned out to be one of these — a direct dial, an internal
        // call or a transfer — which is precisely the call no queue is ringing
        // anybody else's phone to cover. DirectCallPopTest owns the detail.
        $this->signedPost($this->ringingPayload([
            'callee' => ['extension_type' => 'user', 'extension_id' => 'user-9'],
        ]))
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        $this->assertSame('direct', $call->kind);
        $this->assertNull($call->queue_id);
    }

    public function test_a_call_ringing_a_routing_object_is_still_ignored(): void
    {
        // An auto receptionist is not somebody's phone: nothing is ringing yet.
        $this->signedPost($this->ringingPayload([
            'callee' => [
                'extension_type' => 'autoReceptionist',
                'extension_id' => 'ar-1',
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, ZoomLiveQueueCall::count());
    }

    public function test_an_excluded_queue_is_dropped_silently(): void
    {
        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-1',
            'excluded' => true,
        ]);
        ZoomCallQueueSetting::flushCache();

        $this->signedPost($this->ringingPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, ZoomLiveQueueCall::count());
    }

    public function test_a_repeated_ringing_event_updates_rather_than_duplicates(): void
    {
        $this->signedPost($this->ringingPayload());
        $this->signedPost($this->ringingPayload(['caller' => [
            'phone_number' => '+61499999999',
            'name' => 'Someone Else',
        ]]));

        $this->assertSame(1, ZoomLiveQueueCall::count());
        $this->assertSame(
            '+61499999999',
            ZoomLiveQueueCall::first()->caller_number
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Caller enrichment
    |--------------------------------------------------------------------------
    */

    public function test_no_enrichment_is_configured_by_default(): void
    {
        $this->signedPost($this->ringingPayload());

        $this->assertNull(ZoomLiveQueueCall::first()->client_preview);
    }

    public function test_the_enrichment_hook_result_rides_along_with_the_call(): void
    {
        config()->set(
            'visns-packages.call_queue.caller_enrichment',
            StubCallerEnrichment::class
        );

        $this->signedPost($this->ringingPayload());

        $this->assertSame(['+61412345678'], StubCallerEnrichment::$calls);
        $this->assertSame(
            ['id' => 7, 'name' => 'Cleo Client', 'open_tasks' => 2],
            ZoomLiveQueueCall::first()->client_preview
        );
    }

    public function test_a_throwing_enrichment_hook_costs_the_snapshot_not_the_pop(): void
    {
        config()->set(
            'visns-packages.call_queue.caller_enrichment',
            StubCallerEnrichment::class
        );
        StubCallerEnrichment::$shouldThrow = true;

        Event::fake([CallQueueRinging::class]);

        $this->signedPost($this->ringingPayload())->assertOk();

        // The pop still happens - a call the user never hears about is worse
        // than a call card with less detail on it.
        $this->assertSame(1, ZoomLiveQueueCall::count());
        $this->assertNull(ZoomLiveQueueCall::first()->client_preview);
        Event::assertDispatched(CallQueueRinging::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Closing events
    |--------------------------------------------------------------------------
    */

    public function test_an_answered_call_is_cleared_and_announced(): void
    {
        Event::fake([CallQueueAnswered::class]);

        $this->signedPost($this->ringingPayload());

        $this->signedPost([
            'event' => 'phone.callee_answered',
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ])->assertJsonPath('status', 'ok');

        $this->assertSame(0, ZoomLiveQueueCall::count());

        Event::assertDispatched(
            CallQueueAnswered::class,
            fn(CallQueueAnswered $event) => $event->callId === 'call-abc-123'
        );
    }

    public function test_one_member_declining_does_not_end_the_queue_call(): void
    {
        Event::fake([CallQueueEnded::class, CallQueueMissed::class]);

        $this->signedPost($this->ringingPayload());

        // `phone.callee_missed` is per LEG. A queue rings every member's
        // handset on one call_id, so treating it as "the call ended" — which
        // this used to do — closed the pop on every screen the instant the
        // first person waved it away, while the other phones rang on.
        $this->signedPost([
            'event' => 'phone.callee_missed',
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ])->assertOk();

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        $this->assertNotNull($call);
        $this->assertNotNull($call->last_missed_at);

        Event::assertDispatched(CallQueueMissed::class);
        Event::assertNotDispatched(CallQueueEnded::class);
    }

    public function test_a_closing_event_for_an_unknown_call_stays_quiet(): void
    {
        Event::fake([CallQueueEnded::class]);

        $this->signedPost([
            'event' => 'phone.caller_ended',
            'payload' => ['object' => ['call_id' => 'never-seen']],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        // A stray broadcast would only make other tabs flicker.
        Event::assertNotDispatched(CallQueueEnded::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Robustness
    |--------------------------------------------------------------------------
    */

    public function test_an_unrecognised_event_is_acknowledged(): void
    {
        $this->signedPost([
            'event' => 'phone.something_else',
            'payload' => ['object' => []],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_a_malformed_payload_never_errors_back_to_zoom(): void
    {
        // Zoom retries, then DISABLES, endpoints that error or answer slowly -
        // so a payload this code cannot understand must still be a 200.
        $this->signedPost(['event' => 'phone.callee_ringing'])->assertOk();
        $this->signedPost([])->assertOk();
    }

    public function test_the_module_ships_disabled(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertFalse($shipped['call_queue']['enabled']);
        $this->assertSame(
            'call-queue-monitor',
            $shipped['call_queue']['channel']
        );
    }
}
