<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\OAuthConnection;
use Visnsstudio\VisnsPackages\Services\OAuthManager;
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
 * ## Why sending has a second token
 *
 * Zoom checks `POST /phone/sms/messages` against the IDENTITY of the token, not
 * against the account: the token's own user must BE `sender.user_id`. A
 * Server-to-Server token acts as the account owner, so a send from the owner's
 * own number succeeds and a send from any other user's number is refused with
 *
 *     code 7639 — "You do not have permission to send SMS for this user"
 *
 * which is why the shared "Omnia Global" line could never send while the owner
 * could. There is no account-level SMS scope that lifts this; the fix is a
 * USER-managed OAuth app, authorised once while signed in to Zoom as the line's
 * user, whose token IS that user.
 *
 * So: when an active `zoom_sms` OAuth connection exists, **the send path and
 * only the send path** uses its bearer token. `listPhoneUsers()`, the call
 * queue and the webhooks keep the Server-to-Server token, because those are
 * account-wide reads that a single user's token cannot answer. With no
 * connection, sending behaves exactly as it did before this existed.
 *
 * ## Sender substitution
 *
 * The connection is made by a human clicking Connect, so nothing guarantees
 * they were signed in to Zoom as the user that holds the line's number. When
 * `SmsLine::zoom_user_id` and the token's own user id differ, the send goes out
 * with the TOKEN's user id and logs that it substituted — because that is the
 * only id Zoom will accept from this token, and failing the send instead would
 * trade a message that arrives from a slightly unexpected number for no message
 * at all. The probe on Settings -> Integrations names the connected user for
 * exactly this reason: connecting as the wrong person is silent otherwise.
 *
 * Exercised against the live tenant on 21 Aug 2026 with a Server-to-Server
 * OAuth app: `POST /phone/sms/messages` answered 201 with
 * `{session_id, message_id, date_time}` when the sender carried both the Zoom
 * user id and the number - as the account owner. Every response read is still
 * defensive: Zoom's shapes drift and a wrong guess must degrade to a logged
 * failure, not an exception.
 */
class ZoomSmsClient extends ZoomApiClient
{
    /** Zoom's maximum page size for the phone user list. */
    private const PAGE_SIZE = 100;

    /** Runaway guard for the pagination loop. */
    private const MAX_PAGES = 20;

    /**
     * The integration whose user-level token sends.
     *
     * Deliberately NOT `zoom`: that one is the Server-to-Server app and stays
     * an api_key integration. Two Zoom apps, two cards, two jobs.
     */
    public const USER_PROVIDER = 'zoom_sms';

    /**
     * Refresh this many seconds before Zoom says the token dies.
     *
     * A send that starts inside the window would otherwise 401 halfway and pay
     * for the retry.
     */
    private const REFRESH_SKEW_SECONDS = 120;

