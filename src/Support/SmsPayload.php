<?php

namespace Visnsstudio\VisnsPackages\Support;

use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

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
     * @param  bool|null  $optedOut  Whether this number has asked not to be
     *                               texted. Null means "work it out", which
     *                               costs one query - right for one thread, and
     *                               wrong for a list of fifty, so the list
     *                               endpoints batch the answer
     *                               (Services\Sms\SmsOptOuts::optedOutAmong)
     *                               and pass it in. A parameter rather than a
     *                               memo on a support class, deliberately: a
     *                               static cache would outlive the request under
     *                               Octane and go on answering yesterday's
     *                               question.
     * @return array<string, mixed>
     */
    public static function thread(SmsThread $thread, $user = null, ?bool $optedOut = null): array
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
            // Whether there is a handset at the other end. False for a sender
            // ID (`Apple`, `ANZ`, a short code): those threads receive and can
            // never answer. Decided here, on the server, so the compose box and
            // the endpoint that would refuse the send cannot disagree - and
            // sent on every thread, because a missing key would read to an
            // older front end exactly like `false`.
            'can_reply' => ! PhoneNumber::isSenderId($thread->external_number),
            // Has this number texted STOP? On every thread, for the same reason
            // `can_reply` is: a key that appeared on some rows and not others
            // would read as false wherever it was missing.
            //
            // It is a LABEL, not a gate. An opt-out stops bulk; a staff member
            // answering somebody who has written in is a conversation, and
            // refusing that would be a worse failure than the one opting out
            // protects against. The screen says so and the Send button still
            // works.
            'opted_out' => $optedOut ?? self::optedOut($thread),
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
     * The wire shape of a bulk campaign.
     *
     * `counts` and `progress` are what the list screen draws, and both come off
     * the denormalised counters rather than the recipients table - twenty
     * campaigns on a page would otherwise be twenty grouped queries over
     * hundreds of thousands of rows. The sender reconciles those counters at the
     * end of every run (SmsCampaign::syncCounts), which is what makes reading
     * them here honest.
     *
     * @return array<string, mixed>
     */
    public static function campaign(SmsCampaign $campaign): array
    {
        $counts = $campaign->counts();

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'line' => $campaign->line === null ? null : [
                'id' => $campaign->line->id,
                'label' => $campaign->line->label,
                'display_number' => $campaign->line->display_number,
            ],
            // The template as it was typed, WITHOUT the footer, and the footer
            // as config had it when the campaign was created. Kept apart so the
            // screen can show somebody their own words back, and snapshotted so
            // a config change cannot rewrite what was actually sent.
            'body' => $campaign->body,
            'footer' => $campaign->footer,
            'user' => $campaign->user === null ? null : [
                'id' => $campaign->user->id,
                'name' => self::displayName($campaign->user),
            ],
            'counts' => $counts,
            'progress' => self::progress($counts),
            'last_error' => $campaign->last_error,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'estimated_minutes_remaining' => self::minutesRemaining($campaign, $counts),
        ];
    }

    /**
     * The wire shape of one recipient.
     *
     * `message_id` and `thread_id` are plain ids and not links: what a screen
     * does with them is its business, and the thread they name is an ordinary
     * conversation reachable through the ordinary endpoints.
     *
     * @return array<string, mixed>
     */
    public static function campaignRecipient(SmsCampaignRecipient $recipient): array
    {
        return [
            'id' => $recipient->id,
            'name' => $recipient->name,
            'number' => $recipient->number,
            'display_number' => $recipient->display_number,
            'status' => $recipient->status,
            'error' => $recipient->error,
            'retries' => (int) $recipient->retries,
            'sent_at' => $recipient->sent_at?->toIso8601String(),
            'message_id' => $recipient->message_id === null ? null : (int) $recipient->message_id,
            'thread_id' => $recipient->thread_id === null ? null : (int) $recipient->thread_id,
        ];
    }

    /**
     * The wire shape of one opt-out.
     *
     * @return array<string, mixed>
     */
    public static function optOut(SmsOptOut $optOut): array
    {
        return [
            'id' => $optOut->id,
            'number' => $optOut->number,
            'display_number' => $optOut->display_number,
            'source' => $optOut->source,
            'line_id' => $optOut->line_id === null ? null : (int) $optOut->line_id,
            'note' => $optOut->note,
            'user' => $optOut->user === null ? null : [
                'id' => $optOut->user->id,
                'name' => self::displayName($optOut->user),
            ],
            'created_at' => $optOut->created_at?->toIso8601String(),
        ];
    }

    /**
     * Percent done, as an integer.
     *
     * FLOORED, and it never reads 100 until it really is: a bar sitting at 100%
     * while the last few messages go out is how somebody concludes a campaign
     * has hung. Rounding would do exactly that on any list over 200.
     *
     * @param  array{total: int, pending: int, sent: int, failed: int, skipped: int}  $counts
     */
    private static function progress(array $counts): int
    {
        if ($counts['total'] <= 0) {
            return 0;
        }

        $done = $counts['sent'] + $counts['failed'] + $counts['skipped'];

        if ($done >= $counts['total']) {
            return 100;
        }

        return min(99, (int) floor($done * 100 / $counts['total']));
    }

    /**
     * Roughly how long the rest of this will take, in minutes.
     *
     * Pending over the per-minute budget, rounded up. Null unless the campaign
     * is actually sending, because for a draft or a paused one the honest answer
     * is "until somebody presses start", and a number there would be read as a
     * countdown that had stalled.
     *
     * An ESTIMATE and nothing more: the budget is shared between every campaign
     * that is sending, and a retryable failure costs a minute.
     *
     * @param  array{total: int, pending: int, sent: int, failed: int, skipped: int}  $counts
     */
    private static function minutesRemaining(SmsCampaign $campaign, array $counts): ?int
    {
        if ($campaign->status !== SmsCampaign::STATUS_SENDING) {
            return null;
        }

        $perMinute = (int) ModuleConfig::get('messaging.bulk.per_minute', 30);

        if ($perMinute < 1) {
            $perMinute = 1;
        }

        return (int) ceil($counts['pending'] / $perMinute);
    }

    /**
     * The unbatched answer to "has this number opted out", for the callers that
     * are serialising exactly one thread.
     */
    private static function optedOut(SmsThread $thread): bool
    {
        if (PhoneNumber::isSenderId($thread->external_number)) {
            // No handset, so nothing to unsubscribe. Answered without a query,
            // which also keeps the broadcast path free of one.
            return false;
        }

        return app(SmsOptOuts::class)->isOptedOut((string) $thread->external_number);
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
