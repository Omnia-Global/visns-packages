<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Models\ZoomCallQueueSetting;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomCallQueueService;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\AppZoomCallQueueService;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The settings page's Zoom client is named in config and resolved from the
 * container.
 *
 * This exists because a constructor type-hint on the package's own class names
 * exactly one implementation: an application with its own client had no way in,
 * and — worse — a suite binding a fake for ITS class would have been ignored,
 * sending every save in it to the live Zoom tenant.
 */
class CallQueueZoomServiceTest extends TestCase
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

    private function staff(): User
    {
        $user = User::create([
            'firstname' => 'Sam',
            'email' => 'sam@example.test',
            'password' => Hash::make('x'),
        ]);

        Permission::findOrCreate('Call Queue Settings', 'web');
        $user->givePermissionTo('Call Queue Settings');

        return $user;
    }

    /**
     * Point the config at the application's client and register a double for
     * it, exactly as an adopting application's test suite would.
     */
    private function useAppService(): AppZoomCallQueueService
    {
        config()->set(
            'visns-packages.call_queue.zoom_service',
            AppZoomCallQueueService::class
        );

        $double = new AppZoomCallQueueService();

        $this->app->instance(AppZoomCallQueueService::class, $double);

        return $double;
    }

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    public function test_the_shipped_config_names_the_packages_own_client(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertSame(
            ZoomCallQueueService::class,
            $shipped['call_queue']['zoom_service']
        );
    }

    public function test_the_default_still_resolves_the_package_client(): void
    {
        // Nothing configured away: the container must hand back the package's
        // own service, unchanged from before this key existed.
        $this->app->instance(
            ZoomCallQueueService::class,
            new class extends ZoomCallQueueService {
                public function __construct()
                {
                }

                public function listQueues(): array
                {
                    return ['success' => true, 'queues' => [
                        ['id' => 'package-queue', 'name' => 'Package Queue'],
                    ]];
                }
            }
        );

        $this->actingAs($this->staff())
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonPath('queues.0.queue_id', 'package-queue');
    }

    /*
    |--------------------------------------------------------------------------
    | An application's own client
    |--------------------------------------------------------------------------
    */

    public function test_a_configured_class_is_resolved_for_reads(): void
    {
        $this->useAppService();

        $this->actingAs($this->staff())
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonPath('zoom_unreachable', false)
            ->assertJsonPath('queues.0.queue_id', 'app-queue-1')
            ->assertJsonPath('queues.0.name', 'Application Reception');
    }

    public function test_the_container_double_receives_the_pickup_code_push(): void
    {
        $double = $this->useAppService();

        $this->actingAs($this->staff())
            ->putJson('/ajax/call-queue/settings/app-queue-1', [
                'pickup_code' => '8781',
            ])
            ->assertOk()
            ->assertJsonPath('queue.pickup_code', '8781');

        // The whole point: the push went to the bound double, not to a client
        // holding real credentials.
        $this->assertSame([['set', 'app-queue-1', '8781']], $double->pushed);

        $this->assertSame(
            '8781',
            ZoomCallQueueSetting::firstWhere('queue_id', 'app-queue-1')->pickup_code
        );
    }

    public function test_the_container_double_receives_a_cleared_code(): void
    {
        $double = $this->useAppService();

        ZoomCallQueueSetting::create([
            'queue_id' => 'app-queue-1',
            'pickup_code' => '8781',
        ]);

        $this->actingAs($this->staff())
            ->putJson('/ajax/call-queue/settings/app-queue-1', [
                'pickup_code' => null,
            ])
            ->assertOk();

        $this->assertSame([['disable', 'app-queue-1']], $double->pushed);
    }

    public function test_a_refusal_from_the_configured_client_still_blocks_the_save(): void
    {
        config()->set(
            'visns-packages.call_queue.zoom_service',
            AppZoomCallQueueService::class
        );

        $this->app->instance(
            AppZoomCallQueueService::class,
            new class extends AppZoomCallQueueService {
                public function setPickupCode(string $queueId, string $code): array
                {
                    return ['success' => false, 'error' => 'app client refused'];
                }
            }
        );

        $this->actingAs($this->staff())
            ->putJson('/ajax/call-queue/settings/app-queue-1', [
                'pickup_code' => '8781',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'app client refused');

        // The pop must never advertise a code the client would not take,
        // whoever the client is.
        $this->assertNull(
            ZoomCallQueueSetting::firstWhere('queue_id', 'app-queue-1')?->pickup_code
        );
    }

    public function test_the_configured_client_owns_the_unreachable_path_too(): void
    {
        $double = $this->useAppService();
        $double->reachable = false;

        ZoomCallQueueSetting::create(['queue_id' => 'local-only', 'excluded' => true]);

        $this->actingAs($this->staff())
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonPath('zoom_unreachable', true)
            ->assertJsonPath('error', 'app client could not reach Zoom')
            ->assertJsonPath('queues.0.queue_id', 'local-only');
    }

    /*
    |--------------------------------------------------------------------------
    | Bad config
    |--------------------------------------------------------------------------
    */

    public function test_an_unloadable_class_falls_back_to_the_package_client(): void
    {
        config()->set(
            'visns-packages.call_queue.zoom_service',
            'App\\Helpers\\ThisDoesNotExist'
        );

        $this->app->instance(
            ZoomCallQueueService::class,
            new class extends ZoomCallQueueService {
                public function __construct()
                {
                }

                public function listQueues(): array
                {
                    return ['success' => true, 'queues' => [
                        ['id' => 'package-queue', 'name' => 'Package Queue'],
                    ]];
                }
            }
        );

        // A typo in config should not take the settings page down with an
        // unresolvable class.
        $this->actingAs($this->staff())
            ->getJson('/ajax/call-queue/settings')
            ->assertOk()
            ->assertJsonPath('queues.0.queue_id', 'package-queue');
    }
}
