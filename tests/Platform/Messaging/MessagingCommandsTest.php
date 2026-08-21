<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\SmsReceived;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;

/**
 * The three console commands.
 */
class MessagingCommandsTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();
    }

    /*
    |--------------------------------------------------------------------------
    | sms:simulate-inbound
    |--------------------------------------------------------------------------
    */

    public function test_simulate_inbound_records_a_message_through_the_normal_path(): void
    {
        Event::fake([SmsReceived::class]);

        $line = $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:simulate-inbound', [
            'line' => (string) $line->id,
            'from' => '0412 345 678',
            'body' => 'Running late',
        ])->assertSuccessful();

        $thread = SmsThread::first();

        $this->assertSame('+61412345678', $thread->external_number);
        $this->assertSame('Running late', $thread->last_message_preview);
        $this->assertSame('in', SmsMessage::first()->direction);

        Event::assertDispatched(SmsReceived::class);
    }

    public function test_simulate_inbound_accepts_the_line_by_number(): void
    {
        $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:simulate-inbound', [
            'line' => '08 9375 2549',
            'from' => '0412345678',
            'body' => 'Hello',
        ])->assertSuccessful();

        $this->assertSame(1, SmsMessage::count());
    }

    public function test_simulate_inbound_refuses_an_unknown_line(): void
    {
        $this->artisan('sms:simulate-inbound', [
            'line' => '999',
            'from' => '0412345678',
            'body' => 'Hello',
        ])->assertFailed();
    }

    public function test_simulate_inbound_refuses_a_number_it_cannot_read(): void
    {
        $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:simulate-inbound', [
            'line' => '08 9375 2549',
            'from' => '304',
            'body' => 'Hello',
        ])->assertFailed();

        $this->assertSame(0, SmsMessage::count());
    }

    public function test_simulate_inbound_refuses_to_run_while_zoom_is_connected(): void
    {
        // On a connected system this writes a message into a client's
        // conversation that the client never sent.
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');

        $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:simulate-inbound', [
            'line' => '08 9375 2549',
            'from' => '0412345678',
            'body' => 'Hello',
        ])->assertFailed();

        $this->assertSame(0, SmsMessage::count());
    }

    /*
    |--------------------------------------------------------------------------
    | sms:prune
    |--------------------------------------------------------------------------
    */

    public function test_prune_archives_quiet_threads_and_deletes_nothing(): void
    {
        $line = $this->line();

        $quiet = $this->thread($line, '+61400000001', [
            'last_message_at' => now()->subDays(200),
        ]);
        $busy = $this->thread($line, '+61400000002', ['last_message_at' => now()]);

        SmsMessage::create([
            'thread_id' => $quiet->id,
            'direction' => 'in',
            'body' => 'Ancient history',
            'status' => 'received',
            'received_at' => now()->subDays(200),
        ]);

        $this->artisan('sms:prune')->assertSuccessful();

        $this->assertNotNull($quiet->fresh()->archived_at);
        $this->assertNull($busy->fresh()->archived_at);

        // The whole point: SMS to and from clients is a record an AFSL licensee
        // has to be able to produce years later.
        $this->assertSame(1, SmsMessage::count());
    }

    public function test_prune_can_report_without_changing_anything(): void
    {
        $line = $this->line();
        $quiet = $this->thread($line, '+61400000001', [
            'last_message_at' => now()->subDays(200),
        ]);

        $this->artisan('sms:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($quiet->fresh()->archived_at);
    }

    public function test_prune_refuses_a_zero_day_window(): void
    {
        // "--days=0" reads like "archive everything" to whoever typed it.
        $this->artisan('sms:prune', ['--days' => 0])->assertFailed();
    }

    public function test_prune_judges_a_thread_that_never_had_a_message_by_its_age(): void
    {
        $line = $this->line();

        $stale = $this->thread($line, '+61400000001');
        $stale->forceFill(['created_at' => now()->subDays(200)])->save();

        $fresh = $this->thread($line, '+61400000002');

        $this->artisan('sms:prune')->assertSuccessful();

        $this->assertNotNull($stale->fresh()->archived_at);
        $this->assertNull($fresh->fresh()->archived_at);
    }

    /*
    |--------------------------------------------------------------------------
    | sms:sync-lines
    |--------------------------------------------------------------------------
    */

    public function test_sync_lines_is_a_no_op_without_the_zoom_transport(): void
    {
        $line = $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:sync-lines')->assertSuccessful();

        $this->assertNull($line->fresh()->zoom_user_id);
    }

    public function test_sync_lines_stamps_the_zoom_user_that_holds_the_number(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$users = [
            'success' => true,
            'users' => [[
                'id' => 'zoom-user-1',
                'email' => 'reception@example.test',
                'display_name' => 'Reception',
                // Zoom's spelling of the number, not ours - matched on the
                // canonical form.
                'phone_numbers' => ['08 9375 2549'],
            ]],
        ];

        $line = $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:sync-lines')->assertSuccessful();

        $line->refresh();

        $this->assertSame('zoom-user-1', $line->zoom_user_id);
        $this->assertSame('reception@example.test', $line->zoom_user_email);
    }

    public function test_sync_lines_leaves_a_number_zoom_does_not_hold_alone(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$users = ['success' => true, 'users' => []];

        $line = $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:sync-lines')->assertSuccessful();

        $this->assertNull($line->fresh()->zoom_user_id);
    }

    public function test_sync_lines_reports_zoom_being_unreachable(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$shouldThrow = true;

        $this->artisan('sms:sync-lines')->assertFailed();
    }

    public function test_sync_lines_can_report_without_changing_anything(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$users = [
            'success' => true,
            'users' => [[
                'id' => 'zoom-user-1',
                'email' => 'reception@example.test',
                'display_name' => 'Reception',
                'phone_numbers' => ['+61893752549'],
            ]],
        ];

        $line = $this->line([], ['phone_number' => '+61893752549']);

        $this->artisan('sms:sync-lines', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($line->fresh()->zoom_user_id);
        $this->assertSame(1, SmsLine::count());
    }
}
