<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsSystemMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * What the Zoom Phone webhook does with an SMS event.
 *
 * Lives out of ZoomWebhookController so that controller stays what it is - a
 * thin, always-200 dispatcher - and so this logic is testable without an HTTP
 * round trip and a signature.
 *
 * Everything here treats every payload field as optional. NONE of these shapes
 * has been seen from a live SMS-enabled Zoom account (the practice is still
 * waiting on an SMS-capable number); they follow Zoom's published event
 * reference. `raw_payload` is stored on every message for exactly that reason:
 * when the real events start arriving, that column is what says how the guesses
 * here differ from reality.
 *
 * Zoom's documented SMS payload object:
 *
 *   session_id     groups a conversation
 *   message_id     the message's own id - unique, and what a later
 *                  sent/failed event refers back to
 *   message        the text
 *   sender         {phone_number}
 *   to_members[]   [{phone_number}]
 *   owner          {id, type} - the Zoom user or common area the number is on
 *   date_time      ISO 8601, UTC
 *   message_type   1 = SMS, 2 = MMS
 *   attachments[]  {id, name, type, size, download_url}
 */
class SmsWebhookHandler
{
    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Handle one SMS event. Returns a short outcome string, which the controller
     * echoes back to Zoom and the tests assert on.
     */
    public function handle(string $event, array $body): string
    {
        $object = (array) Arr::get($body, 'payload.object', []);

        return match ($event) {
            'phone.sms_received' => $this->received($object),
            'phone.sms_sent' => $this->sent($object),
            'phone.sms_sent_failed' => $this->failed($object),
            default => $this->ignore($event, $object, 'unknown sms event'),
        };
    }

    /**
     * An inbound text.
     *
     * The line is found by whichever of the recipients is one of ours - Zoom
     * addresses the message to the number, and that number is the inbox. A
     * message for a number this application does not know about is logged and
     * dropped: the account may well have numbers that have nothing to do with
     * the CRM.
     */
    private function received(array $object): string
    {
        $line = $this->lineFrom($object, 'to_members');

        if ($line === null) {
            return $this->ignore('phone.sms_received', $object, 'no line matches the recipient number');
        }

        $from = $this->e164(Arr::get($object, 'sender.phone_number'));

        if ($from === null) {
            return $this->ignore('phone.sms_received', $object, 'sender number could not be read');
        }

        $thread = SmsThread::findOrCreateFor($line, $from, $this->sms->clientResolver());

        // Zoom's session id is worth keeping the first time it is seen: it is
        // the handle for reconciling this thread against Zoom's own view of the
        // conversation.
        $sessionId = $this->trim(Arr::get($object, 'session_id'));

        if ($sessionId !== '' && $thread->provider_session_id !== $sessionId) {
            $thread->forceFill(['provider_session_id' => $sessionId])->save();
        }

        $message = $this->sms->recordInbound(
            $thread,
            (string) (Arr::get($object, 'message') ?? ''),
            [
                'provider_message_id' => $this->messageId($object),
                'received_at' => $this->dateTime($object),
                'attachments' => $this->attachments($object),
                'raw_payload' => $object,
            ]
        );

        // null means the provider id was already on a row - a redelivery. Say so
        // rather than pretending a message arrived.
        return $message === null ? 'duplicate' : 'ok';
    }

    /**
     * Zoom confirming a message went out.
     *
     * Two cases, and the second is the one that is easy to forget: usually this
     * is our own message being confirmed, but a text sent from the Zoom desktop
     * or mobile app produces the same event for a message this module never
     * created. Recording that one is what keeps a conversation in the CRM whole.
     */
    private function sent(array $object): string
    {
        // Before anything else: was this one of ours but NOT an inbox message?
        // A two-factor code produces a phone.sms_sent like any other send, and
        // without this the block below would thread it as "an outbound from the
        // Zoom app" - publishing somebody's login code to every colleague
        // attached to the line.
        $system = $this->systemMessage($object);

        if ($system !== null) {
            $system->forceFill([
                'status' => SmsSystemMessage::STATUS_SENT,
                'error' => null,
                'sent_at' => $system->sent_at ?? $this->dateTime($object),
            ])->save();

            return 'ok';
        }

        $message = $this->existingMessage($object);

        if ($message !== null) {
            $this->sms->markStatus(
                $message,
                SmsMessage::STATUS_SENT,
                null,
                ['sent_at' => $message->sent_at ?? $this->dateTime($object)]
            );

            return 'ok';
        }

        // Not ours: an outbound from the Zoom app. The line is the SENDER here.
        $line = $this->lineFrom($object, 'sender');

        if ($line === null) {
            return $this->ignore('phone.sms_sent', $object, 'no line matches the sender number');
        }

        $to = $this->firstNumber(Arr::get($object, 'to_members'));

        if ($to === null) {
            return $this->ignore('phone.sms_sent', $object, 'recipient number could not be read');
        }

        $thread = SmsThread::findOrCreateFor($line, $to, $this->sms->clientResolver());

        $recorded = $this->sms->recordOutboundFromProvider(
            $thread,
            (string) (Arr::get($object, 'message') ?? ''),
            [
                'provider_message_id' => $this->messageId($object),
                'sent_at' => $this->dateTime($object),
                'raw_payload' => $object,
            ]
        );

        return $recorded === null ? 'duplicate' : 'ok';
    }

