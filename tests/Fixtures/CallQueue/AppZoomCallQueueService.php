<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue;

/**
 * Stands in for an application's own Zoom client — the case
 * `call_queue.zoom_service` exists for.
 *
 * Deliberately NOT extending the package's service: an application that already
 * has a Zoom client has its own base class, its own credentials and its own
 * transport, and the whole point of the config key is that it does not have to
 * inherit from anything here. It only has to satisfy the public contract the
 * settings page calls.
 */
class AppZoomCallQueueService
{
    /** @var array<int, array<int, mixed>> */
    public array $pushed = [];

    public bool $reachable = true;

    public function listQueues(): array
    {
        if (! $this->reachable) {
            return [
                'success' => false,
                'queues' => [],
                'error' => 'app client could not reach Zoom',
            ];
        }

        return [
            'success' => true,
            'queues' => [
                [
                    'id' => 'app-queue-1',
                    'name' => 'Application Reception',
                    'extension_number' => 404,
                    'status' => 'active',
                    'phone_numbers' => [['number' => '+61399999999']],
                ],
            ],
        ];
    }

    public function setPickupCode(string $queueId, string $code): array
    {
        $this->pushed[] = ['set', $queueId, $code];

        return ['success' => true, 'http_code' => 204];
    }

    public function disablePickupCode(string $queueId): array
    {
        $this->pushed[] = ['disable', $queueId];

        return ['success' => true, 'http_code' => 204];
    }

    public function getQueue(string $queueId): array
    {
        return ['success' => true, 'http_code' => 200, 'data' => []];
    }

    public function getPolicies(string $queueId): array
    {
        return ['success' => true, 'http_code' => 200, 'data' => []];
    }
}
