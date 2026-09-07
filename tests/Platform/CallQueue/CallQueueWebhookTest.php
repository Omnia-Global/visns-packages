<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Events\CallQueueAnswered;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueMissed;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
        // The per-leg map. Zoom rings (and ends) one call_id once per leg,
        // so every ringing webhook writes to this column.
        $this->runPackageMigration(
            '2026_09_07_100000_add_legs_to_zoom_live_queue_calls_table.php'
        );
        $this->runPackageMigration(
            '2026_08_19_210100_create_zoom_call_queue_settings_table.php'
        );
        // The webhook ledger, so the per-leg tests can assert on the word the
        // diagnostics screen shows for each delivery.
        $this->runPackageMigration(
            '2026_09_02_100000_create_zoom_webhook_events_table.php'
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

    /**
     * One LEG of a queue call, shaped the way production sends it: the callee is
     * the member's own extension and the queue only appears under forwarded_by.
     */
    private function queueLegPayload(array $callee): array
    {
        return $this->ringingPayload([
            'callee' => $callee,
            'forwarded_by' => [
                'extension_type' => 'call_queue',
                'extension_id' => 'queue-1',
                'name' => 'Reception',
            ],
        ]);
    }

    /** `phone.callee_ended` for one leg. Zoom often names no callee at all. */
    private function calleeEndedPayload(array $callee = []): array
    {
        return [
            'event' => 'phone.callee_ended',
            'payload' => [
                'object' => array_merge(
                    ['call_id' => 'call-abc-123'],
                    $callee === [] ? [] : ['callee' => $callee]
                ),
            ],
        ];
    }

    /** The ledger's word for each delivery, in order. */
    private function outcomes(): array
    {
        return ZoomWebhookEvent::orderBy('id')->pluck('outcome')->all();
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

    private function snapshot()
    {
        return $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/live');
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
    | Ending, one leg at a time
    |--------------------------------------------------------------------------
    |
    | `phone.callee_ended` is per LEG, exactly as `phone.callee_missed` is. On a
    | real call (7681211823904341133) production saw ringing(204), ringing(204),
    | callee_ended, callee_answered, callee_ended — and the FIRST ended closed
    | the pop on every screen, a moment before somebody actually picked the call
    | up. The rule the office asked for: if it stops ringing, it should close for
    | everyone — so the pop closes when the LAST leg stops, not the first.
    */

    public function test_one_leg_ending_does_not_close_a_call_the_others_are_ringing(): void
    {
        Event::fake([CallQueueEnded::class, CallQueueAnswered::class]);

        // One extension, two handsets (desk phone and Zoom app): two ringing
        // events on one call_id, and Zoom named no device on either.
        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));
        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));

        $this->signedPost($this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]))->assertOk()->assertJsonPath('status', 'ok');

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        $this->assertNotNull($call);
        $this->assertSame(1, $call->ringingLegCount());

        // Nothing is broadcast at all: a leg ending while other handsets ring is
        // invisible to the pop by design, and an event here would only make
        // every watching tab flicker.
        Event::assertNotDispatched(CallQueueEnded::class);

        // Its own ledger word, so the diagnostics screen shows the fix working
        // rather than an event that went missing.
        $this->assertContains('ended_leg', $this->outcomes());
    }

    public function test_the_last_leg_ending_closes_the_call_for_everyone(): void
    {
        Event::fake([CallQueueEnded::class]);

        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));
        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));

        $this->signedPost($this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));
        $this->signedPost($this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]))->assertOk();

        $this->assertSame(0, ZoomLiveQueueCall::count());

        // Once, on the leg that took the count to zero — the closing path is the
        // same one an answer or a caller hangup goes through, so the event class
        // and the ledger's word for it are unchanged.
        Event::assertDispatchedTimes(CallQueueEnded::class, 1);
        $this->assertContains('closed', $this->outcomes());
    }

    public function test_legs_are_keyed_by_the_device_when_zoom_names_one(): void
    {
        Event::fake([CallQueueEnded::class]);

        foreach (['device-desk', 'device-app'] as $device) {
            $this->signedPost($this->queueLegPayload([
                'extension_type' => 'user',
                'extension_id' => '204',
                'device_id' => $device,
            ]));
        }

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        // Zoom said which handset, so the map says so too — no `#2` suffix
        // needed. This is the only field that distinguishes the two.
        $this->assertSame(
            ['device-desk', 'device-app'],
            array_map('strval', array_keys($call->legs))
        );

        $this->signedPost($this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
            'device_id' => 'device-desk',
        ]));

        $call->refresh();
        $this->assertSame(1, $call->ringingLegCount());
        $this->assertSame('ended', $call->legs['device-desk']['state']);
        Event::assertNotDispatched(CallQueueEnded::class);

        $this->signedPost($this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
            'device_id' => 'device-app',
        ]));

        $this->assertSame(0, ZoomLiveQueueCall::count());
        Event::assertDispatchedTimes(CallQueueEnded::class, 1);
    }

    public function test_a_retried_ended_delivery_does_not_take_a_second_leg_down(): void
    {
        Event::fake([CallQueueEnded::class]);

        foreach (['device-desk', 'device-app'] as $device) {
            $this->signedPost($this->queueLegPayload([
                'extension_type' => 'user',
                'extension_id' => '204',
                'device_id' => $device,
            ]));
        }

        $ended = $this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
            'device_id' => 'device-desk',
        ]);

        $this->signedPost($ended);
        // Zoom retries. The second delivery finds its leg already settled and
        // must settle nothing else — otherwise a retry closes a call that is
        // still ringing on the other handset.
        $this->signedPost($ended)->assertOk();

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        $this->assertNotNull($call);
        $this->assertSame(1, $call->ringingLegCount());
        Event::assertNotDispatched(CallQueueEnded::class);
    }

    public function test_the_caller_hanging_up_closes_the_call_whatever_the_legs_say(): void
    {
        Event::fake([CallQueueEnded::class]);

        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));
        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '206',
        ]));

        // `phone.caller_ended` is the caller ringing off. Every handset in the
        // queue stops at once, so the leg count is beside the point.
        $this->signedPost([
            'event' => 'phone.caller_ended',
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ])->assertOk();

        $this->assertSame(0, ZoomLiveQueueCall::count());
        Event::assertDispatchedTimes(CallQueueEnded::class, 1);
    }

    public function test_an_answer_after_a_leg_ended_still_closes_the_call(): void
    {
        Event::fake([CallQueueAnswered::class, CallQueueEnded::class]);

        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));
        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '206',
        ]));

        // The production ordering that started this: one leg ends, and the
        // answer follows. The card has to survive the end and then close as an
        // ANSWER, not as a hangup.
        $this->signedPost($this->calleeEndedPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));

        $this->assertSame(1, ZoomLiveQueueCall::count());

        $this->signedPost([
            'event' => 'phone.callee_answered',
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ])->assertOk();

        $this->assertSame(0, ZoomLiveQueueCall::count());
        Event::assertDispatchedTimes(CallQueueAnswered::class, 1);
        Event::assertNotDispatched(CallQueueEnded::class);
    }

    public function test_a_missed_leg_leaves_the_other_leg_ringing(): void
    {
        Event::fake([CallQueueEnded::class, CallQueueMissed::class]);

        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '204',
        ]));
        $this->signedPost($this->queueLegPayload([
            'extension_type' => 'user',
            'extension_id' => '206',
        ]));

        // 204 declined; 206 is still ringing. A miss settles its leg exactly as
        // an end does — it just also stamps the row, because a call every leg
        // has missed keeps its card for the grace window rather than closing.
        $this->signedPost([
            'event' => 'phone.callee_missed',
            'payload' => ['object' => [
                'call_id' => 'call-abc-123',
                'callee' => ['extension_type' => 'user', 'extension_id' => '204'],
            ]],
        ])->assertOk();

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-abc-123');

        $this->assertSame(1, $call->ringingLegCount());
        $this->assertSame('missed', $call->legs['204']['state']);
        $this->assertNotNull($call->last_missed_at);

        Event::assertDispatched(CallQueueMissed::class);
        Event::assertNotDispatched(CallQueueEnded::class);
    }

    public function test_a_row_from_before_the_leg_map_still_closes_on_the_first_ended(): void
    {
        Event::fake([CallQueueEnded::class]);

        // Written by the previous release: no legs at all. "We do not know"
        // must not mean "keep the card up until the stale sweep", so the old
        // behaviour stands for these.
        ZoomLiveQueueCall::create([
            'call_id' => 'call-abc-123',
            'queue_id' => 'queue-1',
            'queue_name' => 'Reception',
            'status' => 'ringing',
            'started_at' => Carbon::now(),
            'last_ringing_at' => Carbon::now(),
        ]);

        $this->signedPost($this->calleeEndedPayload())->assertOk();

        $this->assertSame(0, ZoomLiveQueueCall::count());
        Event::assertDispatchedTimes(CallQueueEnded::class, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | The stale-ring safety net
    |--------------------------------------------------------------------------
    */

    public function test_a_call_nothing_has_rung_for_minutes_stops_being_live(): void
    {
        $this->signedPost($this->ringingPayload());

        $this->assertSame(1, ZoomLiveQueueCall::live()->count());

        // Now that the pop closes on the leg count reaching zero rather than on
        // the first ended event, a closing event Zoom never delivered would
        // otherwise leave a phantom card ringing on every screen.
        Carbon::setTestNow(Carbon::now()->addMinutes(3));

        $this->assertSame(0, ZoomLiveQueueCall::live()->count());
    }

    public function test_the_snapshot_publishes_the_windows_the_pop_ages_cards_on(): void
    {
        // The browser has to age a card out on exactly the server's rules, or
        // the two disagree about when a call stopped being live — which is the
        // flicker the whole mechanism exists to avoid. Milliseconds, because
        // that is what a browser timer takes.
        $this->snapshot()
            ->assertOk()
            ->assertJsonPath('channel', CallQueueChannel::name())
            ->assertJsonPath('missed_grace_ms', 20000)
            ->assertJsonPath('max_ringing_ms', 120000);
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
        // The stale-ring safety net, comfortably longer than any queue's ring
        // timeout so it only ever catches a closing event Zoom lost.
        $this->assertSame(120, $shipped['call_queue']['max_ringing_seconds']);
    }
}
