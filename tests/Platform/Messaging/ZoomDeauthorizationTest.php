<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\OAuthConnectionDeauthorized;
use Visnsstudio\VisnsPackages\Models\OAuthConnection;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;

/**
 * `app_deauthorized` (4.19.1).
 *
 * Zoom's Marketplace review requires a published OAuth app to stop using, and
 * remove, the tokens of a user who removes the app. The webhook deactivates
 * every Zoom connection whose token belongs to that user and clears its
 * tokens, keeping the row (an SMS line points at it).
 */
class ZoomDeauthorizationTest extends MessagingTestCase
{
    private const SECRET = 'zoom-signing-secret';

    private const CLIENT = 7;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set('visns-packages.call_queue.webhook_secret_token', self::SECRET);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration('2026_08_19_210000_create_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_09_02_120000_add_kind_and_callee_to_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_09_07_100000_add_legs_to_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_08_19_210100_create_zoom_call_queue_settings_table.php');
        $this->runPackageMigration('2026_09_02_100000_create_zoom_webhook_events_table.php');
    }

    private function signed(array $body, string $secret = self::SECRET)
    {
        $json = json_encode($body);
        $timestamp = (string) time();

        return $this->call('POST', '/api/zoom/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZM_SIGNATURE' => 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $json, $secret),
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
        ], $json);
    }

    private function deauthorized(string $userId): array
    {
        return [
            'event' => 'app_deauthorized',
            'event_ts' => 1760430000000,
            'payload' => [
                'account_id' => 'EabCDEFghiLHMA',
                'user_id' => $userId,
                'client_id' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
                'deauthorization_time' => '2026-10-14T07:00:00.000Z',
                'signature' => '827bdb0a9c1dsecret-looking-signature',
            ],
        ];
    }

    private function connection(string $tokenUser, ?int $clientId, string $provider = 'zoom_sms'): OAuthConnection
    {
        $connection = new OAuthConnection([
            'provider' => $provider,
            'access_token' => 'access-' . $tokenUser,
            'refresh_token' => 'refresh-' . $tokenUser,
            'expires_at' => now()->addHour(),
            'is_active' => true,
            'metadata' => [
                'token_user' => ['id' => $tokenUser, 'email' => $tokenUser . '@acme.test', 'name' => 'Kim'],
                'phone_numbers' => ['+61412000111'],
                'other' => 'kept',
            ],
        ]);
        $connection->client_id = $clientId;
        $connection->save();

        return $connection;
    }

    public function test_a_client_connection_is_deactivated_and_its_tokens_cleared(): void
    {
        $connection = $this->connection('zoom-user-1', self::CLIENT);

        $line = SmsLine::create([
            'label' => 'Acme front desk',
            'phone_number' => '+61893752541',
            'active' => true,
            'client_id' => self::CLIENT,
            'oauth_connection_id' => $connection->id,
        ]);

        $this->signed($this->deauthorized('zoom-user-1'))->assertOk();

        $fresh = OAuthConnection::query()->findOrFail($connection->id);

        $this->assertFalse($fresh->is_active);
        $this->assertSame('', $fresh->access_token);
        $this->assertNull($fresh->refresh_token);
        $this->assertNull($fresh->expires_at);
        // The Zoom user's profile and numbers are deleted, not kept.
        $this->assertArrayNotHasKey('token_user', $fresh->metadata);
        $this->assertArrayNotHasKey('phone_numbers', $fresh->metadata);
        $this->assertStringNotContainsString('@acme.test', json_encode($fresh->metadata));
        $this->assertSame('kept', $fresh->metadata['other']);
        $this->assertTrue($fresh->metadata['deauthorized_by_zoom']);
        $this->assertNotEmpty($fresh->metadata['deauthorized_at']);

        // The row and the line survive; detaching the line is the host
        // application's call, through the event.
        $this->assertSame((int) $connection->id, (int) $line->fresh()->oauth_connection_id);

        $row = ZoomWebhookEvent::query()->sole();
        $this->assertSame('app_deauthorized', $row->event);
        $this->assertSame('deauthorized', $row->outcome);
        $this->assertSame(['user_id' => 'zoom-user-1', 'connections' => 1], $row->meta);
        $this->assertStringNotContainsString('827bdb0a9c1d', json_encode($row->getAttributes()));
    }

    public function test_an_event_is_dispatched_per_connection_with_ids_only(): void
    {
        Event::fake([OAuthConnectionDeauthorized::class]);

        $house = $this->connection('zoom-user-1', null);
        $client = $this->connection('zoom-user-1', self::CLIENT);
        $this->connection('zoom-user-2', self::CLIENT);

        $this->signed($this->deauthorized('zoom-user-1'))->assertOk();

        Event::assertDispatchedTimes(OAuthConnectionDeauthorized::class, 2);
        Event::assertDispatched(OAuthConnectionDeauthorized::class, fn($e) => $e->connectionId === (int) $client->id
            && $e->clientId === self::CLIENT && $e->provider === 'zoom_sms' && $e->providerUserId === 'zoom-user-1');
        Event::assertDispatched(OAuthConnectionDeauthorized::class, fn($e) => $e->connectionId === (int) $house->id
            && $e->clientId === null);
    }

    public function test_a_house_connection_for_the_same_user_is_deactivated_too(): void
    {
        $house = $this->connection('zoom-user-1', null);
        $client = $this->connection('zoom-user-1', self::CLIENT);

        $this->signed($this->deauthorized('zoom-user-1'))->assertOk();

        $this->assertFalse($house->fresh()->is_active);
        $this->assertFalse($client->fresh()->is_active);
        $this->assertSame(2, ZoomWebhookEvent::query()->sole()->meta['connections']);
    }

    public function test_another_users_connection_is_untouched(): void
    {
        $other = $this->connection('zoom-user-2', self::CLIENT);
        $notZoom = $this->connection('zoom-user-1', null, 'xero');

        $this->signed($this->deauthorized('zoom-user-1'))->assertOk();

        $fresh = $other->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('access-zoom-user-2', $fresh->access_token);
        $this->assertSame('refresh-zoom-user-2', $fresh->refresh_token);
        $this->assertArrayNotHasKey('deauthorized_at', $fresh->metadata);

        $this->assertTrue($notZoom->fresh()->is_active);
    }

    public function test_an_unknown_user_answers_200_with_no_connections(): void
    {
        $this->signed($this->deauthorized('never-stored'))->assertOk();

        $row = ZoomWebhookEvent::query()->sole();
        $this->assertSame('deauthorized', $row->outcome);
        $this->assertSame(['user_id' => 'never-stored', 'connections' => 0], $row->meta);
    }

    public function test_it_is_handled_with_messaging_switched_off(): void
    {
        $this->app['config']->set('visns-packages.messaging.enabled', false);

        $connection = $this->connection('zoom-user-1', null);

        $this->signed($this->deauthorized('zoom-user-1'))->assertOk();

        $this->assertFalse($connection->fresh()->is_active);
        $this->assertSame('deauthorized', ZoomWebhookEvent::query()->sole()->outcome);
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $connection = $this->connection('zoom-user-1', null);

        $this->call('POST', '/api/zoom/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($this->deauthorized('zoom-user-1')))->assertStatus(401);

        $this->signed($this->deauthorized('zoom-user-1'), 'somebody-elses-secret')->assertStatus(401);

        $this->assertTrue($connection->fresh()->is_active);
    }
}
