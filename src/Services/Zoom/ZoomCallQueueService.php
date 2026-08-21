<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

/**
 * Zoom Phone call queue administration, for Settings -> Call Queues.
 *
 * Read side: the queue list and per-queue detail, so the settings page always
 * shows what actually exists in the Zoom account rather than a local copy.
 *
 * Write side: the call queue pickup code policy. What that endpoint accepts is
 * undocumented, so it was mapped empirically against a tenant's Test Call
 * Queue (ext 303) — findings verbatim:
 *
 *   WORKING VERB + PAYLOAD
 *     PATCH /phone/call_queues/{callQueueId}/policies
 *     {"call_queue_pickup_code": {"enable": true, "pickup_code": "6439"}}
 *     -> 204 No Content
 *
 *   PROVEN
 *     - This is the endpoint that really writes: PATCHing an unrelated policy
 *       through it (`auto_answer_call_queue_calls.enable` false -> true ->
 *       false) round-tripped through `GET /phone/call_queues/{id}/policies`.
 *       The same body sent to `PATCH /phone/call_queues/{id}` (wrapped in
 *       `policy`) also answers 204 but changes nothing.
 *     - `call_queue_pickup_code.enable` is genuinely part of Zoom's schema: a
 *       type mismatch there (`{"enable": "maybe"}`) is rejected with
 *       400 {"code":300,"message":"Request Body should be a valid JSON object."}.
 *
 *   NOT SUPPORTED BY ZOOM TODAY (documented so nobody re-runs this)
 *     - The code VALUE is silently dropped. Unknown members of the policy object
 *       return 204 without taking effect, and every candidate name behaves that
 *       way — pickup_code, code, pickupCode, queue_pickup_code, pick_up_code,
 *       call_pickup_code, pickup_codes, value, codes, digits, pin, number,
 *       password, prefix, settings, pickup_code_value, code_value, ... — as do
 *       deliberately invalid codes (0439, 1111, 1234, 12345, "abcd"), which a
 *       parsed field would have rejected.
 *     - `GET /phone/call_queues/{id}/policies` returns only
 *       `"call_queue_pickup_code":{"enable":true}` — the code is never readable.
 *     - `/phone/call_queues/{id}/policies/{policyType}` is a dead end: every
 *       slug, including valid policy names, answers
 *       404 {"code":13001,"message":"Invalid value for parameter policyType."},
 *       and its GET 405 ("Method 'GET' is not supported", allow: PATCH, POST,
 *       DELETE) is generic — a nonsense slug 405s identically.
 *
 * So the application owns the code (the DB is what the pop dials) and pushes the
 * policy to Zoom on every save: the enable half lands, the digits still have to
 * be typed into the Zoom admin UI. The payload carries `pickup_code` anyway so
 * the day Zoom accepts it, this starts working with no code change.
 */
class ZoomCallQueueService extends ZoomApiClient
{
    /** Zoom's maximum; an account has a handful of queues, so one page is plenty. */
    private const PAGE_SIZE = 100;

    /** Runaway guard for the pagination loop — 30 pages is 3,000 queues. */
    private const MAX_PAGES = 30;

    /**
     * Every call queue in the account.
     *
     * @return array{success: bool, queues: array<int, array<string, mixed>>, error?: string}
     */
    public function listQueues(): array
    {
        $queues = [];
        $token = '';
        $pages = 0;

        do {
            $result = $this->request(
                'GET',
                '/phone/call_queues?page_size=' . self::PAGE_SIZE .
                    ($token !== '' ? '&next_page_token=' . urlencode($token) : '')
            );

            if (! $result['success']) {
                return [
                    'success' => false,
                    'queues' => [],
                    'error' => $this->errorMessage($result, 'Failed to list Zoom call queues'),
                ];
            }

            foreach ((array) ($result['data']['call_queues'] ?? []) as $queue) {
                if (is_array($queue)) {
                    $queues[] = $queue;
                }
            }

            $token = trim((string) ($result['data']['next_page_token'] ?? ''));
        } while ($token !== '' && ++$pages < self::MAX_PAGES);

        return ['success' => true, 'queues' => $queues];
    }

    /**
     * One call queue, including its members.
     */
    public function getQueue(string $queueId): array
    {
        return $this->request('GET', '/phone/call_queues/' . rawurlencode($queueId));
    }

    /**
     * Push a pickup code to Zoom.
     *
     * See the class docblock: Zoom applies the `enable` half and drops the code
     * itself, so a success here means "the pickup code policy is on for this
     * queue", not "Zoom now dials these digits".
     *
     * @param string $code Bare digits, no leading `*`.
     * @return array{success: bool, http_code?: int, error?: string}
     */
    public function setPickupCode(string $queueId, string $code): array
    {
        $result = $this->patchPickupCodePolicy($queueId, [
            'enable' => true,
            'pickup_code' => ltrim(trim($code), '*'),
        ]);

        if (! $result['success']) {
            return [
                'success' => false,
                'http_code' => $result['http_code'],
                'error' => $this->errorMessage(
                    $result,
                    'Zoom rejected the pickup code for this queue'
                ),
            ];
        }

        return ['success' => true, 'http_code' => $result['http_code']];
    }

    /**
     * Turn the pickup code policy off — what a queue whose code has been cleared
     * locally should look like in Zoom.
     *
     * @return array{success: bool, http_code?: int, error?: string}
     */
    public function disablePickupCode(string $queueId): array
    {
        $result = $this->patchPickupCodePolicy($queueId, ['enable' => false]);

        if (! $result['success']) {
            return [
                'success' => false,
                'http_code' => $result['http_code'],
                'error' => $this->errorMessage(
                    $result,
                    'Zoom rejected the pickup code change for this queue'
                ),
            ];
        }

        return ['success' => true, 'http_code' => $result['http_code']];
    }

    /**
     * The policies read, which is how the enabled state can be confirmed after a
     * write (the code itself is never returned).
     */
    public function getPolicies(string $queueId): array
    {
        return $this->request('GET', '/phone/call_queues/' . rawurlencode($queueId) . '/policies');
    }

    private function patchPickupCodePolicy(string $queueId, array $policy): array
    {
        return $this->request(
            'PATCH',
            '/phone/call_queues/' . rawurlencode($queueId) . '/policies',
            ['call_queue_pickup_code' => $policy]
        );
    }

    /** Zoom's own wording where it gave one, so the settings page can show it. */
    private function errorMessage(array $result, string $fallback): string
    {
        $message = trim((string) ($result['data']['message'] ?? ''));

        return $message !== '' ? $message : $fallback;
    }
}
