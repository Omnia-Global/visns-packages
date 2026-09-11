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

    public function send(SmsMessage $message): SmsSendResult
    {
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

        if (! ($result['success'] ?? false)) {
            return SmsSendResult::failed(
                $client->errorMessage($result),
                $raw,
                $this->retryable($result)
            );
        }

        return SmsSendResult::sent($this->providerId($raw), $raw);
    }

    public function name(): string
    {
        return 'zoom';
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
