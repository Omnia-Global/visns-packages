<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Route;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Support\SchemaColumns;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\ClientInboxController;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\StubClientResolver;

/**
 * The inbox for an actor who is not staff: SmsController's hooks, overridden
 * by a host's subclass scoped to ONE client (the fixture, client 7, a portal
 * reader who is not a `users` row).
 *
 * What it pins: the subclass sees that client's lines and threads and nothing
 * else (every miss the same 404 as an invented id); a send records who sent it
 * by name; unread is per portal reader on its own marks table; an inbound
 * message on the client's line lands in its list; the host's client link never
 * reaches it and is never made for a client line; and `opted_out` reads the
 * client's own register.
 */
class MessagingClientInboxTest extends MessagingTestCase
{
    private const CLIENT = ClientInboxController::CLIENT_ID;

    private const OTHER_CLIENT = 8;

    private const PORTAL = '/portal7/sms';

    private const SECRET = 'zoom-signing-secret';

    private const SMS_APP_SECRET = 'sms-app-signing-secret';

    private const CLIENT_NUMBER = '+61893750001';

    private const OTHER_CLIENT_NUMBER = '+61893750002';

    private const HOUSE_NUMBER = '+61893750009';

    /** The number StubClientResolver matches. */
    private const TEXTER = '+61412345678';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // What the webhook needs to accept an SMS event signed by the
        // user-level SMS app - the path a client line's inbound arrives on.
        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set('visns-packages.call_queue.webhook_secret_token', self::SECRET);
        $app['config']->set('visns-packages.messaging.zoom.webhook_secrets', [self::SMS_APP_SECRET]);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration('2026_08_19_210000_create_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_09_02_120000_add_kind_and_callee_to_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_09_07_100000_add_legs_to_zoom_live_queue_calls_table.php');
        $this->runPackageMigration('2026_08_19_210100_create_zoom_call_queue_settings_table.php');
        $this->runPackageMigration('2026_09_02_100000_create_zoom_webhook_events_table.php');

        // The two this round ships.
        $this->runPackageMigration('2026_11_23_100000_create_sms_thread_reader_marks_table.php');
        $this->runPackageMigration('2026_11_23_100010_add_sent_by_name_to_sms_messages_table.php');

        SchemaColumns::flush();
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubClientResolver::reset();
        ClientInboxController::$readerId = 1;