    /**
     * Zoom rejecting a message.
     *
     * A failure for a message we never sent is logged and dropped - there is
     * nothing in the CRM for the practice to act on, and inventing a failed row
     * for it would put a message in the thread that nobody here composed.
     */
    private function failed(array $object): string
    {
        // Same guard as sent(): a failed login code is recorded against its own
        // row and never becomes a message in a thread.
        $system = $this->systemMessage($object);

        if ($system !== null) {
            $system->forceFill([
                'status' => SmsSystemMessage::STATUS_FAILED,
                'error' => $this->failureReason($object),
            ])->save();

            return 'ok';
        }

        $message = $this->existingMessage($object);

        if ($message === null) {
            return $this->ignore('phone.sms_sent_failed', $object, 'no message matches that id');
        }

        $this->sms->markStatus(
            $message,
            SmsMessage::STATUS_FAILED,
            $this->failureReason($object)
        );

        return 'ok';
    }

    /* ---------------------------------------------------------------------
     | Payload reading
     | ------------------------------------------------------------------- */

    /**
     * The application-originated text this event refers back to, if it is one.
     *
     * Checked before every other lookup on a sent/failed event: these are the
     * messages that must never reach the inbox, so they must be recognised
     * before any code that could create a thread runs.
     */
    private function systemMessage(array $object): ?SmsSystemMessage
    {
        return SmsSystemMessage::findByProviderId($this->messageId($object));
    }

    /**
     * The message this event refers back to, by Zoom's message id.
     */
    private function existingMessage(array $object): ?SmsMessage
    {
        $id = $this->messageId($object);

        if ($id === null) {
            return null;
        }

        return SmsMessage::query()->where('provider_message_id', $id)->first();
    }

    /**
     * The line one of the numbers in `$key` belongs to.
     *
     * `to_members` is a list and `sender` is a single node, so both shapes are
     * accepted here rather than at each call site.
     */
    private function lineFrom(array $object, string $key): ?SmsLine
    {
        foreach ($this->numbers(Arr::get($object, $key)) as $number) {
            $line = SmsLine::findByNumber($number);

            if ($line !== null) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Every phone number in a node, whatever shape it is.
     *
     * @return array<int, string>
     */
    private function numbers($node): array
    {
        if (is_string($node)) {
            return [$node];
        }

        if (! is_array($node)) {
            return [];
        }

        // {"phone_number": "..."}
        if (array_key_exists('phone_number', $node)) {
            return [(string) $node['phone_number']];
        }

        // [{"phone_number": "..."}, ...]
        $numbers = [];

        foreach ($node as $member) {
            if (is_string($member)) {
                $numbers[] = $member;
            } elseif (is_array($member) && isset($member['phone_number'])) {
                $numbers[] = (string) $member['phone_number'];
            }
        }

        return $numbers;
    }

    private function firstNumber($node): ?string
    {
        foreach ($this->numbers($node) as $number) {
            $e164 = $this->e164($number);

            if ($e164 !== null) {
                return $e164;
            }
        }

        return null;
    }

    private function e164($value): ?string
    {
        return PhoneNumber::toE164(
            (string) (is_scalar($value) ? $value : ''),
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );
    }

    private function messageId(array $object): ?string
    {
        $id = $this->trim(Arr::get($object, 'message_id') ?? Arr::get($object, 'id'));

        return $id === '' ? null : $id;
    }

    /**
     * Zoom sends UTC; datetime columns are stored and re-read as app-timezone
     * wall clock, so without the conversion every message would be off by the
     * UTC offset and the thread would sort wrong.
     */
    private function dateTime(array $object): Carbon
    {
        $raw = Arr::get($object, 'date_time') ?? Arr::get($object, 'created_time');

        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse($raw)->setTimezone(config('app.timezone'));
            } catch (\Throwable $e) {
                // Fall through to now().
            }
        }

        return Carbon::now();
    }

    /**
     * MMS attachments, reduced to the fields a UI can use. `download_url` is
     * kept verbatim and is Zoom-authenticated - the front end cannot fetch it
     * directly, which is why the raw payload is kept too.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function attachments(array $object): ?array
    {
        $raw = Arr::get($object, 'attachments');

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $attachments = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachments[] = [
                'id' => $this->trim(Arr::get($item, 'id')),
                'name' => $this->trim(Arr::get($item, 'name')),
                'type' => $this->trim(Arr::get($item, 'type')),
                'size' => (int) Arr::get($item, 'size', 0),
                'download_url' => $this->trim(Arr::get($item, 'download_url')),
            ];
        }

        return $attachments === [] ? null : $attachments;
    }

    /**
     * Whatever Zoom said about why a send failed, or a plain fallback - the
     * message is shown to a staff member next to their failed text.
     */
    private function failureReason(array $object): string
    {
        foreach (['reason', 'error', 'message_status', 'failure_reason'] as $key) {
            $value = $this->trim(Arr::get($object, $key));

            if ($value !== '') {
                return $value;
            }
        }

        return 'Zoom reported the message could not be delivered.';
    }

    /**
     * Log an SMS event that did nothing, with the routing shape only - no
     * message body, because these logs are read casually and an SMS body is
     * client data.
     */
    private function ignore(string $event, array $object, string $reason): string
    {
        Log::info('sms.webhook ignored', [
            'event' => $event,
            'reason' => $reason,
            'session_id' => Arr::get($object, 'session_id'),
            'message_id' => Arr::get($object, 'message_id'),
        ]);

        return 'ignored';
    }

    private function trim($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
