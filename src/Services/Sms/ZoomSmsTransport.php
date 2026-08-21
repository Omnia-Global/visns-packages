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

            return SmsSendResult::failed('Could not reach Zoom: ' . $e->getMessage());
        }

        $raw = is_array($result['data'] ?? null) ? $result['data'] : [];

        if (! ($result['success'] ?? false)) {
            return SmsSendResult::failed(
                $client->errorMessage($result),
                $raw
            );
        }

        return SmsSendResult::sent($this->providerId($raw), $raw);
    }

    public function name(): string
    {
        return 'zoom';
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
