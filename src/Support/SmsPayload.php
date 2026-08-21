<?php

namespace Visnsstudio\VisnsPackages\Support;

use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;

/**
 * The wire shape of a thread and a message.
 *
 * One class rather than a serialiser per caller, because the same two shapes go
 * out of the HTTP endpoints AND over the broadcast: a front end that renders a
 * thread row from `GET /threads` and then has to render a different one from a
 * `sms.received` push is a front end with two renderers and one of them
 * permanently slightly wrong.
 *
 * `unread_count` is the one field that cannot be in the broadcast: it is per
 * user, and a broadcast goes to everybody on the line. Pass a user for the HTTP
 * payloads; the events pass none and the key comes back null, which the front
 * end reads as "recount it yourself" (it knows whether the thread is open).
 */
class SmsPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function thread(SmsThread $thread, $user = null): array
    {
        return [
            'id' => $thread->id,
            'line_id' => $thread->line_id,
            'external_number' => $thread->external_number,
            'display_number' => $thread->display_number,
            'client' => $thread->client_id === null ? null : [
                'id' => (int) $thread->client_id,
                'name' => $thread->client_name,
            ],
            'contact_name' => $thread->contact_name,
            'last_message' => $thread->last_message_at === null ? null : [
                'body' => $thread->last_message_preview,
                'direction' => $thread->last_direction,
                'status' => $thread->last_message_status,
                'at' => $thread->last_message_at?->toIso8601String(),
            ],
            'unread_count' => $user === null ? null : $thread->unreadCountFor($user),
            'archived_at' => $thread->archived_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(SmsMessage $message): array
    {
        return [
            'id' => $message->id,
            'thread_id' => $message->thread_id,
            'direction' => $message->direction,
            'body' => $message->body,
            'status' => $message->status,
            'error' => $message->error,
            'user' => $message->user === null ? null : [
                'id' => $message->user->id,
                'name' => self::displayName($message->user),
            ],
            'attachments' => is_array($message->attachments) ? $message->attachments : [],
            'created_at' => $message->created_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'received_at' => $message->received_at?->toIso8601String(),
        ];
    }

    /**
     * Applications disagree about where a user's display name lives; take
     * whichever of the usual shapes this one has. Same rule as the vault's.
     */
    public static function displayName($user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        $name = trim(
            trim((string) ($user->firstname ?? '')) . ' '
            . trim((string) ($user->surname ?? ''))
        );

        return $name !== '' ? $name : null;
    }
}
