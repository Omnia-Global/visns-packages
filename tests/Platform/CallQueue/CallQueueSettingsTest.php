<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomCallQueueService;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Settings -> Call Queues, and the snapshot the pop hydrates from on page load.
 *
 * The Zoom API is stubbed out: what is under test is the pickup-code rules and
 * the "never advertise a code Zoom refused" ordering, not HTTP.
 */
class CallQueueSettingsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.call_queue.enabled', true);
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
     * Swap the Zoom client for one that answers however this test needs.
     */
    private function fakeZoom(array $behaviour = []): void
    {
        $fake = new class($behaviour) extends ZoomCallQueueService {
            public array $pushed = [];

            public function __construct(private array $behaviour)
            {
                // Deliberately not calling the parent constructor: no
                // credentials are needed for a client that never calls out.
            }

            public function listQueues(): array
            {
                return $this->behaviour['listQueues'] ?? [
                    'success' => true,
                    'queues' => [
                        [
                            'id' => 'queue-1',
                            'name' => 'Reception',
                            'extension_number' => 303,
                            'status' => 'active',
                            'phone_numbers' => [['number' => '+61390000000']],
                        ],
                    ],
                ];
            }

            public function setPickupCode(string $queueId, string $code): array
            {
                $this->pushed[] = ['set', $queueId, $code];

                return $this->behaviour['setPickupCode'] ?? ['success' => true];
            }

            public function disablePickupCode(string $queueId): array
            {
                $this->pushed[] = ['disable', $queueId];

                return $this->behaviour['disablePickupCode'] ?? ['success' => true];
            }
        };

        $this->app->instance(ZoomCallQueueService::class, $fake);
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public function test_the_settings_page_needs_its_own_permission(): void
    {
        $this->fakeZoom();

        // Monitoring the phones and configuring them are separate decisions.
        $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/settings')
            ->assertStatus(403);
    }

    public function test_the_live_snapshot_needs_the_monitor_permission(): void
    {
        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/live')
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    public function test_the_queue_list_comes_from_zoom_merged_with_local_settings(): void
    {
        $this->fakeZoom();

        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-1',
            'pickup_code' => '8781',
            'excluded' => false,
        ]);

        $response = $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/settings')
            ->assertOk();

        $response
            ->assertJsonPath('zoom_unreachable', false)
            ->assertJsonPath('queues.0.queue_id', 'queue-1')
            ->assertJsonPath('queues.0.name', 'Reception')
            ->assertJsonPath('queues.0.pickup_code', '8781')
            ->assertJsonPath('queues.0.excluded', false);
    }

    public function test_zoom_being_down_still_returns_the_local_rows(): void
    {
        $this->fakeZoom([
            'listQueues' => [
                'success' => false,
                'queues' => [],
                'error' => 'Failed to reach the Zoom API',
            ],
        ]);

        ZoomCallQueueSetting::create(['queue_id' => 'queue-1', 'excluded' => true]);

        // The exclusion toggles still have to work with Zoom unreachable, so
        // this is a warning, not an error.
        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonPath('zoom_unreachable', true)
            ->assertJsonPath('queues.0.queue_id', 'queue-1');
    }

    public function test_a_locally_configured_queue_missing_from_zoom_is_not_shown(): void
    {
        $this->fakeZoom();

        ZoomCallQueueSetting::create(['queue_id' => 'gone-queue', 'excluded' => true]);

        // A row for a queue Zoom no longer lists is a leftover of a deleted or
        // recreated queue; a recreated queue carries a new id, so the stale row
        // cannot affect it and only clutters the page. The row itself stays in
        // the table and reappears in the zoom-unreachable fallback.
        $this->actingAs($this->staffWith('Call Queue Settings'))
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            // Zoom's one queue, plus the direct-calls pseudo-queue that is
            // always appended — see DirectCallPopTest.
            ->assertJsonCount(2, 'queues')
            ->assertJsonPath('queues.0.queue_id', 'queue-1')
            ->assertJsonPath('queues.1.id', 'direct');
    }

    /*
    |--------------------------------------------------------------------------
    | Saving a pickup code
    |--------------------------------------------------------------------------
    */

    private function save(array $payload, string $queueId = 'queue-1')
    {
        return $this->actingAs($this->staffWith('Call Queue Settings'))
            ->putJson('/ajax/call-queue/settings/' . $queueId, $payload);
    }

    public function test_a_valid_code_is_pushed_to_zoom_and_then_stored(): void
    {
        $this->fakeZoom();

        $this->save(['pickup_code' => '8781'])
            ->assertOk()
            ->assertJsonPath('queue.pickup_code', '8781');

        $this->assertSame(
            '8781',
            ZoomCallQueueSetting::firstWhere('queue_id', 'queue-1')->pickup_code
        );
    }

    public function test_a_code_zoom_refuses_is_not_stored(): void
    {
        $this->fakeZoom([
            'setPickupCode' => [
                'success' => false,
                'error' => 'Zoom said no',
            ],
        ]);

        // The pop must never advertise a code Zoom would not take.
        $this->save(['pickup_code' => '8781'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Zoom said no');

        $this->assertNull(
            ZoomCallQueueSetting::firstWhere('queue_id', 'queue-1')?->pickup_code
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCodes')]
    public function test_zooms_own_pickup_code_rules_are_enforced(string $code): void
    {
        $this->fakeZoom();

        // Enforced here because Zoom does not validate this field over the API
        // at all - it accepts anything and applies none of it.
        $this->save(['pickup_code' => $code])->assertStatus(422);
    }

    public static function invalidCodes(): array
    {
        return [
            'too short' => ['878'],
            'too long' => ['87812'],
            'not digits' => ['abcd'],
            'leading zero' => ['0781'],
            'all the same' => ['1111'],
            'ascending run' => ['1234'],
            'descending run' => ['4321'],
        ];
    }

    public function test_clearing_a_code_turns_the_policy_off_in_zoom(): void
    {
        $this->fakeZoom();

        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-1',
            'pickup_code' => '8781',
        ]);

        $this->save(['pickup_code' => null])
            ->assertOk()
            ->assertJsonPath('queue.pickup_code', null);

        $this->assertSame(
            [['disable', 'queue-1']],
            $this->app->make(ZoomCallQueueService::class)->pushed
        );
    }

    public function test_the_exclusion_flag_never_touches_zoom(): void
    {
        $this->fakeZoom();

        $this->save(['excluded' => true])->assertOk();

        $this->assertSame(
            [],
            $this->app->make(ZoomCallQueueService::class)->pushed
        );
        $this->assertTrue(
            ZoomCallQueueSetting::firstWhere('queue_id', 'queue-1')->excluded
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The dialled form
    |--------------------------------------------------------------------------
    */

    public function test_the_stored_code_is_bare_and_the_dialled_form_carries_the_prefix(): void
    {
        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-1',
            'pickup_code' => '8781',
        ]);
        ZoomCallQueueSetting::flushCache();

        // Zoom fixes a *99 prefix on every queue pickup code, so 8781 is
        // dialled *998781. The column holds digits only.
        $this->assertSame(
            ['queue-1' => '*998781'],
            ZoomCallQueueSetting::pickupCodes()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Live snapshot
    |--------------------------------------------------------------------------
    */

    public function test_the_snapshot_returns_ringing_calls_codes_and_the_channel(): void
    {
        ZoomLiveQueueCall::create([
            'call_id' => 'call-1',
            'queue_id' => 'queue-1',
            'queue_name' => 'Reception',
            'caller_number' => '+61412345678',
            'status' => 'ringing',
            'started_at' => now(),
        ]);

        ZoomCallQueueSetting::create([
            'queue_id' => 'queue-1',
            'pickup_code' => '8781',
        ]);
        ZoomCallQueueSetting::flushCache();

        $response = $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/live')
            ->assertOk();

        $response
            ->assertJsonPath('calls.0.call_id', 'call-1')
            ->assertJsonPath('pickup_codes.queue-1', '*998781')
            // The front end never hardcodes the channel; it subscribes to
            // whatever is named here.
            ->assertJsonPath('channel', 'call-queue-monitor');
    }

    public function test_a_stale_ringing_row_is_pruned(): void
    {
        // Zoom does not guarantee a closing event for every call, so without
        // this a dropped webhook leaves a card ringing forever.
        $call = ZoomLiveQueueCall::create([
            'call_id' => 'call-old',
            'queue_id' => 'queue-1',
            'status' => 'ringing',
            'started_at' => now()->subHour(),
        ]);

        $call->forceFill(['created_at' => now()->subHour()])->save();

        $this->actingAs($this->staffWith('Call Queue Monitor'))
            ->getJson('/ajax/call-queue/live')
            ->assertOk()
            ->assertJsonCount(0, 'calls');

        $this->assertSame(0, ZoomLiveQueueCall::count());
    }
}
