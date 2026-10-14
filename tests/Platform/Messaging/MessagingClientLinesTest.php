<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Visnsstudio\VisnsPackages\Models\OAuthConnection;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Models\ZoomWebhookEvent;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignSender;
use Visnsstudio\VisnsPackages\Services\Sms\SmsLineResolver;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Support\SchemaColumns;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\ClientCampaignController;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\ClientOptOutController;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;

/**
 * Lines a client owns (4.19.0).
 *
 * The contract, in the order the design states it: a client's line is
 * invisible to every staff surface; a host extends the campaign and opt-out
 * controllers through their hooks and gets exactly one client's campaigns;
 * opt-outs are per client; a client line sends on the client's own Zoom
 * connection or not at all; the webhook accepts the user-level SMS app's
 * secret; and an OAuth connection belongs to the house unless it says
 * otherwise.
 */
class MessagingClientLinesTest extends MessagingTestCase
{
    private const CLIENT = ClientCampaignController::CLIENT_ID;

    private const SECRET = 'zoom-signing-secret';

    private const SMS_APP_SECRET = 'sms-app-signing-secret';

    private const CLIENT_NUMBER = '+61893752541';

    private const HOUSE_NUMBER = '+61893752549';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.messaging.bulk.enabled', true);
        $app['config']->set('visns-packages.messaging.bulk.send_interval_ms', 0);
        $app['config']->set('visns-packages.messaging.bulk.per_day', 0);
        $app['config']->set('visns-packages.messaging.register_broadcast_channel', true);

        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set('visns-packages.call_queue.webhook_secret_token', self::SECRET);
        $app['config']->set('visns-packages.messaging.zoom.webhook_secrets', [self::SMS_APP_SECRET]);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        // What the webhook endpoint needs to answer an SMS event and ledger it.
        $this->runPackageMigration('2026_08_19_210000_create_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_09_02_120000_add_kind_and_callee_to_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_09_07_100000_add_legs_to_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_08_19_210100_create_zoom_call_queue_settings_table.php');
        $this->runPackageMigration('2026_09_02_100000_create_zoom_webhook_events_table.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();

        // How a host mounts the hooks: its own controller, its own routes, its
        // own guard (none here - the guard is the host's business).
        Route::get('client7/campaigns', [ClientCampaignController::class, 'index']);
        Route::post('client7/campaigns', [ClientCampaignController::class, 'store']);
        Route::get('client7/campaigns/{id}', [ClientCampaignController::class, 'show']);
        Route::post('client7/campaigns/{id}/start', [ClientCampaignController::class, 'start']);
        Route::get('client7/opt-outs', [ClientOptOutController::class, 'index']);
        Route::post('client7/opt-outs', [ClientOptOutController::class, 'store']);
        Route::delete('client7/opt-outs/{id}', [ClientOptOutController::class, 'destroy']);
    }

    private function clientLine(array $attributes = []): SmsLine
    {
        return SmsLine::create(array_merge([
            'label' => 'Acme front desk',
            'phone_number' => self::CLIENT_NUMBER,
            'active' => true,
            'client_id' => self::CLIENT,
        ], $attributes));
    }

    private function houseLine(array $users = [], array $attributes = []): SmsLine
    {
        return $this->line($users, array_merge(['phone_number' => self::HOUSE_NUMBER], $attributes));
    }

    private function campaignOn(SmsLine $line, array $attributes = []): SmsCampaign
    {
        return SmsCampaign::create(array_merge([
            'line_id' => $line->id,
            'name' => 'Campaign on ' . $line->label,
            'body' => 'Hi {first_name}',
            'status' => SmsCampaign::STATUS_DRAFT,
            'total' => 0,
        ], $attributes));
    }

    private function useRealZoom(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->forgetInstance(ZoomSmsClient::class);
    }

