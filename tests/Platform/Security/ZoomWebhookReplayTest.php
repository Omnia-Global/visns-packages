<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Middleware\VerifyZoomWebhookSignature;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.4: a correctly signed Zoom delivery sent a second time inside the
 * timestamp window is acknowledged with 200 and not processed again.
 */
class ZoomWebhookReplayTest extends TestCase
{
    private const SECRET = 'zoom-signing-secret';

    private int $processed = 0;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.call_queue.webhook_secret_token', self::SECRET);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/test-zoom-webhook', function () {
            $this->processed++;

            return response()->json(['status' => 'ok']);
        })->middleware(VerifyZoomWebhookSignature::class);
    }

    private function deliver(string $json, ?string $timestamp = null)
    {
        $timestamp ??= (string) time();
        $signature = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $json, self::SECRET);

        return $this->call('POST', '/test-zoom-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZM_SIGNATURE' => $signature,
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
        ], $json);
    }

    #[Test]
    public function a_replayed_delivery_is_acknowledged_and_not_processed_twice(): void
    {
        $json = json_encode(['event' => 'phone.callee_ringing', 'event_ts' => 1726000000000]);
        $timestamp = (string) time();

        $this->deliver($json, $timestamp)->assertOk()->assertJsonPath('status', 'ok');
        $this->deliver($json, $timestamp)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->deliver($json, $timestamp)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, $this->processed);
    }

    #[Test]
    public function distinct_deliveries_are_each_processed(): void
    {
        $this->deliver(json_encode(['event' => 'phone.callee_ringing', 'event_ts' => 1]))->assertOk();
        $this->deliver(json_encode(['event' => 'phone.callee_ringing', 'event_ts' => 2]))->assertOk();

        $this->assertSame(2, $this->processed);
    }

    #[Test]
    public function a_bad_signature_is_still_401_and_not_remembered(): void
    {
        $json = json_encode(['event' => 'x', 'event_ts' => 3]);
        $timestamp = (string) time();

        $this->call('POST', '/test-zoom-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZM_SIGNATURE' => 'v0=forged',
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
        ], $json)->assertStatus(401);

        $this->deliver($json, $timestamp)->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame(1, $this->processed);
    }
}
