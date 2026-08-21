<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Illuminate\Support\Arr;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The Zoom Phone SMS endpoints, and the phone-user list the settings page needs.
 *
 * Split out of ZoomSmsTransport so there is one seam to substitute: the
 * transport asks the container for THIS class, so an application with its own
 * Zoom client - or a test that must be certain no send reaches the live tenant -
 * binds a replacement for it and nothing else changes.
 *
 * Credentials come from `messaging.zoom.api` when set, and otherwise from the
 * call queue's `call_queue.api`, because in every deployment so far both
 * features live in the same Zoom Server-to-Server app. Setting `messaging.zoom.api`
 * is for the case where they do not.
 *
 * NOTHING HERE HAS BEEN RUN AGAINST A LIVE SMS-ENABLED ACCOUNT. The practice is
 * still waiting on an SMS-capable number, so the request and response shapes
 * below follow Zoom's published "Send SMS" / "List phone users" references and
 * are the one place to adjust when the live account confirms field names. Every
 * response read is defensive for that reason.
 */
class ZoomSmsClient extends ZoomApiClient
{
    /** Zoom's maximum page size for the phone user list. */
    private const PAGE_SIZE = 100;

    /** Runaway guard for the pagination loop. */
    private const MAX_PAGES = 20;

    public function __construct()
    {
        parent::__construct();

        // A messaging-specific Zoom app, when one is configured. Read key by
        // key rather than swapped wholesale so a partial override (a different
        // client secret, the same account) behaves the way it reads.
        $api = ModuleConfig::get('messaging.zoom.api');

        if (! is_array($api)) {
            return;
        }

        $this->accountId = (string) ($api['account_id'] ?? $this->accountId);
        $this->clientId = (string) ($api['client_id'] ?? $this->clientId);
        $this->clientSecret = (string) ($api['client_secret'] ?? $this->clientSecret);
        $this->baseUrl = (string) ($api['base_url'] ?? $this->baseUrl);
        $this->tokenUrl = (string) ($api['token_url'] ?? $this->tokenUrl);
    }

    /**
     * Send one SMS.
     *
     * @param  string  $from  The line's number, E.164.
     * @param  string  $to    The recipient, E.164.
     * @return array{success: bool, http_code: int, data: mixed}
     */
    public function sendSms(string $from, string $to, string $body): array
    {
        return $this->request('POST', $this->sendPath(), $this->sendBody($from, $to, $body));
    }

    /**
     * The request body for Zoom's "Send SMS" endpoint.
     *
     * Kept in its own method with nothing else in it so that the day the live
     * account is connected and a field name turns out to be wrong, the fix is
     * one function and the tests that pin this shape say so plainly.
     *
     * Per Zoom's reference, POST /phone/sms/messages takes:
     *
     *   {
     *     "sender":     {"phone_number": "+61812345678"},
     *     "to_members": [{"phone_number": "+61412345678"}],
     *     "message":    "..."
     *   }
     *
     * `to_members` is a list because Zoom models an SMS as a session with
     * members; this module always sends to exactly one, because a thread is one
     * conversation with one number and a group send would have nowhere to land.
     *
     * @return array<string, mixed>
     */
    public function sendBody(string $from, string $to, string $body): array
    {
        return [
            'sender' => ['phone_number' => $from],
            'to_members' => [['phone_number' => $to]],
            'message' => $body,
        ];
    }

    /**
     * Every Zoom Phone user, with the numbers assigned to them.
     *
     * Used by the settings page (to offer real numbers rather than a free-text
     * box) and by `sms:sync-lines`. Failure is reported, never thrown: the
     * settings page has to keep working when Zoom is down.
     *
     * @return array{success: bool, users: array<int, array<string, mixed>>, error?: string}
     */
    public function listPhoneUsers(): array
    {
        $users = [];
        $token = '';
        $pages = 0;

        do {
            $result = $this->request(
                'GET',
                '/phone/users?page_size=' . self::PAGE_SIZE .
                    ($token !== '' ? '&next_page_token=' . urlencode($token) : '')
            );

            if (! $result['success']) {
                return [
                    'success' => false,
                    'users' => [],
                    'error' => $this->errorMessage($result),
                ];
            }

            foreach ((array) Arr::get($result, 'data.users', []) as $user) {
                $users[] = [
                    'id' => (string) Arr::get($user, 'id', ''),
                    'email' => (string) Arr::get($user, 'email', ''),
                    'display_name' => trim(
                        (string) (Arr::get($user, 'name')
                            ?? trim(
                                (string) Arr::get($user, 'first_name', '') . ' '
                                . (string) Arr::get($user, 'last_name', '')
                            ))
                    ),
                    'phone_numbers' => array_values(array_filter(array_map(
                        static fn ($number) => trim((string) Arr::get($number, 'number', '')),
                        (array) Arr::get($user, 'phone_numbers', [])
                    ))),
                ];
            }

            $token = (string) Arr::get($result, 'data.next_page_token', '');
            $pages++;
        } while ($token !== '' && $pages < self::MAX_PAGES);

        return ['success' => true, 'users' => $users];
    }

    /**
     * Zoom's own error text, when it gave one.
     *
     * @param  array{success: bool, http_code: int, data: mixed}  $result
     */
    public function errorMessage(array $result): string
    {
        $message = Arr::get($result, 'data.message');

        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return 'Zoom refused the request (HTTP ' . (int) ($result['http_code'] ?? 0) . ').';
    }

    private function sendPath(): string
    {
        $path = (string) ModuleConfig::get('messaging.zoom.send_path', '/phone/sms/messages');

        return $path === '' ? '/phone/sms/messages' : $path;
    }
}
