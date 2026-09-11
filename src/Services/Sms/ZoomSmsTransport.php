<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Contracts\SmsTransport;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;

/**
 * The real transport: Zoom Phone SMS.
 *
 * Sends went live against the practice's tenant on 21 Aug 2026 (see
 * ZoomSmsClient for what the live account confirmed). Every response read here
 * is still defensive, and the request body lives in one documented method on
 * ZoomSmsClient so a field rename on Zoom's side is a one-line fix.
 *
 * The client is resolved from the container per send rather than injected, for
 * the same reason CallQueueSettingsController resolves its Zoom service that
 * way: an application (or a test) that binds a replacement for ZoomSmsClient
 * must be certain nothing here goes to the live tenant behind its back.
 */
class ZoomSmsTransport implements SmsTransport
{
    /**
     * Ids Zoom might give a sent message, in the order they are trusted.
     * `message_id` is what the send response and the webhook agree on; `id` is
     * the fallback for the shape the reference shows on the session object.
     */
    private const ID_KEYS = ['message_id', 'id', 'data.message_id', 'data.id'];

    /** The HTTP status of the last send this instance made, 0 if it never completed. */
    private int $lastStatus = 0;

    /**
     * The response headers of the last send, lower-cased keys, values as arrays.
     *
     * @var array<string, array<int, string>>
     */
    private array $lastHeaders = [];

    /** `Retry-After` off those headers, in seconds. Null when it was not there. */
    private ?int $lastRetryAfter = null;

    public function send(SmsMessage $message): SmsSendResult
    {
        $this->lastStatus = 0;
        $this->lastHeaders = [];
        $this->lastRetryAfter = null;

        $thread = $message->thread;
        $line = $thread?->line;

        if ($thread === null || $line === null) {
            // Only reachable if a thread or line was deleted between the
            // message being written and this running.
            return SmsSendResult::failed('This conversation no longer has a line to send from.');
        }

        try {
            $client = app(ZoomSmsClient::class);

            $result = $client->sendSms(
                (string) $line->phone_number,
                (string) $thread->external_number,
                (string) $message->body,
                $line->zoom_user_id !== null ? (string) $line->zoom_user_id : null
            );
        } catch (\Throwable $e) {
            // A transport must not throw: the message row already exists and
            // something has to end up in its status column.
            Log::error('sms.zoom send threw', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            // Retryable: nothing reached Zoom. A DNS failure, a timeout, a
            // socket closed mid-request - the message was not sent, so sending
            // it again is not sending it twice.
            return SmsSendResult::failed('Could not reach Zoom: ' . $e->getMessage(), [], true);
        }

        $raw = is_array($result['data'] ?? null) ? $result['data'] : [];

        $this->lastStatus = (int) ($result['http_code'] ?? 0);
        $this->lastHeaders = $this->normaliseHeaders($result['headers'] ?? []);
        $this->lastRetryAfter = $this->readRetryAfter($this->lastHeaders);

        if (! ($result['success'] ?? false)) {
            return SmsSendResult::failed(
                $client->errorMessage($result),
                $raw,
                $this->retryable($result),
                // Only a 429 carries a wait worth honouring. A `Retry-After` on
                // a 503 is Zoom's load balancer talking about the whole account
                // and reading it as an instruction about SMS would stop a
                // campaign for as long as an edge node felt like.
                $this->lastStatus === 429 ? $this->lastRetryAfter : null,
                $this->lastStatus === 429
            );
        }

        return SmsSendResult::sent($this->providerId($raw), $raw);
    }

    public function name(): string
    {
        return 'zoom';
    }

    /**
     * The HTTP status of the last send this instance made.
     *
     * Instance state, and useless to anybody who did not make the call: the
     * service resolves a transport out of the container per send. It is here
     * for the transport's own decisions and for a test that wants to look.
     */
    public function lastStatus(): int
    {
        return $this->lastStatus;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function lastHeaders(): array
    {
        return $this->lastHeaders;
    }

    /**
     * Seconds the provider asked us to wait after the last send, or null.
     */
    public function lastRetryAfter(): ?int
    {
        return $this->lastRetryAfter;
    }

    /**
     * `Retry-After` in the two forms RFC 9110 allows, in seconds.
     *
     *   Retry-After: 120                                (delay-seconds)
     *   Retry-After: Wed, 11 Sep 2026 15:42:00 GMT      (an HTTP-date)
     *
     * Both are read because both are legal and nothing says which Zoom sends;
     * a date already in the past answers null rather than a negative, since
     * "wait for -3 seconds" would silently become the default wait somewhere
     * downstream and look like a header we had failed to read.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    private function readRetryAfter(array $headers): ?int
    {
        $value = trim((string) ($headers['retry-after'][0] ?? ''));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $seconds = (int) $value;

            return $seconds > 0 ? $seconds : null;
        }

        $at = strtotime($value);

        if ($at === false) {
            Log::info('sms.zoom sent an unreadable Retry-After', ['value' => $value]);

            return null;
        }

        $seconds = $at - time();

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Header names lower-cased and every value an array, whichever shape the
     * client handed back - Laravel's HTTP client answers `[name => [values]]`
     * and the curl path builds the same thing by hand, but a header name's case
     * is not guaranteed by either and `Retry-After` is exactly the header a
     * provider spells differently.
     *
     * @param  mixed  $headers
     * @return array<string, array<int, string>>
     */
    private function normaliseHeaders($headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $out = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            $out[strtolower($name)] = array_values(array_map(
                fn ($value) => (string) $value,
                is_array($values) ? $values : [$values]
            ));
        }

        return $out;
    }

    /**
     * Is this refusal worth asking about again in a minute?
     *
     * Read off the HTTP status ZoomSmsClient already surfaces as `http_code` on
     * every result - both its curl path and its user-token path put it there,
     * and the curl one reports 0 when the request never completed at all.
     *
     * Three shapes, and only three:
     *
     *   429   Zoom's rate limiter. Waiting is the ENTIRE remedy; a campaign
     *         hitting it has done nothing wrong except go quickly.
     *   5xx   Zoom is having a bad morning. Nothing about this message is wrong.
     *   0     the request never completed. The message was not sent.
     *
     * Everything else is a 4xx: a malformed body, a number Zoom will not
     * accept, a scope the app does not hold, the 7639 identity refusal. Every
     * one of those is exactly as true in a minute's time, and retrying them
     * would keep a whole campaign stuck behind one bad row for half an hour
     * while nothing else went out.
     *
     * @param  array{success: bool, http_code: int, data: mixed}  $result
     */
    private function retryable(array $result): bool
    {
        $status = (int) ($result['http_code'] ?? 0);

        return $status === 429 || $status >= 500 || $status === 0;
    }

    /**
     * Zoom's id for the message just sent, if it gave one.
     *
     * A send with no id back is still a send - it only means a later delivery
     * webhook cannot be matched to this row, which is worth a log line and not
     * worth failing over.
     */
    private function providerId(array $raw): ?string
    {
        foreach (self::ID_KEYS as $key) {
            $value = Arr::get($raw, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        Log::info('sms.zoom send returned no message id', ['keys' => array_keys($raw)]);

        return null;
    }
}
