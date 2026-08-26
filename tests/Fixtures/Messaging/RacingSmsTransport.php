<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Visnsstudio\VisnsPackages\Contracts\SmsTransport;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Services\Sms\SmsSendResult;

/**
 * A transport that loses the race on purpose.
 *
 * Zoom's phone.sms_sent webhook can land while the send's own HTTP call is still
 * in flight - a separate request, on a separate worker, which is exactly why the
 * two can interleave. Reproducing that ordering with real concurrency is not
 * something a test can do, so this stands in for it: before the send result gets
 * back to SmsService, a row carrying the id that result is about to return
 * already exists, the way the webhook handler would have written it.
 */
class RacingSmsTransport implements SmsTransport
{
    public const PROVIDER_ID = 'zoom-raced-1';

    /**
     * Whether to write the webhook's row. Turned off by the test that puts the
     * id somewhere unrelated instead, where the collision is real but the
     * duplicate is not ours to delete.
     */
    public static bool $inserts = true;

    /** The row the "webhook" inserted, for the test to assert against. */
    public static ?int $insertedId = null;

    public static function reset(): void
    {
        self::$inserts = true;
        self::$insertedId = null;
    }

    public function send(SmsMessage $message): SmsSendResult
    {
        if (self::$inserts) {
            // What SmsService::recordOutboundFromProvider() would have written:
            // same thread, same direction, no user attribution.
            $raced = SmsMessage::create([
                'thread_id' => $message->thread_id,
                'direction' => SmsMessage::DIRECTION_OUT,
                'body' => $message->body,
                'status' => SmsMessage::STATUS_SENT,
                'user_id' => null,
                'provider_message_id' => self::PROVIDER_ID,
                'sent_at' => now(),
            ]);

            self::$insertedId = $raced->id;
        }

        return SmsSendResult::sent(self::PROVIDER_ID, ['message_id' => self::PROVIDER_ID]);
    }

    public function name(): string
    {
        return 'racing';
    }
}
