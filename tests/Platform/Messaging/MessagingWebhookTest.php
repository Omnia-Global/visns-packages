<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\SmsMessageUpdated;
use Visnsstudio\VisnsPackages\Events\SmsReceived;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\StubClientResolver;

/**
 * The SMS half of the Zoom Phone webhook.
 *
 * SMS events arrive on the call queue's endpoint because Zoom subscribes ONE URL
 * per marketplace app, so this suite turns the call queue on too - that is the
 * real deployment shape, not a test convenience.
 *
 * None of these payload shapes has been seen from a live SMS-enabled account
 * (the practice is waiting on an SMS-capable number); they follow Zoom's
 * published reference, and this file is the record of what the module currently
 * believes.
 */
class MessagingWebhookTest extends MessagingTestCase
{
    private const SECRET = 'zoom-signing-secret';

    private const LINE_NUMBER = '+61893752549';

    protected function setUp(): void
    {
        parent::setUp();

        StubClientResolver::reset();
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set('visns-packages.call_queue.webhook_secret_token', self::SECRET);
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

    private function receivedPayload(array $overrides = []): array
    {
        return [
            'event' => 'phone.sms_received',
            'payload' => [
                'object' => array_merge([
                    'session_id' => 'session-abc',
                    'message_id' => 'msg-1',
                    'message' => 'Running late, be there at 10.',
                    'message_type' => 1,
                    'sender' => ['phone_number' => '+61412345678'],
                    'to_members' => [['phone_number' => self::LINE_NUMBER]],
                    'owner' => ['id' => 'zoom-user-1', 'type' => 'user'],
                    'date_time' => '2026-08-21T03:04:05Z',
                ], $overrides),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    public function test_an_inbound_text_creates_the_thread_and_the_message(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload())
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $thread = SmsThread::first();

        $this->assertNotNull($thread);
        $this->assertSame('+61412345678', $thread->external_number);
        $this->assertSame('session-abc', $thread->provider_session_id);
        $this->assertSame('in', $thread->last_direction);
        $this->assertSame('Running late, be there at 10.', $thread->last_message_preview);

        $message = SmsMessage::first();

        $this->assertSame('in', $message->direction);
        $this->assertSame(SmsMessage::STATUS_RECEIVED, $message->status);
        $this->assertSame('msg-1', $message->provider_message_id);
        $this->assertNull($message->user_id);

        // The provider's own JSON is kept verbatim - it is what says how these
        // guesses differ from reality when the live account starts sending.
        $this->assertSame('zoom-user-1', $message->raw_payload['owner']['id']);
    }

    public function test_an_inbound_text_resolves_the_client_through_the_configured_hook(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_resolver',
            StubClientResolver::class
        );

        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload())->assertOk();

        $thread = SmsThread::first();

        $this->assertSame(7, (int) $thread->client_id);
        $this->assertSame('Cleo Client', $thread->client_name);
    }

    public function test_a_redelivered_inbound_text_does_not_appear_twice(): void
    {
        Event::fake([SmsReceived::class]);

        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload())->assertJson(['status' => 'ok']);

        // Zoom retries anything it did not get a timely 200 for.
        $this->signedPost($this->receivedPayload())->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, SmsMessage::count());

