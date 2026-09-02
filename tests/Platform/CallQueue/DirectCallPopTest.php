<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueMissed;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomCallQueueService;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Calls that ring a person rather than a queue.
 *
 * Two behaviours meet here, and they were both found the same way — in the
 * webhook ledger, on a live account:
 *
 *  1. Every `ringing_unmatched` row had `callee.extension_type = "user"` and no
 *     `forwarded_by`. Direct dials, internal calls and transfers: the calls
 *     nobody else's phone is ringing to cover, and the pop was dropping all of
 *     them.
 *
 *  2. Those calls arrive on TWO legs sharing one call_id (the desk phone and
 *     the mobile app), and a queue call arrives on as many legs as the queue
 *     has members. Zoom sends `phone.callee_missed` per leg — so treating it as
 *     "the call ended", which is what the old ENDED_EVENTS list did, closed the
 *     card the instant the first leg gave up while the rest were still ringing.
 */
class DirectCallPopTest extends TestCase
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
        $this->runPackageMigration(
            '2026_08_19_210100_create_zoom_call_queue_settings_table.php'
        );
        $this->runPackageMigration(
            '2026_09_02_100000_create_zoom_webhook_events_table.php'
        );
        $this->runPackageMigration(
            '2026_09_02_120000_add_kind_and_callee_to_zoom_live_queue_calls_table.php'
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    /**
     * A call ringing a person's own extension — the shape production actually
     * sends, callee_type "user" and no forwarded_by.
     */
    private function directPayload(array $overrides = []): array
    {
        return [
            'event' => 'phone.callee_ringing',
            'payload' => [
                'object' => array_merge([
                    'call_id' => 'call-direct-1',
                    'caller' => [
                        'phone_number' => '+61412345678',
                        'name' => 'Cleo Client',
                    ],
                    'callee' => [
                        'extension_type' => 'user',
                        'extension_id' => 'user-9',
                        'extension_number' => '208',
                        'name' => 'Steve Adviser',
                    ],
                    'ringing_start_time' => '2026-09-02T03:04:05Z',
                ], $overrides),
            ],
        ];
    }

    private function missedPayload(string $callId = 'call-direct-1'): array
    {
        return [
            'event' => 'phone.callee_missed',
            'payload' => ['object' => ['call_id' => $callId]],
        ];
    }

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
    | A direct call becomes a live call
    |--------------------------------------------------------------------------
    */

    public function test_a_call_ringing_a_personal_extension_is_recorded_and_broadcast(): void
    {
        Event::fake([CallQueueRinging::class]);

        $this->signedPost($this->directPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-direct-1');

        $this->assertNotNull($call);
        $this->assertSame('direct', $call->kind);
        // No queue rang it, so there is no queue to name.
        $this->assertNull($call->queue_id);
        $this->assertNull($call->queue_name);
        $this->assertSame('Steve Adviser', $call->callee_name);
        $this->assertSame('user-9', $call->callee_extension_id);
        $this->assertSame('208', $call->callee_extension_number);
        $this->assertSame('user', $call->callee_extension_type);
        $this->assertSame('+61412345678', $call->caller_number);
        $this->assertNotNull($call->last_ringing_at);

        Event::assertDispatched(CallQueueRinging::class);

        // The ledger tells the two kinds of recorded call apart, which is what
        // makes "are direct calls popping at all" answerable from the
        // diagnostics screen.
        $this->assertContains('ringing_recorded_direct', $this->outcomes());
    }

    public function test_the_direct_pop_payload_carries_who_it_is_ringing(): void
    {
        $this->signedPost($this->directPayload([
            'forwarded_by' => [
                'extension_type' => 'user',
                'extension_id' => 'user-3',
                'name' => 'Reception Rita',
            ],
        ]));

        $payload = ZoomLiveQueueCall::firstWhere('call_id', 'call-direct-1')
            ->toPopPayload();

        // Every key the front end already parsed, in the same place, plus the
        // direct-call ones after them.
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

        $this->assertSame('direct', $payload['kind']);
        $this->assertNull($payload['queue_id']);
        // The badge still has to say something.
        $this->assertSame('Direct', $payload['queue_name']);
        $this->assertSame('Steve Adviser', $payload['callee_name']);
        $this->assertSame('208', $payload['callee_extension']);
        // "Transferred by Rita" is the whole reason a transferred call is worth
        // popping.
        $this->assertSame('Reception Rita', $payload['forwarded_by_name']);
        // One pseudo-queue key for every direct call: Zoom has one code for
        // grabbing a call ringing a person rather than a queue.
        $this->assertSame('direct', $payload['pickup_key']);
    }

    public function test_a_queue_call_is_unchanged_and_keys_its_pickup_on_the_queue(): void
    {
        $this->signedPost([
            'event' => 'phone.callee_ringing',
            'payload' => [
                'object' => [
                    'call_id' => 'call-queue-1',
                    'caller' => ['phone_number' => '+61412345678'],
                    'callee' => [
                        'extension_type' => 'callqueue',
                        'extension_id' => 'queue-1',
                        'name' => 'Reception',
                    ],
                ],
            ],
        ])->assertOk();

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-queue-1');
        $payload = $call->toPopPayload();

        $this->assertSame('queue', $call->kind);
        $this->assertSame('queue', $payload['kind']);
        $this->assertSame('Reception', $payload['queue_name']);
        $this->assertSame('queue-1', $payload['pickup_key']);
        // The callee columns are a direct call's subject; a queue pop's subject
        // is the queue, not whichever handset the routing reached first.
        $this->assertSame('', $payload['callee_name']);
        $this->assertContains('ringing_recorded', $this->outcomes());
    }

    public function test_a_common_area_handset_is_a_direct_call_too(): void
    {
        $this->signedPost($this->directPayload([
            'callee' => [
                'extension_type' => 'commonArea',
                'extension_id' => 'ca-1',
                'name' => 'Boardroom',
            ],
        ]))->assertOk();

        // Case-insensitive: Zoom spells this one at least two ways.
        $this->assertSame(
            'direct',
            ZoomLiveQueueCall::firstWhere('call_id', 'call-direct-1')->kind
        );
    }

    public function test_an_auto_receptionist_is_still_unmatched(): void
    {
        Event::fake([CallQueueRinging::class]);

        $this->signedPost($this->directPayload([
            'callee' => [
                'extension_type' => 'autoReceptionist',
                'extension_id' => 'ar-1',
                'name' => 'Main IVR',
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        // A routing object is not somebody's phone: nothing is ringing yet.
        $this->assertSame(0, ZoomLiveQueueCall::count());
        $this->assertContains('ringing_unmatched', $this->outcomes());
        Event::assertNotDispatched(CallQueueRinging::class);
    }

    public function test_the_master_switch_puts_direct_calls_back_to_unmatched(): void
    {
        config()->set('visns-packages.call_queue.direct_calls.enabled', false);

        $this->signedPost($this->directPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        // Off means "this build does not do it" — indistinguishable from the
        // behaviour before the feature existed.
        $this->assertSame(0, ZoomLiveQueueCall::count());
        $this->assertContains('ringing_unmatched', $this->outcomes());
    }

    public function test_the_operator_can_switch_direct_pops_off(): void
    {
        Event::fake([CallQueueRinging::class]);

        ZoomCallQueueSetting::create([
            'queue_id' => 'direct',
            'excluded' => true,
        ]);
        ZoomCallQueueSetting::flushCache();

        $this->signedPost($this->directPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, ZoomLiveQueueCall::count());
        Event::assertNotDispatched(CallQueueRinging::class);

        // Its own outcome word, not `ringing_excluded_queue`: on the
        // diagnostics screen "the office turned direct pops off" and "somebody
        // excluded a queue" are different answers to the same question.
        $this->assertContains('ringing_excluded_direct', $this->outcomes());
    }

    public function test_an_excluded_queue_still_wins_over_the_direct_branch(): void
    {
        ZoomCallQueueSetting::create(['queue_id' => 'queue-9', 'excluded' => true]);
        ZoomCallQueueSetting::flushCache();

        // The callee is a member's handset, but the call is the excluded
        // queue's — dropping it is the operator's stated intent.
        $this->signedPost($this->directPayload([
            'forwarded_by' => [
                'extension_type' => 'call_queue',
                'extension_id' => 'queue-9',
                'name' => 'Overflow',
            ],
        ]))->assertJsonPath('status', 'ignored');

        $this->assertSame(0, ZoomLiveQueueCall::count());
        $this->assertContains('ringing_excluded_queue', $this->outcomes());
    }

    public function test_the_second_leg_of_a_direct_call_does_not_duplicate_it(): void
    {
        // A direct call rings the desk phone and the mobile app, and both legs
        // carry the same call_id.
        $this->signedPost($this->directPayload());
        $this->signedPost($this->directPayload([
            'callee' => [
                'extension_type' => 'user',
                'extension_id' => 'user-9',
                'extension_number' => '208',
                'name' => 'Steve Adviser (app)',
            ],
        ]));

        $this->assertSame(1, ZoomLiveQueueCall::count());
        $this->assertSame(
            'Steve Adviser (app)',
            ZoomLiveQueueCall::first()->callee_name
        );
    }

    /*
    |--------------------------------------------------------------------------
    | A declined leg is not the end of the call
    |--------------------------------------------------------------------------
    */

    public function test_a_missed_leg_keeps_the_row_and_announces_itself(): void
    {
        Event::fake([CallQueueMissed::class, CallQueueEnded::class]);

        $this->signedPost($this->directPayload());
        $this->signedPost($this->missedPayload())
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $call = ZoomLiveQueueCall::firstWhere('call_id', 'call-direct-1');

        // Kept: another leg may still be ringing on the same call_id.
        $this->assertNotNull($call);
        $this->assertNotNull($call->last_missed_at);

        Event::assertDispatched(
            CallQueueMissed::class,
            fn(CallQueueMissed $event) => $event->callId === 'call-direct-1'
        );
        // Emphatically not "the call ended".
        Event::assertNotDispatched(CallQueueEnded::class);

        $this->assertContains('missed', $this->outcomes());
    }

    public function test_the_missed_broadcast_contract(): void
    {
        $event = new CallQueueMissed('call-direct-1');

        $this->assertSame('queue.missed', $event->broadcastAs());
        $this->assertSame(
            ['call_id' => 'call-direct-1'],
            $event->broadcastWith()
        );
    }

    public function test_a_miss_for_a_call_we_do_not_track_stays_quiet(): void
    {
        Event::fake([CallQueueMissed::class]);

        $this->signedPost($this->missedPayload('never-seen'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        Event::assertNotDispatched(CallQueueMissed::class);
        $this->assertContains('missed_no_match', $this->outcomes());
    }

    public function test_a_missed_call_stays_on_screen_for_the_grace_window(): void
    {
        $this->signedPost($this->directPayload());
        $this->signedPost($this->missedPayload());

        // Zoom's per-leg events arrive out of order often enough that a card
        // blinking off and back on is worse than one that lingers.
        $this->snapshot()
            ->assertOk()
            ->assertJsonCount(1, 'calls')
            ->assertJsonPath('calls.0.call_id', 'call-direct-1');
    }

    public function test_a_missed_call_drops_off_once_the_grace_expires(): void
    {
        $this->signedPost($this->directPayload());
        $this->signedPost($this->missedPayload());

        Carbon::setTestNow(Carbon::now()->addSeconds(30));

        $this->snapshot()->assertOk()->assertJsonCount(0, 'calls');

        // And the row goes with it, rather than waiting out the far longer
        // stale-ring window.
        $this->assertSame(0, ZoomLiveQueueCall::count());
    }

    public function test_ringing_again_after_a_miss_makes_the_call_live_again(): void
    {
        $this->signedPost($this->directPayload());
        $this->signedPost($this->missedPayload());

        // The desk phone gave up; the mobile app is still ringing.
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->signedPost($this->directPayload());

        Carbon::setTestNow(Carbon::now()->addSeconds(60));

        $this->snapshot()
            ->assertOk()
            ->assertJsonCount(1, 'calls')
            ->assertJsonPath('calls.0.call_id', 'call-direct-1');
    }

    public function test_a_queue_call_survives_one_member_declining(): void
    {
        $this->signedPost([
            'event' => 'phone.callee_ringing',
            'payload' => [
                'object' => [
                    'call_id' => 'call-queue-1',
                    'caller' => ['phone_number' => '+61412345678'],
                    'callee' => [
                        'extension_type' => 'callqueue',
                        'extension_id' => 'queue-1',
                        'name' => 'Reception',
                    ],
                ],
            ],
        ]);

        // The original fault: the first member to wave the call away closed the
        // pop on every screen while four other handsets were still ringing.
        $this->signedPost($this->missedPayload('call-queue-1'));

        $this->snapshot()->assertOk()->assertJsonCount(1, 'calls');
    }

    public function test_an_ending_event_still_ends_the_call(): void
    {
        Event::fake([CallQueueEnded::class]);

        $this->signedPost($this->directPayload());
        $this->signedPost([
            'event' => 'phone.caller_ended',
            'payload' => ['object' => ['call_id' => 'call-direct-1']],
        ])->assertOk();

        $this->assertSame(0, ZoomLiveQueueCall::count());
        Event::assertDispatched(CallQueueEnded::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The direct-calls settings row
    |--------------------------------------------------------------------------
    */

    /**
     * Swap the Zoom client for one that lists a single queue and records every
     * push, so a save that touched Zoom is visible.
     */
    private function fakeZoom(): object
    {
        $fake = new class extends ZoomCallQueueService {
            public array $pushed = [];

            public function __construct()
            {
                // No credentials needed for a client that never calls out.
            }

            public function listQueues(): array
            {
                return [
                    'success' => true,
                    'queues' => [[
                        'id' => 'queue-1',
                        'name' => 'Reception',
                        'extension_number' => 303,
                        'status' => 'active',
                        'phone_numbers' => [['number' => '+61390000000']],
                    ]],
                ];
            }

            public function setPickupCode(string $queueId, string $code): array
            {
                $this->pushed[] = ['set', $queueId, $code];

                return ['success' => true];
            }

            public function disablePickupCode(string $queueId): array
            {
                $this->pushed[] = ['disable', $queueId];

                return ['success' => true];
            }
        };

        $this->app->instance(ZoomCallQueueService::class, $fake);

        return $fake;
    }

    public function test_the_settings_list_ends_with_the_direct_calls_row(): void
    {
        $this->fakeZoom();

        $response = $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonCount(2, 'queues');

        $response
            ->assertJsonPath('queues.0.queue_id', 'queue-1')
            ->assertJsonPath('queues.1.id', 'direct')
            ->assertJsonPath('queues.1.name', 'Direct calls')
            ->assertJsonPath('queues.1.pseudo', true)
            ->assertJsonPath('queues.1.excluded', false)
            ->assertJsonPath('queues.1.pickup_code', null);

        $this->assertStringContainsString(
            'own extension',
            $response->json('queues.1.description')
        );
    }

    public function test_the_direct_row_is_listed_even_when_zoom_is_down(): void
    {
        $this->app->instance(
            ZoomCallQueueService::class,
            new class extends ZoomCallQueueService {
                public function __construct()
                {
                    // No credentials needed for a client that never calls out.
                }

                public function listQueues(): array
                {
                    return [
                        'success' => false,
                        'queues' => [],
                        'error' => 'Failed to reach the Zoom API',
                    ];
                }
            }
        );

        // The direct-calls switch is the application's own; it has to be
        // reachable on the day Zoom is not.
        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonPath('zoom_unreachable', true)
            ->assertJsonPath('queues.0.id', 'direct');
    }

    public function test_the_direct_row_is_never_listed_twice(): void
    {
        $this->fakeZoom();

        ZoomCallQueueSetting::create(['queue_id' => 'direct', 'excluded' => true]);

        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonCount(2, 'queues')
            ->assertJsonPath('queues.1.id', 'direct')
            ->assertJsonPath('queues.1.excluded', true);
    }

    public function test_saving_the_direct_row_stores_without_touching_zoom(): void
    {
        $fake = $this->fakeZoom();

        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->putJson('/ajax/call-queue/settings/direct', [
                'pickup_code' => '8781',
                'excluded' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queue.id', 'direct')
            ->assertJsonPath('queue.pickup_code', '8781')
            ->assertJsonPath('queue.excluded', true);

        // Zoom has no object to push a pseudo-queue's code to.
        $this->assertSame([], $fake->pushed);

        $setting = ZoomCallQueueSetting::firstWhere('queue_id', 'direct');

        $this->assertSame('8781', $setting->pickup_code);
        $this->assertTrue($setting->excluded);
        $this->assertFalse(ZoomCallQueueSetting::directPopsEnabled());
    }

    public function test_the_direct_code_obeys_the_same_rules(): void
    {
        $this->fakeZoom();

        // Same phones, same dial rules — a code Zoom would refuse is a code
        // that does not work, whoever stores it.
        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->putJson('/ajax/call-queue/settings/direct', ['pickup_code' => '1234'])
            ->assertStatus(422);

        $this->assertNull(
            ZoomCallQueueSetting::firstWhere('queue_id', 'direct')?->pickup_code
        );
    }

    public function test_the_snapshot_publishes_the_direct_pickup_code(): void
    {
        ZoomCallQueueSetting::create([
            'queue_id' => 'direct',
            'pickup_code' => '8781',
        ]);
        ZoomCallQueueSetting::flushCache();

        $this->signedPost($this->directPayload());

        // Keyed by the same `pickup_key` the call carries, so the card looks
        // its button up exactly as it does for a queue.
        $this->snapshot()
            ->assertOk()
            ->assertJsonPath('pickup_codes.direct', '*998781')
            ->assertJsonPath('calls.0.pickup_key', 'direct');
    }

    public function test_the_shipped_config_enables_direct_calls(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertTrue($shipped['call_queue']['direct_calls']['enabled']);
        $this->assertSame(
            ['user', 'commonarea', 'common_area'],
            $shipped['call_queue']['direct_calls']['extension_types']
        );
        $this->assertSame(20, $shipped['call_queue']['missed_grace_seconds']);
        $this->assertSame(
            CallQueueMissed::class,
            $shipped['call_queue']['events']['missed']
        );
    }
}