    private function signed(string $secret, array $body)
    {
        $json = json_encode($body);
        $timestamp = (string) time();

        return $this->call(
            'POST',
            '/api/zoom/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ZM_SIGNATURE' => 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $json, $secret),
                'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            ],
            $json
        );
    }

    private function channelCallback(): callable
    {
        $broadcaster = Broadcast::getFacadeRoot()->driver();
        $property = new \ReflectionProperty($broadcaster, 'channels');
        $property->setAccessible(true);

        return $property->getValue($broadcaster)['sms-line.{lineId}'];
    }

    /*
    |--------------------------------------------------------------------------
    | 1. The migration
    |--------------------------------------------------------------------------
    */

    public function test_the_opt_out_register_is_unique_per_client_and_number(): void
    {
        $optOuts = app(SmsOptOuts::class);

        $optOuts->record('+61412345678', SmsOptOut::SOURCE_MANUAL);
        $optOuts->record('+61412345678', SmsOptOut::SOURCE_KEYWORD, null, null, null, null, self::CLIENT);

        // Twice more each: updateOrCreate on the new key, so still one row each.
        $optOuts->record('+61412345678', SmsOptOut::SOURCE_KEYWORD);
        $optOuts->record('+61412345678', SmsOptOut::SOURCE_MANUAL, null, null, null, null, self::CLIENT);

        $this->assertSame(1, SmsOptOut::query()->where('client_id', 0)->count());
        $this->assertSame(1, SmsOptOut::query()->where('client_id', self::CLIENT)->count());

        $this->expectException(QueryException::class);

        DB::table('sms_opt_outs')->insert([
            'client_id' => self::CLIENT,
            'number' => '+61412345678',
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_migration_is_idempotent_and_reverses(): void
    {
        $migration = require __DIR__ . '/../../../database/migrations/2026_10_14_100000_add_client_scope_to_sms_tables.php';

        // Run again over itself: every step is guarded.
        $migration->up();

        $migration->down();
        SchemaColumns::flush();

        $this->assertFalse(\Schema::hasColumn('sms_lines', 'client_id'));
        $this->assertFalse(\Schema::hasColumn('sms_opt_outs', 'client_id'));
        $this->assertFalse(\Schema::hasColumn('oauth_connections', 'client_id'));

        // Back to one row per number.
        app(SmsOptOuts::class)->record('+61412345678', SmsOptOut::SOURCE_MANUAL);
        $this->assertSame(1, SmsOptOut::query()->count());

        $migration->up();
        SchemaColumns::flush();

        $this->assertTrue(\Schema::hasColumn('sms_lines', 'oauth_connection_id'));
        $this->assertSame(0, (int) SmsOptOut::query()->value('client_id'));
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Staff never see a client's line
    |--------------------------------------------------------------------------
    */

    public function test_a_client_line_is_invisible_on_every_staff_surface(): void
    {
        $admin = $this->admin();
        $house = $this->houseLine([$admin]);
        $client = $this->clientLine();

        $this->thread($house, '+61412345678');
        $clientThread = $this->thread($client, '+61412345679');

        $houseCampaign = $this->campaignOn($house);
        $clientCampaign = $this->campaignOn($client);

        $this->actingAs($admin)->getJson(self::BASE . '/lines')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $house->id);

        $this->actingAs($admin)->getJson(self::BASE . '/settings/lines')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $house->id);

        $this->actingAs($admin)->putJson(self::BASE . '/settings/lines/' . $client->id, ['label' => 'Ours now'])
            ->assertNotFound();

        $this->actingAs($admin)->getJson(self::BASE . '/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.id', $houseCampaign->id);

        $this->actingAs($admin)->getJson(self::BASE . '/campaigns/' . $clientCampaign->id)->assertNotFound();
        $this->actingAs($admin)->postJson(self::BASE . '/campaigns/' . $clientCampaign->id . '/start')->assertNotFound();

        $this->actingAs($admin)->getJson(self::BASE . '/threads')
            ->assertOk()
            ->assertJsonMissing(['id' => $clientThread->id, 'external_number' => '+61412345679']);

        $this->actingAs($admin)->getJson(self::BASE . '/threads/' . $clientThread->id)->assertNotFound();

        // A campaign on the client's line cannot be created from the house's
        // screen either - and the sentence does not confirm the line exists.
        $this->actingAs($admin)->postJson(self::BASE . '/campaigns', [
            'line_id' => $client->id,
            'name' => 'Hijack',
            'body' => 'Hello',
            'recipients' => [['number' => '0412 345 678']],
        ])->assertStatus(422)->assertJsonPath('message', 'That line is not available.');

        $this->assertSame('Acme front desk', $client->fresh()->label);
    }

    public function test_the_broadcast_channel_admits_nobody_to_a_client_line(): void
    {
        $admin = $this->admin();
        $house = $this->houseLine();
        $client = $this->clientLine();

        $callback = $this->channelCallback();

        $this->assertTrue($callback($admin, (string) $house->id));
        $this->assertFalse($callback($admin, (string) $client->id));
    }

    public function test_the_resolver_answers_a_house_line_only(): void
    {
        // The client line comes FIRST and carries a Zoom user - exactly the
        // line the resolver's second rule would otherwise pick.
        $client = $this->clientLine(['zoom_user_id' => 'client-zoom-user']);
        $house = $this->houseLine();

        $this->assertLessThan($house->id, $client->id);
        $this->assertSame($house->id, SmsLineResolver::resolve()?->id);

        // Even named outright as the system line.
        $this->app['config']->set('visns-packages.messaging.system_line', self::CLIENT_NUMBER);

        $this->assertSame($house->id, SmsLineResolver::resolve()?->id);
    }

    public function test_sync_lines_never_touches_a_client_line(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$users = [
            'success' => true,
            'users' => [[
                'id' => 'our-zoom-user',
                'email' => 'ours@example.test',
                'display_name' => 'Ours',
                'phone_numbers' => [self::CLIENT_NUMBER, self::HOUSE_NUMBER],
            ]],
        ];

        $client = $this->clientLine(['zoom_user_id' => 'client-zoom-user']);
        $house = $this->houseLine();

        $this->artisan('sms:sync-lines')->assertSuccessful();

        $this->assertSame('client-zoom-user', $client->fresh()->zoom_user_id);
        $this->assertSame('our-zoom-user', $house->fresh()->zoom_user_id);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. The hooks: one client's campaigns, through a subclass
    |--------------------------------------------------------------------------
    */

    public function test_a_subclass_scoped_to_one_client_sees_and_acts_on_only_that_client(): void
    {
        $house = $this->houseLine();
        $client = $this->clientLine();
        $houseCampaign = $this->campaignOn($house);

        $created = $this->postJson('client7/campaigns', [
            'line_id' => $client->id,
            'name' => 'Spring sale',
            'body' => 'Hi {first_name}, our spring sale is on.',
            'recipients' => [['name' => 'Cleo Client', 'number' => '0412 345 678']],
        ])->assertStatus(201)
            ->assertJsonPath('campaign.user.name', ClientCampaignController::ACTOR)
            ->assertJsonPath('campaign.user.id', null)
            ->assertJsonPath('campaign.user_name', ClientCampaignController::ACTOR);

        $campaign = SmsCampaign::findOrFail($created->json('campaign.id'));

        $this->assertNull($campaign->user_id);
        $this->assertSame(ClientCampaignController::ACTOR, $campaign->created_by_name);

        $this->getJson('client7/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.id', $campaign->id);

        $this->getJson('client7/campaigns/' . $campaign->id)->assertOk();
        $this->getJson('client7/campaigns/' . $houseCampaign->id)->assertNotFound();
        $this->postJson('client7/campaigns/' . $houseCampaign->id . '/start')->assertNotFound();

        $this->postJson('client7/campaigns/' . $campaign->id . '/start')
            ->assertOk()
            ->assertJsonPath('campaign.status', SmsCampaign::STATUS_SENDING);

        // The house's line is not one of this client's.
        $this->postJson('client7/campaigns', [
            'line_id' => $house->id,
            'name' => 'Borrowed',
            'body' => 'Hello',
            'recipients' => [['number' => '0412 345 678']],
        ])->assertStatus(422)->assertJsonPath('message', 'That line is not available.');

        // And the house cannot see the client's campaign.
        $this->actingAs($this->admin())->getJson(self::BASE . '/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.id', $houseCampaign->id);
    }

    public function test_the_import_is_judged_against_the_clients_own_register(): void
    {
        $client = $this->clientLine();
        $optOuts = app(SmsOptOuts::class);

        // Unsubscribed from US, not from the client: accepted.
        $optOuts->record('+61412345678', SmsOptOut::SOURCE_MANUAL);
        // Unsubscribed from the client: refused.
        $optOuts->record('+61412345679', SmsOptOut::SOURCE_KEYWORD, null, null, null, null, self::CLIENT);

        $this->postJson('client7/campaigns', [
            'line_id' => $client->id,
            'name' => 'Spring sale',
            'body' => 'Hello',
            'recipients' => [
                ['number' => '0412 345 678'],
                ['number' => '0412 345 679'],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('report.accepted', 1)
            ->assertJsonPath('report.opted_out.0.number', '+61412345679');
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Opt-outs are per client
    |--------------------------------------------------------------------------
    */

    public function test_a_stop_on_a_client_line_unsubscribes_from_that_client_only(): void
    {
        $client = $this->clientLine();
        $thread = $this->thread($client, '+61412345678');

        app(SmsService::class)->recordInbound($thread, 'STOP');

        $row = SmsOptOut::sole();

        $this->assertSame(self::CLIENT, (int) $row->client_id);
        $this->assertSame((int) $client->id, (int) $row->line_id);

        $optOuts = app(SmsOptOuts::class);

        $this->assertTrue($optOuts->isOptedOut('+61412345678', self::CLIENT));
        $this->assertFalse($optOuts->isOptedOut('+61412345678'));

        app(SmsService::class)->recordInbound($thread, 'START');

        $this->assertSame(0, SmsOptOut::query()->count());
    }

    public function test_the_sender_skips_a_number_for_the_client_whose_register_holds_it(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        $house = $this->houseLine();
        $client = $this->clientLine();

        app(SmsOptOuts::class)->record('+61412345678', SmsOptOut::SOURCE_KEYWORD, null, null, null, null, self::CLIENT);

        $clientCampaign = $this->campaignOn($client, ['status' => SmsCampaign::STATUS_SENDING, 'total' => 1]);
        $houseCampaign = $this->campaignOn($house, ['status' => SmsCampaign::STATUS_SENDING, 'total' => 1]);

        foreach ([$clientCampaign, $houseCampaign] as $campaign) {
            $campaign->recipients()->create([
                'number' => '+61412345678',
                'status' => 'pending',
                'retries' => 0,
            ]);
        }

        app(SmsCampaignSender::class)->run(10);

        $this->assertSame('skipped', $clientCampaign->recipients()->value('status'));
        $this->assertSame('sent', $houseCampaign->recipients()->value('status'));
    }

    public function test_a_subclass_manages_only_its_clients_register(): void
    {
        $house = app(SmsOptOuts::class)->record('+61412345670', SmsOptOut::SOURCE_MANUAL);

        $this->postJson('client7/opt-outs', ['number' => '0412 345 678', 'note' => 'Rang the front desk'])
            ->assertStatus(201);

        $row = SmsOptOut::query()->where('client_id', self::CLIENT)->sole();

        $this->assertNull($row->user_id);
        $this->assertSame('+61412345678', $row->number);

        $this->getJson('client7/opt-outs')
            ->assertOk()
            ->assertJsonCount(1, 'opt_outs')
            ->assertJsonPath('opt_outs.0.number', '+61412345678');

        $this->deleteJson('client7/opt-outs/' . $house->id)->assertNotFound();
        $this->assertNotNull($house->fresh());

        // The house's screen does not list the client's row.
        $this->actingAs($this->admin())->getJson(self::BASE . '/opt-outs')
            ->assertOk()
            ->assertJsonCount(1, 'opt_outs')
            ->assertJsonPath('opt_outs.0.number', '+61412345670');
    }

    /*
    |--------------------------------------------------------------------------
    | 5. A client line sends on the client's own connection or not at all
    |--------------------------------------------------------------------------
    */

    public function test_a_client_line_with_no_connection_is_not_connected_and_nothing_reaches_zoom(): void
    {
        $this->useRealZoom();
        Http::fake();

        // A house connection exists, and must NOT be borrowed.
        OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'house-token', 'expires_in' => 3600]);

        $client = $this->clientLine(['zoom_user_id' => 'client-zoom-user']);

        $message = app(SmsService::class)->sendToNumber('0412 345 678', 'Hello', null, $client);

        $this->assertSame(SmsMessage::STATUS_NOT_CONNECTED, $message->status);
        $this->assertSame(ZoomSmsClient::CLIENT_NOT_CONNECTED, $message->error);

        Http::assertNothingSent();
    }

    public function test_a_client_campaign_with_no_connection_pauses_and_says_why(): void
    {
        $this->useRealZoom();
        Http::fake();

        $client = $this->clientLine();
        $campaign = $this->campaignOn($client, ['status' => SmsCampaign::STATUS_SENDING, 'total' => 1]);
        $campaign->recipients()->create(['number' => '+61412345678', 'status' => 'pending', 'retries' => 0]);

        app(SmsCampaignSender::class)->run(10);

        $campaign->refresh();

        $this->assertSame(SmsCampaign::STATUS_PAUSED, $campaign->status);
        $this->assertSame(ZoomSmsClient::CLIENT_NOT_CONNECTED, $campaign->last_error);
        $this->assertSame('pending', $campaign->recipients()->value('status'));

        Http::assertNothingSent();
    }

    public function test_a_client_line_sends_with_its_own_token_as_its_own_zoom_user(): void
    {
        $this->useRealZoom();

        OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'house-token', 'expires_in' => 3600]);
        $clientConnection = OAuthConnection::storeConnection(
            'zoom_sms',
            ['access_token' => 'client-token', 'expires_in' => 3600],
            [],
            self::CLIENT
        );

        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/users/me')) {
                return Http::response(['id' => 'client-zoom-user', 'email' => 'owner@acme.test']);
            }

            return Http::response(['message_id' => 'zoom-msg-1'], 201);
        });

        $client = $this->clientLine([
            'zoom_user_id' => 'stale-zoom-user',
            'oauth_connection_id' => $clientConnection->id,
        ]);

        $message = app(SmsService::class)->sendToNumber('0412 345 678', 'Hello', null, $client);

        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/phone/sms/messages')
            && $request->hasHeader('Authorization', 'Bearer client-token')
            && $request['sender']['user_id'] === 'client-zoom-user'
            && $request['sender']['phone_number'] === self::CLIENT_NUMBER);

        Http::assertNotSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer house-token'));

        // The token's user is remembered on the CLIENT's connection row.
        $this->assertSame('client-zoom-user', $clientConnection->fresh()->metadata['token_user']['id']);
    }

    public function test_a_client_line_without_a_pointer_finds_the_clients_active_connection(): void
    {
        $this->useRealZoom();

        OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'client-token', 'expires_in' => 3600], [], self::CLIENT);

        Http::fake(fn (HttpRequest $request) => str_ends_with($request->url(), '/users/me')
            ? Http::response(['id' => 'client-zoom-user'])
            : Http::response(['message_id' => 'zoom-msg-2'], 201));

        $client = $this->clientLine();

        app(SmsService::class)->sendToNumber('0412 345 678', 'Hello', null, $client);

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/phone/sms/messages')
            && $request->hasHeader('Authorization', 'Bearer client-token'));
    }

    public function test_a_house_line_keeps_using_the_house_connection(): void
    {
        $this->useRealZoom();

        OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'house-token', 'expires_in' => 3600]);
        OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'client-token', 'expires_in' => 3600], [], self::CLIENT);

        Http::fake(fn (HttpRequest $request) => str_ends_with($request->url(), '/users/me')
            ? Http::response(['id' => 'house-zoom-user'])
            : Http::response(['message_id' => 'zoom-msg-3'], 201));

        $house = $this->houseLine([], ['zoom_user_id' => 'house-zoom-user']);

        $message = app(SmsService::class)->sendToNumber('0412 345 678', 'Hello', null, $house);

        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/phone/sms/messages')
            && $request->hasHeader('Authorization', 'Bearer house-token')
            && $request['sender']['user_id'] === 'house-zoom-user');

        Http::assertNotSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer client-token'));
    }

    /*
    |--------------------------------------------------------------------------
    | 6. The webhook takes the SMS app's secret too
    |--------------------------------------------------------------------------
    */

    public function test_a_delivery_signed_with_the_sms_apps_secret_is_accepted(): void
    {
        $client = $this->clientLine();

        $this->signed(self::SMS_APP_SECRET, [
            'event' => 'phone.sms_received',
            'payload' => [
                'object' => [
                    'session_id' => 'session-client',
                    'message_id' => 'client-msg-1',
                    'message' => 'Is the sale still on?',
                    'message_type' => 1,
                    'sender' => ['phone_number' => '+61412345678'],
                    'to_members' => [['phone_number' => self::CLIENT_NUMBER]],
                    'date_time' => '2026-10-14T03:04:05Z',
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, ZoomWebhookEvent::query()->count());

        $message = SmsMessage::query()->where('provider_message_id', 'client-msg-1')->sole();
        $this->assertSame((int) $client->id, (int) $message->thread->line_id);
    }

    public function test_url_validation_is_answered_with_the_secret_that_signed_it(): void
    {
        $response = $this->signed(self::SMS_APP_SECRET, [
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain-123'],
        ])->assertOk();

        $this->assertSame(hash_hmac('sha256', 'plain-123', self::SMS_APP_SECRET), $response->json('encryptedToken'));

        $house = $this->signed(self::SECRET, [
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain-456'],
        ])->assertOk();

        $this->assertSame(hash_hmac('sha256', 'plain-456', self::SECRET), $house->json('encryptedToken'));
    }

    public function test_a_delivery_signed_with_neither_secret_is_refused(): void
    {
        $this->signed('somebody-elses-secret', [
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain-789'],
        ])->assertStatus(401);
    }

    public function test_the_zoom_sms_integrations_own_secret_is_a_candidate(): void
    {
        $this->app['config']->set('visns-packages.messaging.zoom.webhook_secrets', []);
        $this->app['config']->set('visns-packages.integrations.zoom_sms', [
            'driver' => 'oauth2',
            'fields' => [
                'webhook_secret' => ['label' => 'Webhook secret token', 'secret' => true, 'default' => 'integration-secret'],
            ],
        ]);

        $response = $this->signed('integration-secret', [
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain-abc'],
        ])->assertOk();

        $this->assertSame(hash_hmac('sha256', 'plain-abc', 'integration-secret'), $response->json('encryptedToken'));
    }

    /*
    |--------------------------------------------------------------------------
    | 7. A connection is the house's unless it says otherwise
    |--------------------------------------------------------------------------
    */

    public function test_connections_are_scoped_to_their_owner(): void
    {
        $house = OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'house-token']);
        $first = OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'client-1'], [], self::CLIENT);
        $other = OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'other-client'], [], 8);

        $this->assertSame($house->id, OAuthConnection::getActiveConnection('zoom_sms')?->id);
        $this->assertSame($first->id, OAuthConnection::getActiveConnection('zoom_sms', self::CLIENT)?->id);

        // Reconnecting client 7 retires client 7's previous row and nobody else's.
        $second = OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'client-2'], [], self::CLIENT);

        $this->assertFalse((bool) $first->fresh()->is_active);
        $this->assertTrue((bool) $house->fresh()->is_active);
        $this->assertTrue((bool) $other->fresh()->is_active);
        $this->assertSame($second->id, OAuthConnection::getActiveConnection('zoom_sms', self::CLIENT)?->id);
        $this->assertSame(self::CLIENT, (int) $second->fresh()->client_id);

        // And the house reconnecting retires only the house's.
        OAuthConnection::storeConnection('zoom_sms', ['access_token' => 'house-2']);

        $this->assertFalse((bool) $house->fresh()->is_active);
        $this->assertTrue((bool) $second->fresh()->is_active);
        $this->assertNull(OAuthConnection::getActiveConnection('zoom_sms', 99));
    }
}
