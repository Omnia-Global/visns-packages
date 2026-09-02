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

    public function test_zooms_escaped_newlines_are_stored_as_real_ones(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        // Observed live: Zoom's webhook carries a line break as the two
        // characters backslash-n, not as a newline. The handler undoes it.
        $this->signedPost($this->receivedPayload([
            'message' => 'Line one.\n\nLine two.\r\nLine three.',
        ]))->assertOk();

        $message = SmsMessage::first();

        $this->assertSame(
            "Line one.\n\nLine two.\nLine three.",
            $message->body
        );
        $this->assertStringNotContainsString('\\n', $message->body);
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

    /*
    |--------------------------------------------------------------------------
    | Senders that are not numbers
    |--------------------------------------------------------------------------
    |
    | Observed live on 2026-08-31: Apple's two-factor codes arrive on the shared
    | line from the sender ID `Apple`, and every one of them was dropped with
    | "sender number could not be read". A team sharing a login has to be able
    | to read those, so they are threaded now.
    */

    public function test_a_two_factor_code_from_an_alphanumeric_sender_is_threaded(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'sender' => ['phone_number' => 'Apple'],
            'message' => 'Your Apple Account code is: 290172. Do not share it with anyone.',
        ]))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $thread = SmsThread::first();

        $this->assertNotNull($thread);
        // Kept exactly as the carrier sent it: this string is the thread title.
        $this->assertSame('Apple', $thread->external_number);
        $this->assertSame('in', $thread->last_direction);

        $this->assertSame(
            'Your Apple Account code is: 290172. Do not share it with anyone.',
            SmsMessage::first()->body
        );
    }

    public function test_a_short_code_sender_is_threaded_too(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'sender' => ['phone_number' => '27311'],
        ]))->assertJson(['status' => 'ok']);

        $this->assertSame('27311', SmsThread::first()->external_number);
    }

    /**
     * The whole point of the shared line: the second code has to land in the
     * SAME conversation, not open a new one - and the carrier is under no
     * obligation to spell the sender the same way twice.
     */
    public function test_two_codes_from_one_sender_are_one_thread_whatever_the_casing(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'sender' => ['phone_number' => 'Apple'],
            'message_id' => 'msg-1',
            'message' => 'Your Apple Account code is: 290172.',
        ]))->assertJson(['status' => 'ok']);

        $this->signedPost($this->receivedPayload([
            'sender' => ['phone_number' => 'APPLE'],
            'message_id' => 'msg-2',
            'message' => 'Your Apple Account code is: 325661.',
        ]))->assertJson(['status' => 'ok']);

        $this->assertSame(1, SmsThread::count());
        $this->assertSame(2, SmsMessage::count());
        // The first spelling wins, because it is the one already on screen.
        $this->assertSame('Apple', SmsThread::first()->external_number);
    }

    /**
     * The resolver is the host application's number -> client hook. A sender ID
     * has no digits in it to match, so asking is a question the application was
     * never written to answer.
     */
    public function test_the_client_resolver_is_not_asked_about_a_sender_id(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_resolver',
            StubClientResolver::class
        );

        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'sender' => ['phone_number' => 'Apple'],
            'message_id' => 'msg-sender-id',
        ]))->assertJson(['status' => 'ok']);

        $this->assertSame([], StubClientResolver::$calls);
        $this->assertNull(SmsThread::first()->client_id);

        // The control: the SAME configured resolver is still asked about a real
        // number, so the assertion above is about sender IDs and not about a
        // hook that was never wired up.
        $this->signedPost($this->receivedPayload(['message_id' => 'msg-number']))
            ->assertJson(['status' => 'ok']);

        $this->assertSame(['+61412345678'], StubClientResolver::$calls);
    }

    /**
     * A sender that is not a number AND not a sender id either. Still dropped,
     * because there is nothing to call the thread.
     */
    public function test_a_sender_that_is_neither_is_still_acknowledged_and_dropped(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost($this->receivedPayload([
            'sender' => ['phone_number' => '   '],
        ]))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, SmsThread::count());
        $this->assertSame(0, SmsMessage::count());
    }

    /**
     * A line cannot send TO a sender ID, so the outbound paths must not learn
     * the fallback: an unreadable sender on a sent event is still an event this
     * module has no thread for.
     */
    public function test_the_sender_id_fallback_does_not_reach_the_outbound_paths(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => self::LINE_NUMBER]);

        $this->signedPost([
            'event' => 'phone.sms_sent',
            'payload' => ['object' => [
                'session_id' => 'session-abc',
                'message_id' => 'msg-out',
                'message' => 'anything',
                'sender' => ['phone_number' => 'Apple'],
                'to_members' => [['phone_number' => '+61412345678']],
            ]],
        ])
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

    public function test_a_sent_confirmation_claims_a_send_still_in_flight(): void
    {
        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => self::LINE_NUMBER]);
        $thread = $this->thread($line, '+61412345678');

        // The shape SmsService::send() leaves behind while it is waiting on
        // Zoom's HTTP response: the row exists, the provider id does not yet.
        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUT,
            'body' => 'Booked you in.',
            'status' => SmsMessage::STATUS_QUEUED,
            'user_id' => $member->id,
        ]);

        $this->signedPost([
            'event' => 'phone.sms_sent',
            'payload' => ['object' => [
                'message_id' => 'msg-raced',
                'message' => 'Booked you in.',
                'sender' => ['phone_number' => self::LINE_NUMBER],
                'to_members' => [['phone_number' => '+61412345678']],
                'date_time' => '2026-08-21T03:04:05Z',
            ]],
        ])->assertOk()->assertJson(['status' => 'ok']);

        // One row, not two: the webhook confirmed our send rather than inventing
        // a second copy of it - which would also have collided with the unique
        // provider id the send is about to store.
        $this->assertSame(1, SmsMessage::count());

        $message->refresh();

        $this->assertSame('msg-raced', $message->provider_message_id);
        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->sent_at);

        // And it is still attributed to whoever pressed send.
        $this->assertSame($member->id, (int) $message->user_id);
    }

    public function test_a_sent_confirmation_does_not_claim_an_old_queued_message(): void
    {
        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => self::LINE_NUMBER]);
        $thread = $this->thread($line, '+61412345678');

        // Older than the race window - a send that stalled hours ago, not the
        // one this event is about. Claiming it would rewrite an unrelated
        // message's history.
        $stale = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUT,
            'body' => 'Something from this morning.',
            'status' => SmsMessage::STATUS_QUEUED,
            'user_id' => $member->id,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

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

        $this->assertSame(2, SmsMessage::count());

        $stale->refresh();

        $this->assertSame(SmsMessage::STATUS_QUEUED, $stale->status);
        $this->assertNull($stale->provider_message_id);
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
