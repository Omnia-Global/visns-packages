<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;

/**
 * A ZoomSmsClient that records what it was asked to send and answers with
 * whatever the test set up - so the Zoom transport can be exercised without a
 * Zoom account, and with certainty that nothing reached a live tenant.
 *
 * Bound in the container for ZoomSmsClient::class, which is the seam the real
 * transport resolves through.
 */
class FakeZoomSmsClient extends ZoomSmsClient
{
    /** @var array<int, array{from: string, to: string, body: string, request: array}> */
    public static array $sends = [];

    /** @var array{success: bool, http_code: int, data: mixed} */
    public static array $response = [
        'success' => true,
        'http_code' => 201,
        'data' => ['message_id' => 'zoom-message-1'],
    ];

    /** @var array{success: bool, users: array, error?: string} */
    public static array $users = ['success' => true, 'users' => []];

    public static bool $shouldThrow = false;

    public static function reset(): void
    {
        self::$sends = [];
        self::$response = [
            'success' => true,
            'http_code' => 201,
            'data' => ['message_id' => 'zoom-message-1'],
        ];
        self::$users = ['success' => true, 'users' => []];
        self::$shouldThrow = false;
    }

    public function sendSms(string $from, string $to, string $body): array
    {
        if (self::$shouldThrow) {
            throw new \RuntimeException('zoom is on fire');
        }

        self::$sends[] = [
            'from' => $from,
            'to' => $to,
            'body' => $body,
            // The real body builder, not a copy of it - so the test pins the
            // shape that would actually go to Zoom.
            'request' => $this->sendBody($from, $to, $body),
        ];

        return self::$response;
    }

    public function listPhoneUsers(): array
    {
        if (self::$shouldThrow) {
            throw new \RuntimeException('zoom is on fire');
        }

        return self::$users;
    }
}