    /** Set once a refresh has been attempted, so one send cannot loop. */
    private bool $refreshed = false;

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
     * @param  string       $from    The line's number, E.164.
     * @param  string       $to      The recipient, E.164.
     * @param  string|null  $userId  The Zoom user the line's number is assigned to
     *                               (SmsLine::zoom_user_id). Zoom rejects a send
     *                               without it ("User does not exist due to
     *                               missing required params").
     * @return array{success: bool, http_code: int, data: mixed}
     */
    public function sendSms(string $from, string $to, string $body, ?string $userId = null): array
    {
        $connection = $this->userConnection();

        // No user-level connection: the original Server-to-Server path,
        // unchanged. Every deployment that has not connected the SMS app keeps
        // behaving exactly as it did.
        if ($connection === null) {
            return $this->request('POST', $this->sendPath(), $this->sendBody($from, $to, $body, $userId));
        }

        $token = $this->userAccessToken($connection);

        if ($token === null) {
            // The connection exists but cannot produce a token (refresh
            // refused, or consent revoked at Zoom). Falling back to the S2S
            // token would send from the wrong identity and be refused with
            // 7639 anyway, so say what is actually wrong.
            Log::warning('sms.zoom user token unusable; not falling back to the account token', [
                'provider' => self::USER_PROVIDER,
                'connection_id' => $connection->id,
            ]);

            return [
                'success' => false,
                'http_code' => 401,
                'data' => [
                    'message' => 'The Zoom SMS connection has expired. Reconnect it under Settings > Integrations.',
                ],
            ];
        }

        return $this->sendAsUser(
            $token,
            $this->sendBody($from, $to, $body, $this->senderUserId($connection, $token, $userId))
        );
    }

    /**
     * The user id to put in `sender.user_id`.
     *
     * The token's own user, whenever it is known - see the class docblock. The
     * line's id is kept when the token's user cannot be read, so a temporarily
     * unreachable `/users/me` degrades to the previous behaviour rather than to
     * a send with no sender at all.
     */
    private function senderUserId(OAuthConnection $connection, string $token, ?string $lineUserId): ?string
    {
        $tokenUser = $this->tokenUser($connection, $token);
        $tokenUserId = trim((string) ($tokenUser['id'] ?? ''));

        if ($tokenUserId === '') {
            return $lineUserId;
        }

        $lineUserId = $lineUserId === null ? '' : trim($lineUserId);

        if ($lineUserId !== '' && $lineUserId !== $tokenUserId) {
            Log::info('sms.zoom sender substituted with the connected Zoom user', [
                'line_zoom_user_id' => $lineUserId,
                'token_zoom_user_id' => $tokenUserId,
                'token_zoom_email' => $tokenUser['email'] ?? null,
                // Said plainly, because the fix is a human action: reconnect
                // while signed in to Zoom as the line's own user.
                'reason' => 'Zoom only accepts sender.user_id equal to the token owner (error 7639).',
            ]);
        }

        return $tokenUserId;
    }

    /**
     * The request body for Zoom's "Send SMS" endpoint.
     *
     * Kept in its own method with nothing else in it so that the day the live
     * account is connected and a field name turns out to be wrong, the fix is
     * one function and the tests that pin this shape say so plainly.
     *
     * Confirmed against the live tenant on 21 Aug 2026: POST /phone/sms/messages
     * takes
     *
     *   {
     *     "sender":     {"user_id": "<zoom user id>", "phone_number": "+61812345678"},
     *     "to_members": [{"phone_number": "+61412345678"}],
     *     "message":    "..."
     *   }
     *
     * `sender.user_id` is the Zoom user the number is assigned to - it is what
     * `sms:sync-lines` (or the settings page) stamps on SmsLine::zoom_user_id.
     * It is left out of the body when unknown so Zoom's own error says what is
     * missing, rather than an empty string being sent as an id.
     *
     * `to_members` is a list because Zoom models an SMS as a session with
     * members; this module always sends to exactly one, because a thread is one
     * conversation with one number and a group send would have nowhere to land.
     *
     * @return array<string, mixed>
     */
    public function sendBody(string $from, string $to, string $body, ?string $userId = null): array
    {
        $sender = ['phone_number' => $from];

        if ($userId !== null && trim($userId) !== '') {
            $sender = ['user_id' => trim($userId)] + $sender;
        }

        return [
            'sender' => $sender,
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

    /**
     * Who the SMS app is connected as, for Settings -> Integrations.
     *
     * The whole point of the probe: an admin who connected while signed in to
     * Zoom as themselves gets a working-looking integration that sends from the
     * wrong number, and nothing else in the UI would ever say so.
     *
     * @param  bool  $fresh  Ignore the cached identity and ask Zoom again.
     * @return array{success: bool, id: ?string, email: ?string, message: string}
     */
    public function userTokenIdentity(bool $fresh = false): array
    {
        $connection = $this->userConnection();

        if ($connection === null) {
            return [
                'success' => false,
                'id' => null,
                'email' => null,
                'message' => 'Not connected. Press Connect while signed in to Zoom as the user who holds the SMS number.',
            ];
        }

        $token = $this->userAccessToken($connection);

        if ($token === null) {
            return [
                'success' => false,
                'id' => null,
                'email' => null,
                'message' => 'The stored authorisation could not be refreshed. Press Connect to authorise again.',
            ];
        }

        $user = $this->tokenUser($connection, $token, $fresh);

        if (($user['id'] ?? '') === '') {
            return [
                'success' => false,
                'id' => null,
                'email' => null,
                'message' => 'Zoom accepted the token but would not say who it belongs to. Check the user:read:user scope is granted.',
            ];
        }

        $email = (string) ($user['email'] ?? '');

        return [
            'success' => true,
            'id' => (string) $user['id'],
            'email' => $email !== '' ? $email : null,
            'message' => 'Connected as ' . ($email !== '' ? $email : $user['id'])
                . ' - every SMS will go out as this Zoom user. If that is not the user who holds the line\'s number,'
                . ' sign out of Zoom, sign in as that user, and press Connect again.',
        ];
    }

    /** True when a user-level token is in play, i.e. sends bypass the S2S app. */
    public function hasUserConnection(): bool
    {
        return $this->userConnection() !== null;
    }

    /**
     * The active `zoom_sms` connection, or null.
     *
     * Never throws. This is called on the send path of an app whose
     * `oauth_connections` table may not have been migrated yet, and a missing
     * table must degrade to "no user connection" rather than take the send
     * down with it.
     */
    private function userConnection(): ?OAuthConnection
    {
        try {
            return OAuthConnection::getActiveConnection(self::USER_PROVIDER);
        } catch (\Throwable $e) {
            Log::warning('sms.zoom could not read the user OAuth connection', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A usable bearer token for the connection, refreshing first if it is due.
     *
     * The framework has a refresh, but only behind OAuthManager's PROTECTED
     * `ensureValidConnection()` - reachable from `testConnection()` and
     * `syncData()` and from nothing else. Rather than widen that, the same two
     * public pieces it is built from are used here: the provider's
     * `refreshToken()` (which knows the token URL and the client auth) and the
     * model's `updateTokens()` (which persists the result).
     */
    private function userAccessToken(OAuthConnection $connection): ?string
    {
        $due = $connection->expires_at === null
            ? false
            : $connection->expires_at->lte(now()->addSeconds(self::REFRESH_SKEW_SECONDS));

        if ($due && ! $this->refreshUserToken($connection)) {
            return null;
        }

        $token = (string) ($connection->access_token ?? '');

        return $token !== '' ? $token : null;
    }

    /**
     * Swap the refresh token for a new pair, and store BOTH.
     *
     * Zoom rotates the refresh token on every refresh and invalidates the old
     * one immediately, so persisting only the access token would work once and
     * then lock the integration out until somebody re-consented.
     * `OAuthConnection::updateTokens()` writes both.
     */
    private function refreshUserToken(OAuthConnection $connection): bool
    {
        $this->refreshed = true;

        if (! $connection->refresh_token) {
            return false;
        }

        try {
            $provider = app(OAuthManager::class)->getProvider(self::USER_PROVIDER);

            if ($provider === null) {
                Log::warning('sms.zoom has a connection but no registered provider', [
                    'provider' => self::USER_PROVIDER,
                ]);

                return false;
            }

            $tokens = $provider->refreshToken((string) $connection->refresh_token);
        } catch (\Throwable $e) {
            Log::error('sms.zoom token refresh threw', ['error' => $e->getMessage()]);

            return false;
        }

        if (! is_array($tokens) || empty($tokens['access_token'])) {
            Log::error('sms.zoom token refresh was refused', [
                'connection_id' => $connection->id,
            ]);

            return false;
        }

        $connection->updateTokens($tokens);
        $connection->refresh();

        Log::info('sms.zoom user token refreshed', [
            'connection_id' => $connection->id,
            'expires_at' => $connection->expires_at?->toIso8601String(),
        ]);

        return true;
    }

    /**
     * The Zoom user the token belongs to, cached on the connection.
     *
     * `GET /users/me` on every send would double the outbound calls for an
     * answer that cannot change while the connection lives, so the id and email
     * are written into the connection's metadata the first time and read from
     * there afterwards. A reconnect makes a new row, so the cache cannot
     * outlive the token it describes.
     *
     * @return array{id: string, email: string}
     */
    private function tokenUser(OAuthConnection $connection, string $token, bool $fresh = false): array
    {
        $cached = (array) Arr::get($connection->metadata ?? [], 'token_user', []);

        if (! $fresh && ($cached['id'] ?? '') !== '') {
            return ['id' => (string) $cached['id'], 'email' => (string) ($cached['email'] ?? '')];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->acceptJson()
                ->get(rtrim($this->baseUrl, '/') . '/users/me');
        } catch (\Throwable $e) {
            Log::warning('sms.zoom could not read /users/me', ['error' => $e->getMessage()]);

            return ['id' => '', 'email' => ''];
        }

        if (! $response->successful()) {
            Log::warning('sms.zoom /users/me was refused', [
                'status' => $response->status(),
                'message' => $response->json('message'),
            ]);

            return ['id' => '', 'email' => ''];
        }

        $user = [
            'id' => (string) ($response->json('id') ?? ''),
            'email' => (string) ($response->json('email') ?? ''),
        ];

        if ($user['id'] !== '') {
            $connection->update([
                'metadata' => array_merge($connection->metadata ?? [], [
                    'token_user' => $user + ['fetched_at' => now()->toIso8601String()],
                ]),
            ]);
        }

        return $user;
    }

    /**
     * The send itself, on the user's token.
     *
     * Http rather than the inherited curl `request()`: that one hard-wires the
     * Server-to-Server token, and this is the one call that must not use it.
     * The return shape is identical, because ZoomSmsTransport, SmsSystemSender
     * and `errorMessage()` all read it.
     *
     * @param  array<string, mixed>  $body
     * @return array{success: bool, http_code: int, data: mixed}
     */
    private function sendAsUser(string $token, array $body): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/') . $this->sendPath(), $body);
        } catch (\Throwable $e) {
            Log::error('sms.zoom user-token send threw', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'http_code' => 0,
                'data' => ['message' => 'Could not reach Zoom: ' . $e->getMessage()],
            ];
        }

        // A 401 means the token died between the expiry check and the call.
        // One refresh, one retry, then give up - `$this->refreshed` makes that
        // true even if the expiry check already refreshed.
        if ($response->status() === 401 && ! $this->refreshed) {
            $connection = $this->userConnection();

            if ($connection !== null && $this->refreshUserToken($connection)) {
                $retry = (string) ($connection->access_token ?? '');

                if ($retry !== '') {
                    return $this->sendAsUser($retry, $body);
                }
            }
        }

        if (! $response->successful()) {
            Log::error('Zoom SMS send failed on the user token', [
                'http_code' => $response->status(),
                'response' => $response->body(),
            ]);
        }

        return [
            'success' => $response->successful(),
            'http_code' => $response->status(),
            'data' => $response->json() ?? null,
        ];
    }

    private function sendPath(): string
    {
        $path = (string) ModuleConfig::get('messaging.zoom.send_path', '/phone/sms/messages');

        return $path === '' ? '/phone/sms/messages' : $path;
    }
}
