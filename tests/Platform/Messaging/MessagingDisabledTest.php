<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The module ships disabled, and a disabled module has no endpoints at all.
 *
 * Its own test case (not MessagingTestCase) precisely because it must run with
 * `messaging.enabled` left at its shipped default.
 */
class MessagingDisabledTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // The call queue owns the webhook URI, and routes are registered at
        // boot - so this has to be set here rather than in the test body.
        // `messaging.enabled` is deliberately left at its shipped default.
        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set('visns-packages.call_queue.webhook_secret_token', 'secret');
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

    public function test_no_messaging_route_exists_while_the_module_is_off(): void
    {
        foreach ([
            '/ajax/sms/status',
            '/ajax/sms/lines',
            '/ajax/sms/unread',
            '/ajax/sms/threads',
            '/ajax/sms/templates',
            '/ajax/sms/settings/lines',
        ] as $uri) {
            $this->getJson($uri)->assertStatus(404);
        }
    }

    public function test_an_sms_webhook_event_is_acknowledged_and_dropped(): void
    {
        // With messaging off, an SMS subscription that somebody ticked in the
        // Zoom app must not become an error - Zoom disables endpoints that
        // error.
        $body = ['event' => 'phone.sms_received', 'payload' => ['object' => []]];
        $json = json_encode($body);
        $timestamp = (string) time();

        $this->call(
            'POST',
            '/api/zoom/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ZM_SIGNATURE' => 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $json, 'secret'),
                'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            ],
            $json
        )->assertOk()->assertJson(['status' => 'ignored']);
    }
}
