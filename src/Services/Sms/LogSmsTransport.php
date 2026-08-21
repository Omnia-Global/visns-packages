<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Contracts\SmsTransport;
use Visnsstudio\VisnsPackages\Models\SmsMessage;

/**
 * The development transport: writes the SMS to the log, reports success, and
 * texts back.
 *
 * The auto-reply is the point of it. Without one, every dev-mode conversation is
 * one-sided and none of the things that actually break - unread counts, the
 * broadcast reaching the right line's channel, the thread list reordering, the
 * read mark - are ever exercised. With it, the whole loop runs on a laptop with
 * no Zoom account, no webhook tunnel and no phone.
 *
 * Never configure this in production: `sent` here means "written to
 * storage/logs", and a practice would believe clients had been texted.
 */
class LogSmsTransport implements SmsTransport
{
    /**
     * How much of the outbound message the auto-reply quotes back. Long enough
     * to tell two test messages apart, short enough to stay inside the thread
     * list preview.
     */
    private const QUOTE_LENGTH = 60;

    public function send(SmsMessage $message): SmsSendResult
    {
        $thread = $message->thread;

        Log::info('sms.log transport send', [
            'thread_id' => $message->thread_id,
            'to' => $thread?->external_number,
            'from' => $thread?->line?->phone_number,
            'body' => $message->body,
        ]);

        $id = 'log-' . Str::uuid()->toString();

        $this->autoReply($message);

        return SmsSendResult::sent($id, ['transport' => 'log']);
    }

    public function name(): string
    {
        return 'log';
    }

    /**
     * Record an inbound reply on the same thread, as though the other end had
     * answered.
     *
     * The 1-2 second offset on `received_at` is not decoration: the thread is
     * ordered by time, and a reply stamped at the same second as the message it
     * answers sorts unpredictably. It is also roughly how long a real reply
     * takes to arrive, which makes a demo look like the real thing.
     *
     * Recording it goes through SmsService like any other inbound message, so
     * the dev loop exercises the same denormalisation, unread and broadcast code
     * a Zoom webhook would.
     */
    private function autoReply(SmsMessage $message): void
    {
        $thread = $message->thread;

        if ($thread === null) {
            return;
        }

        $quote = Str::limit((string) $message->body, self::QUOTE_LENGTH, '');

        app(SmsService::class)->recordInbound(
            $thread,
            'Auto-reply from the dev transport: received "' . $quote . '"',
            [
                'provider_message_id' => 'log-reply-' . Str::uuid()->toString(),
                'received_at' => now()->addSeconds(random_int(1, 2)),
                'raw_payload' => ['transport' => 'log', 'in_reply_to' => $message->id],
            ]
        );
    }
}
