<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Schema;
use Visnsstudio\VisnsPackages\Auth\ZoomSmsTwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsSystemMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Services\Sms\SmsSystemSender;
use Visnsstudio\VisnsPackages\Services\Sms\SmsWebhookHandler;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;

/**
 * Application-originated texts: login codes and portal OTPs go out on the same
 * Zoom line as everything else and are NEVER visible in the shared inbox.
 *
 * That last clause is the security property this whole file exists to pin. Any
 * change that puts a system send into a thread - directly, or by letting the
 * `phone.sms_sent` webhook thread its confirmation - hands one staff member
 * another's second factor, and should break here.
 */
class MessagingSystemSendTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();
    }

    private function useZoom(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());
    }

    private function sender(): SmsSystemSender
    {
        return $this->app->make(SmsSystemSender::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function test_a_system_send_goes_to_zoom_on_the_line_and_records_no_body(): void
    {
        $this->useZoom();

        $line = $this->line([], [
            'phone_number' => '+61893752549',
            'zoom_user_id' => 'zoom-user-9',
        ]);

        $result = $this->sender()->send('0412 345 678', 'Your code is 123456', 'two_factor');

        $this->assertTrue($result->ok);
        $this->assertSame('zoom-message-1', $result->providerMessageId);

        // The line's own number and the Zoom user it hangs off - Zoom refuses a
        // send without the second.
        $this->assertCount(1, FakeZoomSmsClient::$sends);
        $this->assertSame('+61893752549', FakeZoomSmsClient::$sends[0]['from']);
        $this->assertSame('+61412345678', FakeZoomSmsClient::$sends[0]['to']);
        $this->assertSame('Your code is 123456', FakeZoomSmsClient::$sends[0]['body']);
        $this->assertSame('zoom-user-9', FakeZoomSmsClient::$sends[0]['user_id']);

        $record = SmsSystemMessage::query()->first();

        $this->assertNotNull($record);
        $this->assertSame($line->id, (int) $record->line_id);
        $this->assertSame('two_factor', $record->purpose);
        $this->assertSame('+61412345678', $record->to_number);
        $this->assertSame(SmsSystemMessage::STATUS_SENT, $record->status);
        $this->assertSame('zoom-message-1', $record->provider_message_id);
        $this->assertNotNull($record->sent_at);
        $this->assertNull($record->error);

        // The code is not written down anywhere: there is no column that could
        // hold it, and nothing landed in the inbox.
        $this->assertFalse(Schema::hasColumn($record->getTable(), 'body'));
        $this->assertSame(0, SmsThread::query()->count());
        $this->assertSame(0, SmsMessage::query()->count());
    }

    public function test_the_configured_system_line_is_the_one_used(): void
    {
        $this->useZoom();

        // Both usable; only the second is named in config.
        $this->line([], ['phone_number' => '+61893752541', 'zoom_user_id' => 'zoom-user-1']);
        $chosen = $this->line([], ['phone_number' => '+61893752542', 'zoom_user_id' => 'zoom-user-2']);

        $this->app['config']->set('visns-packages.messaging.system_line', '+61893752542');

        $result = $this->sender()->send('+61412345678', 'Your code is 123456', 'two_factor');

        $this->assertTrue($result->ok);
        $this->assertSame('+61893752542', FakeZoomSmsClient::$sends[0]['from']);
        $this->assertSame('zoom-user-2', FakeZoomSmsClient::$sends[0]['user_id']);
        $this->assertSame($chosen->id, (int) $result->record->line_id);
    }

    public function test_a_line_with_a_zoom_user_is_preferred_over_one_without(): void
    {
        $this->useZoom();

        // Lower id, but unusable for a real send.
        $this->line([], ['phone_number' => '+61893752541']);
        $usable = $this->line([], ['phone_number' => '+61893752542', 'zoom_user_id' => 'zoom-user-2']);

        $result = $this->sender()->send('+61412345678', 'Your code is 123456', 'two_factor');

        $this->assertTrue($result->ok);
        $this->assertSame($usable->id, (int) $result->record->line_id);
    }

    public function test_the_log_transport_marks_a_system_message_sent(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'log');

        $this->line();

        $result = $this->sender()->send('+61412345678', 'Your code is 123456', 'portal_otp');

        $this->assertTrue($result->ok);

        $record = SmsSystemMessage::query()->first();

        $this->assertSame(SmsSystemMessage::STATUS_SENT, $record->status);
        $this->assertSame('log-' . $record->id, $record->provider_message_id);
        $this->assertSame('portal_otp', $record->purpose);

        // The dev transport's auto-reply is an inbox behaviour; a system send
        // never goes near it.
        $this->assertSame(0, SmsThread::query()->count());
    }

    public function test_with_no_transport_a_system_message_is_recorded_as_not_connected(): void
    {
        // The module default, restated for the reader.
        $this->app['config']->set('visns-packages.messaging.transport', 'null');

        $this->line();

        $result = $this->sender()->send('+61412345678', 'Your code is 123456', 'two_factor');

        $this->assertFalse($result->ok);
        $this->assertSame('SMS is not connected', $result->error);

        $record = SmsSystemMessage::query()->first();

        $this->assertSame(SmsSystemMessage::STATUS_NOT_CONNECTED, $record->status);
        $this->assertNull($record->sent_at);
        $this->assertNull($record->provider_message_id);
    }

    public function test_an_unreadable_number_fails_without_writing_a_row(): void
    {
        $this->useZoom();
        $this->line([], ['zoom_user_id' => 'zoom-user-9']);

        $result = $this->sender()->send('not a phone number', 'Your code is 123456', 'two_factor');

        $this->assertFalse($result->ok);
        $this->assertSame('unusable number', $result->error);
        $this->assertNull($result->record);
        $this->assertSame(0, SmsSystemMessage::query()->count());
        $this->assertSame([], FakeZoomSmsClient::$sends);
    }

    public function test_no_line_at_all_fails_rather_than_throwing(): void
    {
        $this->useZoom();

        $result = $this->sender()->send('+61412345678', 'Your code is 123456', 'two_factor');

        $this->assertFalse($result->ok);
        $this->assertSame('No SMS line is configured', $result->error);
        $this->assertSame(0, SmsSystemMessage::query()->count());
    }

    public function test_a_throwing_provider_is_recorded_as_failed(): void
    {
        $this->useZoom();
        $this->line([], ['zoom_user_id' => 'zoom-user-9']);

        FakeZoomSmsClient::$shouldThrow = true;

        $result = $this->sender()->send('+61412345678', 'Your code is 123456', 'two_factor');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('zoom is on fire', (string) $result->error);
        $this->assertSame(
            SmsSystemMessage::STATUS_FAILED,
            SmsSystemMessage::query()->first()->status
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The webhook guard
    |--------------------------------------------------------------------------
    */

    private function handle(string $event, array $object): string
    {
        return $this->app->make(SmsWebhookHandler::class)
            ->handle($event, ['payload' => ['object' => $object]]);
    }

    public function test_a_sent_event_for_a_system_message_updates_it_and_creates_no_thread(): void
    {
        $line = $this->line([], [
            'phone_number' => '+61893752549',
            'zoom_user_id' => 'zoom-user-9',
        ]);

        $record = SmsSystemMessage::create([
            'line_id' => $line->id,
            'purpose' => 'two_factor',
            'to_number' => '+61412345678',
            'status' => SmsSystemMessage::STATUS_QUEUED,
            'provider_message_id' => 'zoom-message-1',
        ]);

        // Exactly the payload that, for any other message, would have the
        // handler record "an outbound sent from the Zoom app" - body and all.
        $outcome = $this->handle('phone.sms_sent', [
            'session_id' => 'session-1',
            'message_id' => 'zoom-message-1',
            'message' => 'Your code is 123456',
            'sender' => ['phone_number' => '+61893752549'],
            'to_members' => [['phone_number' => '+61412345678']],
            'date_time' => '2026-08-21T02:00:00Z',
        ]);

        $this->assertSame('ok', $outcome);

        $record->refresh();

        $this->assertSame(SmsSystemMessage::STATUS_SENT, $record->status);
        $this->assertNotNull($record->sent_at);

        // The point of the guard.
        $this->assertSame(0, SmsThread::query()->count());
        $this->assertSame(0, SmsMessage::query()->count());
    }

    public function test_a_failed_event_for_a_system_message_marks_it_failed(): void
    {
        $line = $this->line([], ['phone_number' => '+61893752549']);

        $record = SmsSystemMessage::create([
            'line_id' => $line->id,
            'purpose' => 'two_factor',
            'to_number' => '+61412345678',
            'status' => SmsSystemMessage::STATUS_SENT,
            'provider_message_id' => 'zoom-message-1',
        ]);

        $outcome = $this->handle('phone.sms_sent_failed', [
            'message_id' => 'zoom-message-1',
            'sender' => ['phone_number' => '+61893752549'],
            'to_members' => [['phone_number' => '+61412345678']],
            'reason' => 'Carrier rejected the message.',
        ]);

        $this->assertSame('ok', $outcome);

        $record->refresh();

        $this->assertSame(SmsSystemMessage::STATUS_FAILED, $record->status);
        $this->assertSame('Carrier rejected the message.', $record->error);
        $this->assertSame(0, SmsThread::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | sendToNumber - the client-facing counterpart, which DOES thread
    |--------------------------------------------------------------------------
    */

    public function test_send_to_number_threads_the_message_into_the_inbox(): void
    {
        $this->useZoom();

        $member = $this->member();
        $line = $this->line([$member], [
            'phone_number' => '+61893752549',
            'zoom_user_id' => 'zoom-user-9',
        ]);

        $message = $this->app->make(SmsService::class)->sendToNumber(
            '0412 345 678',
            'Your review is booked for Thursday.',
            $member
        );

        $this->assertNotNull($message);
        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);
        $this->assertSame($member->id, (int) $message->user_id);

        $thread = SmsThread::query()->first();

        $this->assertNotNull($thread);
        $this->assertSame($line->id, (int) $thread->line_id);
        $this->assertSame('+61412345678', $thread->external_number);
        $this->assertSame('Your review is booked for Thursday.', $thread->last_message_preview);

        // A client-facing message is a normal message: it has a body, and it is
        // NOT in the system table.
        $this->assertSame(0, SmsSystemMessage::query()->count());
    }

    public function test_send_to_number_reuses_an_existing_thread(): void
    {
        $this->useZoom();

        $line = $this->line([], ['zoom_user_id' => 'zoom-user-9']);
        $existing = $this->thread($line, '+61412345678');

        $message = $this->app->make(SmsService::class)
            ->sendToNumber('+61412345678', 'Hello again.');

        $this->assertSame($existing->id, (int) $message->thread_id);
        $this->assertSame(1, SmsThread::query()->count());
    }

    public function test_send_to_number_returns_null_rather_than_throwing_when_it_cannot_send(): void
    {
        $this->useZoom();

        $service = $this->app->make(SmsService::class);

        // No line at all.
        $this->assertNull($service->sendToNumber('+61412345678', 'Hello.'));

        $this->line([], ['zoom_user_id' => 'zoom-user-9']);

        // A number nothing can be made of.
        $this->assertNull($service->sendToNumber('n/a', 'Hello.'));

        $this->assertSame(0, SmsThread::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The two-factor sender
    |--------------------------------------------------------------------------
    */

    private function twoFactorSender(): ZoomSmsTwoFactorCodeSender
    {
        return $this->app->make(ZoomSmsTwoFactorCodeSender::class);
    }

    public function test_the_two_factor_sender_texts_the_code_on_the_line(): void
    {
        $this->useZoom();

        $this->line([], [
            'phone_number' => '+61893752549',
            'zoom_user_id' => 'zoom-user-9',
        ]);

        $user = $this->member();
        $user->forceFill(['mobile' => '0412 345 678'])->save();

        $this->twoFactorSender()->send($user, '123456', 'Your verification code is: 123456');

        $this->assertCount(1, FakeZoomSmsClient::$sends);
        $this->assertSame('+61412345678', FakeZoomSmsClient::$sends[0]['to']);
        $this->assertSame('Your verification code is: 123456', FakeZoomSmsClient::$sends[0]['body']);

        $record = SmsSystemMessage::query()->first();

        $this->assertSame(SmsSystemMessage::PURPOSE_TWO_FACTOR, $record->purpose);
        $this->assertSame(SmsSystemMessage::STATUS_SENT, $record->status);

        // A login code is never a thread.
        $this->assertSame(0, SmsThread::query()->count());
    }

    public function test_the_two_factor_sender_throws_when_no_transport_is_connected(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'null');

        $this->line();

        $user = $this->member();
        $user->forceFill(['mobile' => '0412 345 678'])->save();

        // Throwing is the contract: TwoFactorCodeManager lets it propagate and
        // the login is refused rather than allowed through unchallenged.
        $this->expectException(\RuntimeException::class);

        $this->twoFactorSender()->send($user, '123456', 'Your verification code is: 123456');
    }

    public function test_the_two_factor_sender_throws_when_the_user_has_no_mobile(): void
    {
        $this->useZoom();

        $this->line([], ['zoom_user_id' => 'zoom-user-9']);

        $user = $this->member();

        try {
            $this->twoFactorSender()->send($user, '123456', 'Your verification code is: 123456');
            $this->fail('Expected the sender to refuse a user with no mobile.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no mobile number on file', $e->getMessage());
        }

        $this->assertSame([], FakeZoomSmsClient::$sends);
        $this->assertSame(0, SmsSystemMessage::query()->count());
    }

    public function test_the_two_factor_sender_reads_the_configured_mobile_column(): void
    {
        $this->useZoom();
        $this->app['config']->set('visns-packages.auth.two_factor.mobile_column', 'username');

        $this->line([], ['zoom_user_id' => 'zoom-user-9']);

        $user = $this->member();
        $user->forceFill(['username' => '0412 345 678'])->save();

        $this->twoFactorSender()->send($user, '123456', 'Your verification code is: 123456');

        $this->assertSame('+61412345678', FakeZoomSmsClient::$sends[0]['to']);
    }

    public function test_a_sent_receipt_that_beats_the_provider_id_still_never_threads_the_code(): void
    {
        $this->useZoom();
        $line = $this->line([], ['phone_number' => '+61893752549', 'zoom_user_id' => 'zoom-user-1']);

        // A row as SmsSystemSender leaves it between the create and the Zoom
        // response: queued, no provider id yet.
        $record = SmsSystemMessage::create([
            'line_id' => $line->id,
            'purpose' => SmsSystemMessage::PURPOSE_TWO_FACTOR,
            'to_number' => '+61412345678',
            'status' => SmsSystemMessage::STATUS_QUEUED,
        ]);

        $outcome = app(SmsWebhookHandler::class)->handle('phone.sms_sent', [
            'payload' => ['object' => [
                'message_id' => 'early-receipt-1',
                'sender' => ['phone_number' => '+61893752549'],
                'to_members' => [['phone_number' => '+61412345678']],
                'message' => 'Your code is 123456',
                'date_time' => '2026-08-21T06:00:00Z',
            ]],
        ]);

        $this->assertSame('ok', $outcome);
        $this->assertSame(SmsSystemMessage::STATUS_SENT, $record->fresh()->status);
        $this->assertSame('early-receipt-1', $record->fresh()->provider_message_id);
        $this->assertSame(0, SmsThread::query()->count());
        $this->assertSame(0, SmsMessage::query()->count());
    }
}
