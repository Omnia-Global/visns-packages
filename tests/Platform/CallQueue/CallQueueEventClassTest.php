<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\CallQueueAnswered;
use Visnsstudio\VisnsPackages\Events\CallQueueEnded;
use Visnsstudio\VisnsPackages\Events\CallQueueMissed;
use Visnsstudio\VisnsPackages\Events\CallQueueRinging;
use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\AppCallQueueAnswered;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\AppCallQueueEnded;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\AppCallQueueMissed;
use Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue\AppCallQueueRinging;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The webhook can be told to dispatch an application's own event classes.
 *
 * This exists because Laravel's Event::fake() keys listeners by EXACT class
 * name: an application whose listeners and tests are written against
 * App\Events\CallQueue* cannot be reached by dispatching the package's classes,
 * however they are aliased or subclassed. So the class names are config.
 */
class CallQueueEventClassTest extends TestCase
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
    }

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

    private function ringing(): array
    {
        return [
            'event' => 'phone.callee_ringing',
            'payload' => [
                'object' => [
                    'call_id' => 'call-abc-123',
                    'caller' => ['phone_number' => '+61412345678', 'name' => 'Cleo'],
                    'callee' => [
                        'extension_type' => 'callqueue',
                        'extension_id' => 'queue-1',
                        'name' => 'Reception',
                    ],
                ],
            ],
        ];
    }

    private function closing(string $event): array
    {
        return [
            'event' => $event,
            'payload' => ['object' => ['call_id' => 'call-abc-123']],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    public function test_the_package_events_are_dispatched_by_default(): void
    {
        Event::fake([CallQueueRinging::class]);

        $this->signedPost($this->ringing())->assertOk();

        Event::assertDispatched(CallQueueRinging::class);
    }

    public function test_the_shipped_event_config_names_the_package_classes(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertSame(
            [
                'ringing' => CallQueueRinging::class,
                'answered' => CallQueueAnswered::class,
                'ended' => CallQueueEnded::class,
                'missed' => CallQueueMissed::class,
            ],
            $shipped['call_queue']['events']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Application-owned classes
    |--------------------------------------------------------------------------
    */

    public function test_a_configured_ringing_class_is_dispatched_instead(): void
    {
        config()->set(
            'visns-packages.call_queue.events.ringing',
            AppCallQueueRinging::class
        );

        Event::fake([AppCallQueueRinging::class, CallQueueRinging::class]);

        $this->signedPost($this->ringing())->assertOk();

        Event::assertDispatched(
            AppCallQueueRinging::class,
            fn(AppCallQueueRinging $event) => $event->call instanceof ZoomLiveQueueCall
                && $event->call->call_id === 'call-abc-123'
        );

        // Exactly one event, not both.
        Event::assertNotDispatched(CallQueueRinging::class);
    }

    public function test_a_configured_answered_class_is_dispatched_instead(): void
    {
        config()->set(
            'visns-packages.call_queue.events.answered',
            AppCallQueueAnswered::class
        );

        Event::fake([AppCallQueueAnswered::class, CallQueueAnswered::class]);

        $this->signedPost($this->ringing());
        $this->signedPost($this->closing('phone.callee_answered'))->assertOk();

        Event::assertDispatched(
            AppCallQueueAnswered::class,
            fn(AppCallQueueAnswered $event) => $event->callId === 'call-abc-123'
        );
        Event::assertNotDispatched(CallQueueAnswered::class);
    }

    public function test_a_configured_ended_class_is_dispatched_instead(): void
    {
        config()->set(
            'visns-packages.call_queue.events.ended',
            AppCallQueueEnded::class
        );

        Event::fake([AppCallQueueEnded::class, CallQueueEnded::class]);

        $this->signedPost($this->ringing());
        $this->signedPost($this->closing('phone.callee_ended'))->assertOk();

        Event::assertDispatched(
            AppCallQueueEnded::class,
            fn(AppCallQueueEnded $event) => $event->callId === 'call-abc-123'
        );
        Event::assertNotDispatched(CallQueueEnded::class);
    }

    public function test_a_configured_missed_class_is_dispatched_instead(): void
    {
        config()->set(
            'visns-packages.call_queue.events.missed',
            AppCallQueueMissed::class
        );

        Event::fake([AppCallQueueMissed::class, CallQueueMissed::class]);

        $this->signedPost($this->ringing());
        // Not a closing event any more: one leg declining leaves the row alone
        // and says so on its own name.
        $this->signedPost($this->closing('phone.callee_missed'))->assertOk();

        Event::assertDispatched(
            AppCallQueueMissed::class,
            fn(AppCallQueueMissed $event) => $event->callId === 'call-abc-123'
        );
        Event::assertNotDispatched(CallQueueMissed::class);
    }

    public function test_the_three_classes_are_configured_independently(): void
    {
        config()->set(
            'visns-packages.call_queue.events.ringing',
            AppCallQueueRinging::class
        );

        Event::fake([AppCallQueueRinging::class, CallQueueEnded::class]);

        $this->signedPost($this->ringing());
        $this->signedPost($this->closing('phone.caller_ended'));

        Event::assertDispatched(AppCallQueueRinging::class);
        // The two left at their defaults are untouched.
        Event::assertDispatched(CallQueueEnded::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Bad config
    |--------------------------------------------------------------------------
    */

    public function test_an_unloadable_class_falls_back_to_the_package_event(): void
    {
        config()->set(
            'visns-packages.call_queue.events.ringing',
            'App\\Events\\ThisDoesNotExist'
        );

        Event::fake([CallQueueRinging::class]);

        // A typo in config should cost the pop its custom listener, not stop the
        // webhook recording calls at all.
        $this->signedPost($this->ringing())->assertOk();

        $this->assertSame(1, ZoomLiveQueueCall::count());
        Event::assertDispatched(CallQueueRinging::class);
    }
}
