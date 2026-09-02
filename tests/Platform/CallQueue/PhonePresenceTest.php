<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Events\PhonePresenceUpdated;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Models\ZoomPhoneLiveCall;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomPhoneUserDirectory;
use Visnsstudio\VisnsPackages\Support\CallQueueChannel;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\StubCallerEnrichment;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The Zoom Phone roster: the webhook half that records who is on a call, and the
 * endpoint that joins it to the cached `/phone/users` directory.
 *
 * The Zoom API is stubbed throughout — what is under test is the join, the leg
 * bookkeeping and the pruning, not HTTP.
 */
class PhonePresenceTest extends TestCase
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
        $app['config']->set('visns-packages.call_queue.presence.enabled', true);
        $app['config']->set(
            'visns-packages.call_queue.webhook_secret_token',
            self::SECRET
        );
        $app['config']->set(
            'visns-packages.call_queue.caller_enrichment',
            StubCallerEnrichment::class
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
            '2026_08_27_090000_create_zoom_phone_live_calls_table.php'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

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

    /** Post a payload signed the way Zoom signs it. */
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

    private function event(string $event, array $object): array
    {
        return ['event' => $event, 'payload' => ['object' => $object]];
    }

    /** An outside caller ringing extension 208 directly. */
    private function inboundRinging(array $overrides = []): array
    {
        return $this->event('phone.callee_ringing', array_merge([
            'call_id' => 'call-1',
            'caller' => [
                'phone_number' => '+61412345678',
                'name' => 'Cleo Client',
            ],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_id' => 'ext-id-208',
                'extension_number' => '208',
                'name' => 'Reyhan Thee',
            ],
            'ringing_start_time' => '2026-08-27T03:04:05Z',
        ], $overrides));
    }

    /**
     * Swap the directory for one that answers without touching Zoom.
     *
     * @param  array<int, array<string, mixed>>  $users
     */
    private function fakeDirectory(array $users, bool $configured = true, ?string $error = null): void
    {
        $fake = new class($users, $configured, $error) extends ZoomPhoneUserDirectory {
            public function __construct(
                private array $users,
                private bool $isConfigured,
                private ?string $error
            ) {
                // No parent constructor: a directory that never calls out needs
                // no credentials.
            }

            public function configured(): bool
            {
                return $this->isConfigured;
            }

            public function users(bool $fresh = false): array
            {
                if ($this->error !== null) {
                    return ['success' => false, 'users' => [], 'error' => $this->error];
                }

                return ['success' => true, 'users' => $this->users];
            }
        };

        $this->app->instance(ZoomPhoneUserDirectory::class, $fake);
    }

    private function rosterUser(array $overrides = []): array
    {
        return array_merge([
            'id' => 'zoom-user-208',
            'name' => 'Reyhan Thee',
            'email' => 'reyhan@example.test',
            'extension_number' => '208',
            'active' => true,
            'phone_numbers' => ['+61390000208'],
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    */

    public function test_an_inbound_ring_on_a_user_extension_is_recorded(): void
    {
        $this->signedPost($this->inboundRinging())->assertOk();

        $call = ZoomPhoneLiveCall::first();

        $this->assertNotNull($call);
        $this->assertSame('call-1', $call->call_id);
        $this->assertSame('zoom-user-208', $call->zoom_user_id);
        $this->assertSame('208', $call->extension_number);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('ringing', $call->status);
        $this->assertSame('+61412345678', $call->peer_number);
        $this->assertSame('Cleo Client', $call->peer_name);
    }

    public function test_the_peer_number_is_resolved_to_a_client_once(): void
    {
        $this->signedPost($this->inboundRinging())->assertOk();
        $this->signedPost($this->event('phone.callee_answered', [
            'call_id' => 'call-1',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
        ]))->assertOk();

        $call = ZoomPhoneLiveCall::first();

        $this->assertSame(
            ['id' => 7, 'name' => 'Cleo Client', 'open_tasks' => 2],
            $call->client_preview
        );

        // The enrichment hook queries the CRM, so it runs once per leg and not
        // again on the answer event that already has an answer.
        //
        // Twice on the ringing leg, once each: this payload rings a user's own
        // extension, so it is now BOTH a roster leg and a direct call pop, and
        // the two features resolve the caller independently. The answer event
        // adds nothing, which is what this is really guarding.
        $this->assertSame(
            ['+61412345678', '+61412345678'],
            StubCallerEnrichment::$calls
        );
    }

    public function test_a_ringing_leg_becomes_active_when_it_is_answered(): void
    {
        $this->signedPost($this->inboundRinging())->assertOk();
        $this->signedPost($this->event('phone.callee_answered', [
            'call_id' => 'call-1',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
        ]))->assertOk();

        $this->assertSame(1, ZoomPhoneLiveCall::count());

        $call = ZoomPhoneLiveCall::first();

        $this->assertSame('active', $call->status);
        $this->assertNotNull($call->answered_at);
    }

    /**
     * The whole reason the roster exists as a separate table: the queue's own
     * row is deleted on pickup, because the pop is done with it. The roster's
     * has to survive, or nobody is ever shown as being on a call.
     */
    public function test_answering_clears_the_queue_row_but_keeps_the_presence_row(): void
    {
        $this->signedPost($this->event('phone.callee_ringing', [
            'call_id' => 'call-q',
            'caller' => ['phone_number' => '+61412345678', 'name' => 'Cleo Client'],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
                'name' => 'Reyhan Thee',
            ],
            'forwarded_by' => [
                'extension_type' => 'callqueue',
                'extension_id' => 'queue-1',
                'name' => 'Reception',
            ],
        ]))->assertOk();

        $this->assertSame(1, ZoomLiveQueueCall::count());
        $this->assertSame(1, ZoomPhoneLiveCall::count());
        $this->assertSame('Reception', ZoomPhoneLiveCall::first()->queue_name);

        $this->signedPost($this->event('phone.callee_answered', [
            'call_id' => 'call-q',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
        ]))->assertOk();

        $this->assertSame(0, ZoomLiveQueueCall::count());
        $this->assertSame('active', ZoomPhoneLiveCall::first()->status);
    }

    public function test_an_outbound_leg_is_recorded_from_the_caller_side(): void
    {
        $this->signedPost($this->event('phone.caller_ringing', [
            'call_id' => 'call-out',
            'caller' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
                'name' => 'Reyhan Thee',
            ],
            'callee' => ['phone_number' => '+61412345678'],
        ]))->assertOk();

        $call = ZoomPhoneLiveCall::first();

        $this->assertNotNull($call);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame('+61412345678', $call->peer_number);

        // Outbound legs are not queue traffic and must not reach the pop.
        $this->assertSame(0, ZoomLiveQueueCall::count());
    }

    /**
     * Webhooks arrive out of order often enough to matter. A connected call that
     * flips back to "ringing" because a delayed delivery landed after the answer
     * is the roster telling the office something untrue.
     */
    public function test_a_late_ringing_event_cannot_un_answer_a_call(): void
    {
        $this->signedPost($this->inboundRinging())->assertOk();
        $this->signedPost($this->event('phone.callee_answered', [
            'call_id' => 'call-1',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
        ]))->assertOk();

        $answeredAt = ZoomPhoneLiveCall::first()->answered_at;

        // The delayed duplicate.
        $this->signedPost($this->inboundRinging())->assertOk();

        $call = ZoomPhoneLiveCall::first();

        $this->assertSame('active', $call->status);
        // And the duration everybody is reading did not restart.
        $this->assertTrue($answeredAt->equalTo($call->answered_at));
    }

    public function test_a_call_queue_extension_is_not_a_roster_row(): void
    {
        $this->signedPost($this->event('phone.callee_ringing', [
            'call_id' => 'call-2',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'callqueue',
                'extension_id' => 'queue-1',
                'name' => 'Reception',
            ],
        ]))->assertOk();

        // The pop still gets its row; the roster does not, because a queue is
        // not a person with a handset.
        $this->assertSame(1, ZoomLiveQueueCall::count());
        $this->assertSame(0, ZoomPhoneLiveCall::count());
    }

    public function test_ending_a_call_clears_every_leg_of_it(): void
    {
        // An internal call: both legs belong to us, under one call_id.
        $this->signedPost($this->event('phone.caller_ringing', [
            'call_id' => 'call-int',
            'caller' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-209',
                'extension_number' => '209',
            ],
        ]))->assertOk();

        $this->signedPost($this->event('phone.callee_ringing', [
            'call_id' => 'call-int',
            'caller' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-209',
                'extension_number' => '209',
            ],
        ]))->assertOk();

        $this->assertSame(2, ZoomPhoneLiveCall::count());

        $this->signedPost($this->event('phone.caller_ended', [
            'call_id' => 'call-int',
        ]))->assertOk();

        $this->assertSame(0, ZoomPhoneLiveCall::count());
    }

    /**
     * Zoom fans one call_id out across every handset a queue rings, so the leg
     * key — not the call id — is what keeps them apart. Without it the fifth
     * ringing phone would overwrite the first four into one row.
     */
    public function test_one_call_ringing_several_handsets_keeps_a_leg_each(): void
    {
        foreach (['208', '209', '210'] as $extension) {
            $this->signedPost($this->event('phone.callee_ringing', [
                'call_id' => 'call-fan',
                'caller' => ['phone_number' => '+61412345678'],
                'callee' => [
                    'extension_type' => 'user',
                    'user_id' => 'zoom-user-' . $extension,
                    'extension_number' => $extension,
                ],
            ]))->assertOk();
        }

        $this->assertSame(3, ZoomPhoneLiveCall::count());

        // One member declining takes only that member's leg.
        $this->signedPost($this->event('phone.callee_missed', [
            'call_id' => 'call-fan',
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-209',
                'extension_number' => '209',
            ],
        ]))->assertOk();

        $this->assertSame(2, ZoomPhoneLiveCall::count());
        $this->assertSame(
            0,
            ZoomPhoneLiveCall::where('extension_number', '209')->count()
        );
    }

    public function test_nothing_is_recorded_when_presence_is_switched_off(): void
    {
        config()->set('visns-packages.call_queue.presence.enabled', false);

        $this->signedPost($this->inboundRinging([
            'forwarded_by' => [
                'extension_type' => 'callqueue',
                'extension_id' => 'queue-1',
                'name' => 'Reception',
            ],
        ]))->assertOk();

        $this->assertSame(0, ZoomPhoneLiveCall::count());
        // The call queue is untouched by the switch.
        $this->assertSame(1, ZoomLiveQueueCall::count());
    }

    public function test_a_change_is_broadcast_on_the_call_queue_channel(): void
    {
        Event::fake([PhonePresenceUpdated::class]);

        $this->signedPost($this->inboundRinging())->assertOk();

        Event::assertDispatched(
            PhonePresenceUpdated::class,
            function (PhonePresenceUpdated $event) {
                $payload = $event->broadcastWith();

                $this->assertFalse($payload['cleared']);
                $this->assertContains('zoom-user-208', $payload['keys']);
                $this->assertSame('+61412345678', $payload['call']['peer_number']);
                $this->assertSame('inbound', $payload['call']['direction']);
                $this->assertSame('phone.presence', $event->broadcastAs());
                // Same channel the pop already listens on, so there is one
                // /broadcasting/auth round trip and one registration in the
                // consuming application's channels.php.
                $this->assertSame(
                    'private-' . CallQueueChannel::name(),
                    $event->broadcastOn()->name
                );

                return true;
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The roster endpoint
    |--------------------------------------------------------------------------
    */

    public function test_the_roster_needs_the_monitor_permission(): void
    {
        $this->fakeDirectory([$this->rosterUser()]);

        $this->actingAs($this->staffWith())
            ->getJson('/ajax/call-queue/presence')
            ->assertStatus(403);
    }

    public function test_the_roster_joins_the_directory_to_the_live_calls(): void
    {
        $this->fakeDirectory([
            $this->rosterUser(),
            $this->rosterUser([
                'id' => 'zoom-user-209',
                'name' => 'Ada Adviser',
                'email' => 'ada@example.test',
                'extension_number' => '209',
            ]),
        ]);

        $this->signedPost($this->inboundRinging())->assertOk();

        $response = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk();

        $body = $response->json();

        $this->assertTrue($body['configured']);
        $this->assertNull($body['error']);
        $this->assertSame(CallQueueChannel::name(), $body['channel']);
        $this->assertSame('.phone.presence', $body['event']);
        $this->assertSame(1, $body['on_call_count']);
        $this->assertCount(2, $body['users']);

        [$reyhan, $ada] = $body['users'];

        $this->assertSame('ringing', $reyhan['status']);
        $this->assertSame('208', $reyhan['extension_number']);
        $this->assertSame('+61412345678', $reyhan['call']['peer_number']);
        $this->assertSame('Cleo Client', $reyhan['call']['client']['name']);

        $this->assertSame('available', $ada['status']);
        $this->assertNull($ada['call']);
    }

    public function test_an_answered_call_reads_as_on_a_call(): void
    {
        $this->fakeDirectory([$this->rosterUser()]);

        $this->signedPost($this->inboundRinging())->assertOk();
        $this->signedPost($this->event('phone.callee_answered', [
            'call_id' => 'call-1',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'user',
                'user_id' => 'zoom-user-208',
                'extension_number' => '208',
            ],
        ]))->assertOk();

        $body = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk()
            ->json();

        $this->assertSame('on_call', $body['users'][0]['status']);
        $this->assertNotNull($body['users'][0]['call']['answered_at']);
    }

    /**
     * A leg on an extension the cached directory has never heard of — a
     * common-area handset, or somebody hired since the cache was filled. Showing
     * it separately beats dropping it and telling the office everyone is free.
     */
    public function test_a_call_on_an_unknown_extension_is_still_reported(): void
    {
        $this->fakeDirectory([
            $this->rosterUser([
                'id' => 'zoom-user-209',
                'name' => 'Ada Adviser',
                'extension_number' => '209',
            ]),
        ]);

        $this->signedPost($this->inboundRinging())->assertOk();

        $body = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk()
            ->json();

        $this->assertNull($body['users'][0]['call']);
        $this->assertCount(1, $body['unmatched_calls']);
        $this->assertSame('Reyhan Thee', $body['unmatched_calls'][0]['name']);
        $this->assertSame(1, $body['on_call_count']);
    }

    public function test_an_extension_is_matched_on_its_number_when_the_user_id_is_missing(): void
    {
        $this->fakeDirectory([$this->rosterUser(['id' => 'some-other-id'])]);

        $this->signedPost($this->event('phone.callee_ringing', [
            'call_id' => 'call-3',
            'caller' => ['phone_number' => '+61412345678'],
            'callee' => [
                'extension_type' => 'user',
                'extension_number' => '208',
                'name' => 'Reyhan Thee',
            ],
        ]))->assertOk();

        $body = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk()
            ->json();

        $this->assertSame('ringing', $body['users'][0]['status']);
        $this->assertEmpty($body['unmatched_calls']);
    }

    public function test_a_ring_zoom_never_closed_is_pruned(): void
    {
        $this->fakeDirectory([$this->rosterUser()]);

        $this->signedPost($this->inboundRinging())->assertOk();

        ZoomPhoneLiveCall::query()->update([
            'started_at' => now()->subMinutes(30),
        ]);

        $body = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk()
            ->json();

        $this->assertSame('available', $body['users'][0]['status']);
        $this->assertSame(0, ZoomPhoneLiveCall::count());
    }

    public function test_an_unconfigured_tenant_says_so(): void
    {
        $this->fakeDirectory([], false);

        $body = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk()
            ->json();

        $this->assertFalse($body['configured']);
        $this->assertSame([], $body['users']);
    }

    public function test_a_zoom_failure_is_reported_rather_than_shown_as_an_empty_office(): void
    {
        $this->fakeDirectory([], true, 'Invalid access token.');

        $body = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/presence')
            ->assertOk()
            ->json();

        $this->assertTrue($body['configured']);
        $this->assertSame('Invalid access token.', $body['error']);
        $this->assertSame([], $body['users']);
    }

    public function test_the_route_exists_while_presence_is_enabled(): void
    {
        // The off case is PhonePresenceDisabledTest: routes are registered at
        // boot, so switching the flag afterwards proves nothing.
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => $route->uri() === 'ajax/call-queue/presence'
            )
        );
    }
}