        // And the second delivery must not pop a notification again.
        Event::assertDispatchedTimes(SmsReceived::class, 1);
    }

    public function test_an_inbound_text_for_an_unknown_number_is_acknowledged_and_dropped(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'to_members' => [['phone_number' => '+61899999999']],
        ]))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, SmsThread::count());
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_the_line_is_matched_however_zoom_spells_the_number(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'to_members' => [['phone_number' => '08 9375 2549']],
            'sender' => ['phone_number' => '0412 345 678'],
        ]))->assertJson(['status' => 'ok']);

        $this->assertSame('+61412345678', SmsThread::first()->external_number);
    }

    public function test_an_inbound_text_carries_its_mms_attachments(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'message_type' => 2,
            'attachments' => [[
                'id' => 'att-1',
                'name' => 'statement.pdf',
                'type' => 'application/pdf',
                'size' => 12345,
                'download_url' => 'https://zoom.example/att-1',
            ]],
        ]))->assertOk();

        $attachments = SmsMessage::first()->attachments;

        $this->assertCount(1, $attachments);
        $this->assertSame('statement.pdf', $attachments[0]['name']);
        $this->assertSame(12345, $attachments[0]['size']);
    }

    public function test_an_inbound_text_broadcasts_on_the_lines_channel(): void
    {
        Event::fake([SmsReceived::class]);

        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload())->assertOk();

        Event::assertDispatched(SmsReceived::class, function (SmsReceived $event) use ($line) {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'sms.received'
                && $event->broadcastOn()->name === 'private-sms-line.' . $line->id
                && $payload['message']['direction'] === 'in'
                && $payload['thread']['line_id'] === $line->id
                // No user in scope on a broadcast, so the per-user count is null
                // and the front end recounts.
                && $payload['thread']['unread_count'] === null;
        });
    }

    public function test_an_inbound_text_leaves_the_thread_unread_for_the_lines_staff(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload())->assertOk();

        $this->actingAs($member)
            ->getJson(self::BASE . '/unread')
            ->assertJsonPath('total', 1);
    }

    public function test_a_malformed_payload_still_answers_200(): void
    {
        // Zoom retries and eventually disables endpoints that error.
        $this->signedPost(['event' => 'phone.sms_received', 'payload' => ['object' => []]])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound confirmations
    |--------------------------------------------------------------------------
    */

    public function test_a_sent_confirmation_stamps_the_message_we_sent(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => self::LINE_NUMBER]);
        $thread = $this->thread($line);

        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => 'out',
            'body' => 'Booked you in.',
            'status' => SmsMessage::STATUS_QUEUED,
            'provider_message_id' => 'msg-out-1',
        ]);

        $this->signedPost([
            'event' => 'phone.sms_sent',
            'payload' => ['object' => [
                'message_id' => 'msg-out-1',
                'date_time' => '2026-08-21T03:04:05Z',
            ]],
        ])->assertOk()->assertJson(['status' => 'ok']);

        $message->refresh();

        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->sent_at);
    }

    public function test_a_failure_marks_the_message_failed_with_zooms_reason(): void
    {
        Event::fake([SmsMessageUpdated::class]);

        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => self::LINE_NUMBER]);
        $thread = $this->thread($line);

        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => 'out',
            'body' => 'Booked you in.',
            'status' => SmsMessage::STATUS_SENT,
            'provider_message_id' => 'msg-out-2',
        ]);

        $this->signedPost([
            'event' => 'phone.sms_sent_failed',
            'payload' => ['object' => [
                'message_id' => 'msg-out-2',
                'reason' => 'The recipient number is not reachable.',
            ]],
        ])->assertOk()->assertJson(['status' => 'ok']);

        $message->refresh();

        $this->assertSame(SmsMessage::STATUS_FAILED, $message->status);
        $this->assertSame('The recipient number is not reachable.', $message->error);

        Event::assertDispatched(SmsMessageUpdated::class);
    }

    public function test_a_failure_for_a_message_we_never_sent_is_dropped(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost([
            'event' => 'phone.sms_sent_failed',
            'payload' => ['object' => ['message_id' => 'nothing-here']],
        ])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, SmsMessage::count());
    }

    public function test_a_text_sent_from_the_zoom_app_is_threaded_too(): void
    {
        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        // Half a conversation would be invisible in the CRM without this: the
        // client's replies threaded, the adviser's own texts from their phone
        // not.
        $this->signedPost([
            'event' => 'phone.sms_sent',
            'payload' => ['object' => [
                'message_id' => 'msg-from-app',
                'message' => 'Sent from my phone',
                'sender' => ['phone_number' => self::LINE_NUMBER],
                'to_members' => [['phone_number' => '+61412345678']],
                'date_time' => '2026-08-21T03:04:05Z',
            ]],
        ])->assertOk()->assertJson(['status' => 'ok']);

        $message = SmsMessage::first();

        $this->assertSame('out', $message->direction);
        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);
        // Zoom identifies the sender by extension, not by CRM user.
        $this->assertNull($message->user_id);

        $this->assertSame($line->id, SmsThread::first()->line_id);
    }

    public function test_an_unrecognised_sms_event_is_acknowledged(): void
    {
        // Not in SMS_EVENTS at all: falls through to the controller's own
        // unhandled path, which still answers 200.
        $this->signedPost([
            'event' => 'phone.sms_something_new',
            'payload' => ['object' => []],
        ])->assertOk();
    }
}
