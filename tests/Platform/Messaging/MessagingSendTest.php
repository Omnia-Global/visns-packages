<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\SmsMessageUpdated;
use Visnsstudio\VisnsPackages\Events\SmsReceived;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Services\Sms\NullSmsTransport;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Support\SmsChannel;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\AppSmsReceived;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\RacingSmsTransport;

/**
 * Sending: the three transports, what each leaves behind, and what the browsers
 * are told about it.
 */
class MessagingSendTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();
        RacingSmsTransport::reset();
    }

    private function useZoom(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');

        // The seam the real transport resolves through: bind a fake and nothing
        // can reach a live Zoom tenant.
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());
    }

    /*
    |--------------------------------------------------------------------------
    | The null transport - the production default until Zoom is connected
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Receive-only threads
    |--------------------------------------------------------------------------
    |
    | A thread opened by an inbound message from an alphanumeric sender ID -
    | `Apple`, a bank, a short code - has no handset behind it. It exists so a
    | shared line can READ a two-factor code; nothing can be sent back to it.
    */

    public function test_a_thread_from_a_sender_id_says_it_cannot_be_replied_to(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $numbered = $this->thread($line);
        $senderId = $this->thread($line, 'Apple');

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $senderId->id)
            ->assertOk()
            ->assertJsonPath('thread.can_reply', false);

        // The flag is on every thread, not only the awkward ones: a key that
        // appeared on some rows and not others would read as false wherever it
        // was missing.
        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $numbered->id)
            ->assertOk()
            ->assertJsonPath('thread.can_reply', true);
    }

    public function test_a_reply_to_a_sender_id_is_refused_with_a_sentence(): void
    {
        $this->useZoom();

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, 'Apple');

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', [
                'body' => 'thanks',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Apple is a sender ID, not a phone number — messages from it are one-way.');

        // Nothing was stored and nothing was sent: a queued row would sit in the
        // thread as a message the practice believes it sent.
        $this->assertSame(0, SmsMessage::count());
        $this->assertSame([], FakeZoomSmsClient::$sends);
    }

    public function test_an_ordinary_thread_is_untouched_by_that_guard(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201);
    }

    public function test_a_send_with_no_transport_is_stored_rather_than_refused(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $response = $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', [
                'body' => 'Your review is booked for Thursday.',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('message.status', SmsMessage::STATUS_NOT_CONNECTED);
        $response->assertJsonPath('message.error', NullSmsTransport::MESSAGE);
        $response->assertJsonPath('message.direction', 'out');
        $response->assertJsonPath('message.sent_at', null);

        // It is still the newest thing in the conversation - the practice has to
        // be able to see what it tried to send - and the list can badge it
        // without opening the thread.
        $fresh = $thread->fresh();

        $this->assertSame('Your review is booked for Thursday.', $fresh->last_message_preview);
        $this->assertSame(SmsMessage::STATUS_NOT_CONNECTED, $fresh->last_message_status);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads')
            ->assertJsonPath('data.0.last_message.status', SmsMessage::STATUS_NOT_CONNECTED);
    }

    public function test_the_sender_is_recorded_on_an_outbound_message(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201)
            ->assertJsonPath('message.user.id', $member->id)
            ->assertJsonPath('message.user.name', $member->name);
    }

    public function test_an_empty_body_is_refused(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => ''])
            ->assertStatus(422);
    }

    public function test_a_body_over_the_configured_maximum_is_refused(): void
    {
        $this->app['config']->set('visns-packages.messaging.max_body_length', 20);

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', [
                'body' => str_repeat('x', 21),
            ])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | The dev transport
    |--------------------------------------------------------------------------
    */

    public function test_the_dev_transport_reports_success_and_texts_back(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Knock knock'])
            ->assertStatus(201)
            ->assertJsonPath('message.status', SmsMessage::STATUS_SENT);

        $messages = SmsMessage::orderBy('id')->get();

        $this->assertCount(2, $messages);
        $this->assertSame('out', $messages[0]->direction);
        $this->assertStringStartsWith('log-', (string) $messages[0]->provider_message_id);

        $this->assertSame('in', $messages[1]->direction);
        $this->assertSame(SmsMessage::STATUS_RECEIVED, $messages[1]->status);
        $this->assertStringContainsString('Knock knock', (string) $messages[1]->body);

        // The reply is stamped a little later, so the thread sorts the way a
        // conversation reads.
        $this->assertTrue($messages[1]->received_at->greaterThan($messages[0]->created_at));
    }

    public function test_the_dev_transports_reply_leaves_the_thread_unread(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201);

        $this->actingAs($member)
            ->getJson(self::BASE . '/unread')
            ->assertJsonPath('total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | The Zoom transport
    |--------------------------------------------------------------------------
    */

    public function test_the_zoom_request_body_follows_zooms_send_sms_shape(): void
    {
        $this->useZoom();

        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => '+61893752549', 'zoom_user_id' => 'zoom-user-1']);
        $thread = $this->thread($line, '+61412345678');

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'On my way'])
            ->assertStatus(201)
            ->assertJsonPath('message.status', SmsMessage::STATUS_SENT);

        $this->assertCount(1, FakeZoomSmsClient::$sends);

        $send = FakeZoomSmsClient::$sends[0];

        $this->assertSame('+61893752549', $send['from']);
        $this->assertSame('+61412345678', $send['to']);

        // The one place the wire shape is pinned. If Zoom's live account turns
        // out to disagree, this assertion and ZoomSmsClient::sendBody() are what
        // change.
        $this->assertSame([
            'sender' => ['user_id' => 'zoom-user-1', 'phone_number' => '+61893752549'],
            'to_members' => [['phone_number' => '+61412345678']],
            'message' => 'On my way',
        ], $send['request']);
    }

    public function test_a_line_with_no_zoom_user_sends_without_a_user_id(): void
    {
        $this->useZoom();

        $member = $this->member();
        $line = $this->line([$member], ['phone_number' => '+61893752549']);
        $thread = $this->thread($line, '+61412345678');

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'On my way'])
            ->assertStatus(201);

        // Omitted, not sent as an empty string: Zoom's own error then says
        // which field is missing.
        $this->assertSame(['phone_number' => '+61893752549'], FakeZoomSmsClient::$sends[0]['request']['sender']);
    }

    public function test_zooms_message_id_is_stored_so_a_later_webhook_can_find_the_row(): void
    {
        $this->useZoom();

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201);

        $this->assertSame(
            'zoom-message-1',
            SmsMessage::where('direction', 'out')->first()->provider_message_id
        );
    }

    public function test_a_zoom_refusal_is_stored_as_a_failure_with_zooms_own_words(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$response = [
            'success' => false,
            'http_code' => 400,
            'data' => ['code' => 300, 'message' => 'The sender number is not SMS enabled.'],
        ];

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201)
            ->assertJsonPath('message.status', SmsMessage::STATUS_FAILED)
            ->assertJsonPath('message.error', 'The sender number is not SMS enabled.');
    }

    public function test_zoom_being_unreachable_is_a_failed_message_not_a_500(): void
    {
        $this->useZoom();

        FakeZoomSmsClient::$shouldThrow = true;

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201)
            ->assertJsonPath('message.status', SmsMessage::STATUS_FAILED);
    }

    public function test_an_unusable_transport_falls_back_to_sending_nothing(): void
    {
        // Failing closed: the alternative to "nothing was sent" must not be a
        // 500 on every send.
        $this->app['config']->set(
            'visns-packages.messaging.transport',
            'App\\Sms\\NoSuchTransport'
        );

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201)
            ->assertJsonPath('message.status', SmsMessage::STATUS_NOT_CONNECTED);
    }

    /*
    |--------------------------------------------------------------------------
    | The webhook getting there first
    |--------------------------------------------------------------------------
    */

    public function test_a_webhook_that_beats_the_send_does_not_cost_the_message(): void
    {
        // `provider_message_id` is unique, so the webhook having already stored
        // the id this send is holding used to surface as a 500 on a text the
        // client had already received.
        $this->app['config']->set(
            'visns-packages.messaging.transport',
            RacingSmsTransport::class
        );

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'On my way'])
            ->assertStatus(201);

        $outbound = SmsMessage::where('direction', 'out')->get();

        // One message in the thread, not the send's row and the webhook's copy
        // of it.
        $this->assertCount(1, $outbound);
        $this->assertSame(RacingSmsTransport::PROVIDER_ID, $outbound[0]->provider_message_id);

        // The row that survives is the one that knows who pressed send - the
        // webhook's copy has no user, because Zoom identifies a sender by
        // extension.
        $this->assertSame($member->id, (int) $outbound[0]->user_id);
        $this->assertSame(SmsMessage::STATUS_SENT, $outbound[0]->status);
        $this->assertNotNull($outbound[0]->sent_at);

        // And the thread list summary is the surviving row's, not the deleted
        // one's - touchLastMessage() runs after the collision is resolved.
        $fresh = $thread->fresh();

        $this->assertSame('On my way', $fresh->last_message_preview);
        $this->assertSame(SmsMessage::STATUS_SENT, $fresh->last_message_status);
    }

    public function test_a_provider_id_already_used_elsewhere_costs_the_id_not_the_message(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.transport',
            RacingSmsTransport::class
        );

        // The collision here is with a row that is nothing to do with this send,
        // so the transport writes no duplicate of its own.
        RacingSmsTransport::$inserts = false;

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);
        $other = $this->thread($line, '+61498765432');

        // Nothing should put the same id on an unrelated conversation, but if a
        // provider ever did, the send must still be recorded.
        $stranger = SmsMessage::create([
            'thread_id' => $other->id,
            'direction' => 'in',
            'body' => 'Not this send at all.',
            'status' => SmsMessage::STATUS_RECEIVED,
            'provider_message_id' => RacingSmsTransport::PROVIDER_ID,
            'received_at' => now(),
        ]);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'On my way'])
            ->assertStatus(201);

        $mine = SmsMessage::where('thread_id', $thread->id)->where('direction', 'out')->first();

        // Ours kept its body, its status and its sender; only the id it could
        // not have is gone.
        $this->assertNotNull($mine);
        $this->assertNull($mine->provider_message_id);
        $this->assertSame('On my way', $mine->body);
        $this->assertSame(SmsMessage::STATUS_SENT, $mine->status);
        $this->assertSame($member->id, (int) $mine->user_id);

        // And the stranger's row is exactly where it was.
        $this->assertSame(
            RacingSmsTransport::PROVIDER_ID,
            $stranger->fresh()->provider_message_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Broadcasts
    |--------------------------------------------------------------------------
    */

    public function test_sending_tells_the_lines_browsers(): void
    {
        Event::fake([SmsMessageUpdated::class]);

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201);

        Event::assertDispatched(
            SmsMessageUpdated::class,
            function (SmsMessageUpdated $event) use ($thread, $line) {
                $payload = $event->broadcastWith();

                return $event->thread->id === $thread->id
                    && $event->broadcastAs() === 'sms.updated'
                    && $event->broadcastOn()->name === 'private-' . SmsChannel::name($line->id)
                    && $payload['message']['body'] === 'Hello'
                    && $payload['thread']['id'] === $thread->id;
            }
        );
    }

    public function test_the_channel_is_per_line_and_can_carry_the_environment(): void
    {
        $this->assertSame('sms-line.12', SmsChannel::name(12));
        $this->assertSame('sms-line.{lineId}', SmsChannel::pattern());

        $this->app['config']->set('visns-packages.messaging.append_env_suffix', true);

        $this->assertSame('sms-line.12.' . config('app.env'), SmsChannel::name(12));
        $this->assertSame('sms-line.{lineId}.' . config('app.env'), SmsChannel::pattern());
    }

    public function test_an_applications_own_event_class_is_dispatched_when_configured(): void
    {
        // Laravel's Event::fake() keys listeners by exact class name, which is
        // the whole reason this is configurable.
        $this->app['config']->set(
            'visns-packages.messaging.events.received',
            AppSmsReceived::class
        );
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        Event::fake([AppSmsReceived::class, SmsReceived::class]);

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201);

        Event::assertDispatched(AppSmsReceived::class);
        Event::assertNotDispatched(SmsReceived::class);
    }

    public function test_a_configured_event_class_that_does_not_exist_falls_back_to_the_packages(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.events.received',
            'App\\Events\\NoSuchSmsEvent'
        );
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        Event::fake([SmsReceived::class]);

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', ['body' => 'Hello'])
            ->assertStatus(201);

        Event::assertDispatched(SmsReceived::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The inbound simulator
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_simulate_an_inbound_message(): void
    {
        Event::fake([SmsReceived::class]);

        $admin = $this->admin();
        $line = $this->line([$admin]);
        $thread = $this->thread($line);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/simulate-inbound', [
                'body' => 'Sounds good',
            ])
            ->assertStatus(201)
            ->assertJsonPath('message.direction', 'in')
            ->assertJsonPath('message.status', SmsMessage::STATUS_RECEIVED);

        Event::assertDispatched(SmsReceived::class);
    }

    public function test_a_member_cannot_simulate_an_inbound_message(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/simulate-inbound', ['body' => 'Hi'])
            ->assertStatus(404);
    }

    public function test_simulating_an_inbound_message_is_refused_once_zoom_is_connected(): void
    {
        $this->useZoom();

        $admin = $this->admin();
        $line = $this->line([$admin]);
        $thread = $this->thread($line);

        $this->actingAs($admin)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/simulate-inbound', ['body' => 'Hi'])
            ->assertStatus(422);

        $this->assertSame(0, SmsMessage::count());
    }
}