        // Mounted the way a host would: its own controller, its own routes.
        Route::get('portal7/sms/lines', [ClientInboxController::class, 'lines']);
        Route::get('portal7/sms/unread', [ClientInboxController::class, 'unread']);
        Route::get('portal7/sms/threads', [ClientInboxController::class, 'threads']);
        Route::post('portal7/sms/threads', [ClientInboxController::class, 'storeThread']);
        Route::get('portal7/sms/threads/{id}', [ClientInboxController::class, 'showThread']);
        Route::post('portal7/sms/threads/{id}/messages', [ClientInboxController::class, 'storeMessage']);
        Route::post('portal7/sms/threads/{id}/read', [ClientInboxController::class, 'read']);
        Route::post('portal7/sms/threads/{id}/archive', [ClientInboxController::class, 'archive']);
        Route::post('portal7/sms/threads/{id}/unarchive', [ClientInboxController::class, 'unarchive']);
    }

    private function clientLine(int $clientId = self::CLIENT, string $number = self::CLIENT_NUMBER): SmsLine
    {
        return SmsLine::create([
            'label' => 'Client ' . $clientId . ' front desk',
            'phone_number' => $number,
            'active' => true,
            'client_id' => $clientId,
        ]);
    }

    private function houseLine(array $users = []): SmsLine
    {
        return $this->line($users, ['phone_number' => self::HOUSE_NUMBER]);
    }

    private function inbound(SmsThread $thread, string $body = 'Hello?'): SmsMessage
    {
        return app(SmsService::class)->recordInbound($thread, $body);
    }

    private function useResolver(): void
    {
        $this->app['config']->set('visns-packages.messaging.client_resolver', StubClientResolver::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function test_it_lists_only_that_clients_lines_and_threads(): void
    {
        $mine = $this->clientLine();
        $theirs = $this->clientLine(self::OTHER_CLIENT, self::OTHER_CLIENT_NUMBER);
        $house = $this->houseLine();

        $myThread = $this->thread($mine);
        $this->thread($theirs);
        $this->thread($house);

        $lines = $this->getJson(self::PORTAL . '/lines')->assertOk()->json('data');
        $this->assertSame([(int) $mine->id], array_column($lines, 'id'));

        $threads = $this->getJson(self::PORTAL . '/threads')->assertOk()->json('data');
        $this->assertSame([(int) $myThread->id], array_column($threads, 'id'));

        // A line filter naming someone else's line is an empty list, not a leak.
        $this->assertSame([], $this->getJson(self::PORTAL . '/threads?line_id=' . $house->id)->json('data'));
        $this->assertSame([], $this->getJson(self::PORTAL . '/threads?line_id=' . $theirs->id)->json('data'));
    }

    public function test_a_house_or_another_clients_thread_is_the_same_404_as_an_invented_id(): void
    {
        $this->clientLine();
        $houseThread = $this->thread($this->houseLine());
        $theirThread = $this->thread($this->clientLine(self::OTHER_CLIENT, self::OTHER_CLIENT_NUMBER));

        $requests = [
            fn ($id) => $this->getJson(self::PORTAL . '/threads/' . $id),
            fn ($id) => $this->postJson(self::PORTAL . '/threads/' . $id . '/messages', ['body' => 'Hi']),
            fn ($id) => $this->postJson(self::PORTAL . '/threads/' . $id . '/read'),
            fn ($id) => $this->postJson(self::PORTAL . '/threads/' . $id . '/archive'),
            fn ($id) => $this->postJson(self::PORTAL . '/threads/' . $id . '/unarchive'),
        ];

        foreach ($requests as $request) {
            $invented = $request(999999)->assertStatus(404)->getContent();

            foreach ([$houseThread->id, $theirThread->id] as $id) {
                $this->assertSame($invented, $request($id)->assertStatus(404)->getContent());
            }
        }

        // Nothing was sent, marked or archived on either.
        $this->assertSame(0, SmsMessage::query()->count());
        $this->assertNull($houseThread->fresh()->archived_at);
        $this->assertNull($theirThread->fresh()->archived_at);
    }

    public function test_starting_a_thread_on_a_house_or_another_clients_line_is_404(): void
    {
        $house = $this->houseLine();
        $theirs = $this->clientLine(self::OTHER_CLIENT, self::OTHER_CLIENT_NUMBER);

        foreach ([$house, $theirs] as $line) {
            $this->postJson(self::PORTAL . '/threads', ['line_id' => $line->id, 'to' => '0412345678', 'body' => 'Hi'])
                ->assertStatus(404);
        }

        $this->assertSame(0, SmsThread::query()->count());
        $this->assertSame(0, SmsMessage::query()->count());

        $mine = $this->clientLine();

        $this->postJson(self::PORTAL . '/threads', ['line_id' => $mine->id, 'to' => '0412345678'])
            ->assertStatus(201)
            ->assertJsonPath('thread.line_id', $mine->id);
    }

    public function test_the_staff_inbox_still_cannot_see_any_of_it(): void
    {
        $admin = $this->admin();
        $thread = $this->thread($this->clientLine());
        $this->inbound($thread);

        $this->actingAs($admin)->getJson(self::BASE . '/threads')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson(self::BASE . '/threads/' . $thread->id)->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function test_a_portal_send_is_recorded_by_name_with_no_user(): void
    {
        $thread = $this->thread($this->clientLine());

        $response = $this->postJson(self::PORTAL . '/threads/' . $thread->id . '/messages', ['body' => 'We open at nine.'])
            ->assertStatus(201);

        $this->assertSame(['id' => null, 'name' => ClientInboxController::SENDER], $response->json('message.user'));

        $message = SmsMessage::query()->sole();
        $this->assertNull($message->user_id);
        $this->assertSame(ClientInboxController::SENDER, $message->sent_by_name);

        // And it reads back the same way when the thread is opened.
        $this->getJson(self::PORTAL . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('messages.0.user', ['id' => null, 'name' => ClientInboxController::SENDER]);
    }

    public function test_starting_a_thread_with_a_body_records_the_sender_by_name(): void
    {
        $line = $this->clientLine();

        $this->postJson(self::PORTAL . '/threads', ['line_id' => $line->id, 'to' => '0412345678', 'body' => 'Hello'])
            ->assertStatus(201)
            ->assertJsonPath('message.user', ['id' => null, 'name' => ClientInboxController::SENDER]);

        $this->assertSame(ClientInboxController::SENDER, SmsMessage::query()->sole()->sent_by_name);
    }

    public function test_a_staff_send_is_unchanged(): void
    {
        $member = $this->member();
        $thread = $this->thread($this->line([$member]));

        $response = $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hi'])
            ->assertStatus(201);

        $this->assertSame(['id' => $member->id, 'name' => $member->name], $response->json('message.user'));
        $this->assertSame((int) $member->id, (int) SmsMessage::query()->sole()->user_id);
        $this->assertNull(SmsMessage::query()->sole()->sent_by_name);

        // The staff thread payload keeps every key it had, `client` included,
        // and a real unread count.
        $this->assertSame([
            'id', 'line_id', 'external_number', 'display_number', 'client', 'contact_name',
            'can_reply', 'opted_out', 'last_message', 'unread_count', 'archived_at', 'updated_at',
        ], array_keys($response->json('thread')));
        $this->assertSame(0, $response->json('thread.unread_count'));
    }

    /*
    |--------------------------------------------------------------------------
    | Unread, per portal reader
    |--------------------------------------------------------------------------
    */

    public function test_unread_is_per_reader(): void
    {
        $line = $this->clientLine();
        $thread = $this->thread($line);
        $this->inbound($thread);

        foreach ([1, 2] as $reader) {
            ClientInboxController::$readerId = $reader;

            $this->getJson(self::PORTAL . '/unread')
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('by_line.' . $line->id, 1)
                ->assertJsonPath('by_thread.' . $thread->id, 1);
        }

        // Reader 1 opens the thread.
        ClientInboxController::$readerId = 1;
        $this->getJson(self::PORTAL . '/threads/' . $thread->id)->assertOk()->assertJsonPath('thread.unread_count', 0);

        $this->getJson(self::PORTAL . '/unread')->assertJsonPath('total', 0)->assertJsonPath('by_thread', []);
        $this->getJson(self::PORTAL . '/lines')->assertJsonPath('data.0.unread_count', 0);

        // Reader 2 has not.
        ClientInboxController::$readerId = 2;
        $this->getJson(self::PORTAL . '/unread')->assertJsonPath('total', 1);
        $this->getJson(self::PORTAL . '/lines')->assertJsonPath('data.0.unread_count', 1);
        $this->getJson(self::PORTAL . '/threads')->assertJsonPath('data.0.unread_count', 1);
        $this->assertSame(
            [(int) $thread->id],
            array_column($this->getJson(self::PORTAL . '/threads?unread_only=1')->json('data'), 'id')
        );

        // The read endpoint does the same for reader 2.
        $this->postJson(self::PORTAL . '/threads/' . $thread->id . '/read')->assertNoContent();
        $this->getJson(self::PORTAL . '/unread')->assertJsonPath('total', 0);

        // A newer message is unread for both again.
        $this->inbound($thread, 'One more thing');

        foreach ([1, 2] as $reader) {
            ClientInboxController::$readerId = $reader;
            $this->getJson(self::PORTAL . '/unread')->assertJsonPath('total', 1);
        }

        // No staff read row was written for any of it.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('sms_thread_reads')->count());
    }

    public function test_an_archived_thread_counts_zero(): void
    {
        $thread = $this->thread($this->clientLine());
        $this->inbound($thread);

        $this->postJson(self::PORTAL . '/threads/' . $thread->id . '/archive')->assertNoContent();

        $this->getJson(self::PORTAL . '/unread')->assertJsonPath('total', 0);
        $this->getJson(self::PORTAL . '/threads?archived=1')
            ->assertJsonPath('data.0.id', $thread->id)
            ->assertJsonPath('data.0.unread_count', 0);

        // The mark was never moved, so un-archiving brings the count back.
        $this->postJson(self::PORTAL . '/threads/' . $thread->id . '/unarchive')->assertNoContent();
        $this->getJson(self::PORTAL . '/unread')->assertJsonPath('total', 1);
    }

    public function test_reader_counts_answer_nothing_for_empty_restrictions(): void
    {
        $thread = $this->thread($this->clientLine());
        $this->inbound($thread);

        $this->assertSame([], SmsThread::unreadCountsForReader('portal_user', 1, []));
        $this->assertSame([], SmsThread::unreadCountsForReader('portal_user', 1, null, []));
        $this->assertSame([(int) $thread->id => 1], SmsThread::unreadCountsForReader('portal_user', 1));

        // Another kind of reader with the same id has a mark of its own.
        \Visnsstudio\VisnsPackages\Models\SmsThreadReaderMark::mark((int) $thread->id, 'someone_else', 1, PHP_INT_MAX);
        $this->assertSame([(int) $thread->id => 1], SmsThread::unreadCountsForReader('portal_user', 1));
        $this->assertSame([], SmsThread::unreadCountsForReader('someone_else', 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound, and the host's client book
    |--------------------------------------------------------------------------
    */

    public function test_an_inbound_message_on_the_client_line_appears_in_its_list(): void
    {
        $this->useResolver();
        $line = $this->clientLine();

        $json = json_encode([
            'event' => 'phone.sms_received',
            'payload' => [
                'object' => [
                    'session_id' => 'session-client',
                    'message_id' => 'client-msg-1',
                    'message' => 'Is the sale still on?',
                    'message_type' => 1,
                    'sender' => ['phone_number' => self::TEXTER],
                    'to_members' => [['phone_number' => self::CLIENT_NUMBER]],
                    'date_time' => '2026-10-14T03:04:05Z',
                ],
            ],
        ]);
        $timestamp = (string) time();

        $this->call('POST', '/api/zoom/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZM_SIGNATURE' => 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $json, self::SMS_APP_SECRET),
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
        ], $json)->assertOk();

        $rows = $this->getJson(self::PORTAL . '/threads')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(self::TEXTER, $rows[0]['external_number']);
        $this->assertSame('Is the sale still on?', $rows[0]['last_message']['body']);
        $this->assertSame(1, $rows[0]['unread_count']);
        $this->assertSame((int) $line->id, (int) $rows[0]['line_id']);

        // The host's resolver would have matched this texter; it was never asked.
        $this->assertSame([], StubClientResolver::$calls);
        $this->assertNull(SmsThread::query()->sole()->client_id);
    }

    public function test_find_or_create_never_resolves_a_client_line_but_still_resolves_the_house(): void
    {
        $resolver = new StubClientResolver();

        $client = SmsThread::findOrCreateFor($this->clientLine(), self::TEXTER, $resolver);

        $this->assertSame([], StubClientResolver::$calls);
        $this->assertNull($client->client_id);
        $this->assertNull($client->client_name);

        $house = SmsThread::findOrCreateFor($this->houseLine(), self::TEXTER, $resolver);

        $this->assertSame([self::TEXTER], StubClientResolver::$calls);
        $this->assertSame(7, (int) $house->client_id);
        $this->assertSame('Cleo Client', $house->client_name);
    }

    public function test_no_portal_payload_carries_a_client_key(): void
    {
        $this->useResolver();
        $line = $this->clientLine();

        // Even a thread somebody linked by hand (staff tooling, a data fix)
        // never shows the link to the portal.
        $linked = $this->thread($line, '+61412000111', ['client_id' => 42, 'client_name' => 'Host Client']);
        $this->inbound($linked);

        $created = $this->postJson(self::PORTAL . '/threads', ['line_id' => $line->id, 'to' => '0412345678', 'body' => 'Hi'])
            ->assertStatus(201);
        $this->assertArrayNotHasKey('client', $created->json('thread'));
        $this->assertSame([], StubClientResolver::$calls);

        foreach ($this->getJson(self::PORTAL . '/threads')->json('data') as $row) {
            $this->assertArrayNotHasKey('client', $row);
        }

        foreach ($this->getJson(self::PORTAL . '/threads?unread_only=1')->json('data') as $row) {
            $this->assertArrayNotHasKey('client', $row);
        }

        $this->assertArrayNotHasKey('client', $this->getJson(self::PORTAL . '/threads/' . $linked->id)->json('thread'));

        $sent = $this->postJson(self::PORTAL . '/threads/' . $linked->id . '/messages', ['body' => 'Hello'])->assertStatus(201);
        $this->assertArrayNotHasKey('client', $sent->json('thread'));
    }

    /*
    |--------------------------------------------------------------------------
    | Opt-outs
    |--------------------------------------------------------------------------
    */

    public function test_opted_out_follows_the_clients_register(): void
    {
        $member = $this->member();
        $houseLine = $this->houseLine([$member]);
        $clientLine = $this->clientLine();
        $optOuts = app(SmsOptOuts::class);

        $houseStop = '+61412000001';
        $clientStop = '+61412000002';

        // A STOP to the house...
        $optOuts->record($houseStop, SmsOptOut::SOURCE_KEYWORD);
        // ...and one to the client.
        $optOuts->record($clientStop, SmsOptOut::SOURCE_KEYWORD, null, null, null, null, self::CLIENT);

        $portalHouseStop = $this->thread($clientLine, $houseStop);
        $portalClientStop = $this->thread($clientLine, $clientStop);
        $staffHouseStop = $this->thread($houseLine, $houseStop);
        $staffClientStop = $this->thread($houseLine, $clientStop);

        $portalRows = collect($this->getJson(self::PORTAL . '/threads')->json('data'))->keyBy('id');
        $this->assertFalse($portalRows[$portalHouseStop->id]['opted_out']);
        $this->assertTrue($portalRows[$portalClientStop->id]['opted_out']);

        $this->getJson(self::PORTAL . '/threads/' . $portalHouseStop->id)->assertJsonPath('thread.opted_out', false);
        $this->getJson(self::PORTAL . '/threads/' . $portalClientStop->id)->assertJsonPath('thread.opted_out', true);

        $staffRows = collect($this->actingAs($member)->getJson(self::BASE . '/threads')->json('data'))->keyBy('id');
        $this->assertTrue($staffRows[$staffHouseStop->id]['opted_out']);
        $this->assertFalse($staffRows[$staffClientStop->id]['opted_out']);

        $this->actingAs($member)->getJson(self::BASE . '/threads/' . $staffHouseStop->id)
            ->assertJsonPath('thread.opted_out', true);
        $this->actingAs($member)->getJson(self::BASE . '/threads/' . $staffClientStop->id)
            ->assertJsonPath('thread.opted_out', false);
    }
}
