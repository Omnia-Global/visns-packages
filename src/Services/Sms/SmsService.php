<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Contracts\SmsTransport;
use Visnsstudio\VisnsPackages\Events\SmsMessageUpdated;
use Visnsstudio\VisnsPackages\Events\SmsReceived;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * Everything that happens to a message between "somebody pressed send" and "the
 * row is in the right state and the browsers know".
 *
 * The controllers, the webhook handler, the dev transport's auto-reply and the
 * console commands all go through here, which is the point: the status
 * vocabulary, the thread denormalisation and the two broadcasts are each written
 * once. A second place that inserted an inbound message would be a second place
 * that could forget to touch `last_message_at`, and the thread list would
 * silently stop reordering.
 */
class SmsService
{
    /** The transports that have a short name in config. */
    private const BUILTIN = [
        'zoom' => ZoomSmsTransport::class,
        'log' => LogSmsTransport::class,
        'null' => NullSmsTransport::class,
    ];

    /**
     * The configured transport.
     *
     * Resolved per call, through the container: an application (or a test)
     * binding a double for one of the transport classes must be honoured, and a
     * memoised instance would outlive a config change made mid-request by a
     * test.
     *
     * An unusable configuration falls back to the null transport rather than
     * throwing - the safe direction, because the alternative to "nothing was
     * sent" is a 500 on every send.
     */
    public function transport(): SmsTransport
    {
        $configured = ModuleConfig::get('messaging.transport', 'null');

        if (is_object($configured) && $configured instanceof SmsTransport) {
            return $configured;
        }

        $name = is_string($configured) && $configured !== '' ? $configured : 'null';

        $class = self::BUILTIN[$name] ?? $name;

        if (! class_exists($class)) {
            Log::warning('sms transport is not a class', ['transport' => $name]);

            return app(NullSmsTransport::class);
        }

        $transport = app($class);

        if (! $transport instanceof SmsTransport) {
            Log::warning('sms transport does not implement SmsTransport', [
                'transport' => $class,
            ]);

            return app(NullSmsTransport::class);
        }

        return $transport;
    }

    /**
     * The transport's short name, for GET {base}/status.
     */
    public function transportName(): string
    {
        return $this->transport()->name();
    }

    /**
     * Whether a real provider is behind the module. The UI uses this to say
     * plainly that messaging is not connected yet rather than pretending.
     */
    public function connected(): bool
    {
        return $this->transportName() === 'zoom';
    }

    /**
     * Send a message on a thread.
     *
     * The row is written BEFORE the transport is called and updated after, not
     * the other way round: a provider that accepts the SMS and then times out on
     * the response must not leave the practice with a text the client received
     * and no record of it. The cost is a `queued` row for the duration of the
     * call, which is exactly what `queued` means.
     *
     * Synchronous rather than queued because the composer waits for the result -
     * and because an AFSL practice sending a client a text wants to be told
     * immediately if it failed.
     */
    public function send(SmsThread $thread, string $body, $user = null): SmsMessage
    {
        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUT,
            'body' => $body,
            'status' => SmsMessage::STATUS_QUEUED,
            'user_id' => is_object($user) ? ($user->id ?? null) : $user,
        ]);

        // The transport reads both, and a lazily-loaded relation inside a
        // transport is a query per send.
        $message->setRelation('thread', $thread);
        $thread->setRelation('line', $thread->line);

        $result = $this->transport()->send($message);

        $message->status = $result->status;
        $message->error = $result->error;
        $message->provider_message_id = $result->providerMessageId;

        if ($result->successful()) {
            $message->sent_at = now();
        }

        if ($result->raw !== []) {
            $message->raw_payload = $result->raw;
        }

        $message->save();

        // Even a not_connected message is the newest thing in the thread: the
        // list has to show it, or the practice loses track of what it tried to
        // send.
        $thread->touchLastMessage($message);

        $this->dispatch('updated', SmsMessageUpdated::class, $thread, $message);

        return $message;
    }

    /**
     * Send to a bare number, threading it into the inbox.
     *
     * For CLIENT-FACING messages the application originates - an appointment
     * reminder, a "your review pack is ready" - where there is no thread in hand
     * and no staff member pressing send. The message SHOULD be in the inbox:
     * the client can reply to it, and the reply has to land somewhere a human
     * will see.
     *
     * This is the opposite decision from SmsSystemSender, which exists for
     * texts that must NOT be in the inbox (login codes). If the body contains a
     * credential, this is the wrong method.
     *
     * The line is resolved by SmsLineResolver - the same order the system sender
     * uses, so a reminder and a code come from the same number - unless the
     * caller names one.
     *
     * Returns null rather than throwing when there is no line or the number
     * cannot be read: the caller is a reminder job, and a whole run must not die
     * because one client's mobile is "n/a".
     *
     * @param  mixed  $user  The staff member to attribute it to, when the
     *                       application has one in mind. Null reads as "the
     *                       system sent this".
     */
    public function sendToNumber(string $to, string $body, $user = null, ?SmsLine $line = null): ?SmsMessage
    {
        $e164 = PhoneNumber::toE164(
            $to,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );

        if ($e164 === null) {
            Log::warning('sms.sendToNumber has an unusable number');

            return null;
        }

        $line = $line ?? SmsLineResolver::resolve();

        if ($line === null) {
            Log::warning('sms.sendToNumber has no line to send from', ['to' => $e164]);

            return null;
        }

        $thread = SmsThread::findOrCreateFor($line, $e164, $this->clientResolver());

        return $this->send($thread, $body, $user);
    }

    /**
     * Record an inbound message.
     *
     * Idempotent on `provider_message_id`: Zoom retries a webhook it did not get
     * a timely 200 for, and the second delivery must update the row rather than
     * duplicate the client's message in the thread. A message with no provider
     * id (the simulator, the dev transport) is always an insert.
     *
     * Returns null when the message already existed and nothing changed, so the
     * caller can skip the broadcast - a redelivery must not pop a notification
     * twice.
     */
    public function recordInbound(SmsThread $thread, string $body, array $attributes = []): ?SmsMessage
    {
        $providerId = $attributes['provider_message_id'] ?? null;

        if (is_string($providerId) && $providerId !== '') {
            $existing = SmsMessage::query()
                ->where('provider_message_id', $providerId)
                ->first();

            if ($existing !== null) {
                return null;
            }
        }

        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_IN,
            'body' => $body,
            'status' => SmsMessage::STATUS_RECEIVED,
            'user_id' => null,
            'provider_message_id' => is_string($providerId) && $providerId !== '' ? $providerId : null,
            'attachments' => $attributes['attachments'] ?? null,
            'raw_payload' => $attributes['raw_payload'] ?? null,
            'received_at' => $attributes['received_at'] ?? now(),
        ]);

        $thread->touchLastMessage($message);

        $this->dispatch('received', SmsReceived::class, $thread, $message);

        return $message;
    }

    /**
     * Record an outbound message the module did not send - one typed into the
     * Zoom app or the Zoom mobile client.
     *
     * Without this, half of a practice's conversation would be invisible in the
     * CRM: the client's replies would be threaded, the adviser's own texts from
     * their phone would not. `user_id` is null because Zoom identifies the
     * sender by extension, not by CRM user.
     */
    public function recordOutboundFromProvider(SmsThread $thread, string $body, array $attributes = []): ?SmsMessage
    {
        $providerId = $attributes['provider_message_id'] ?? null;

        if (is_string($providerId) && $providerId !== '') {
            $existing = SmsMessage::query()
                ->where('provider_message_id', $providerId)
                ->first();

            if ($existing !== null) {
                return null;
            }
        }

        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUT,
            'body' => $body,
            'status' => $attributes['status'] ?? SmsMessage::STATUS_SENT,
            'user_id' => null,
            'provider_message_id' => is_string($providerId) && $providerId !== '' ? $providerId : null,
            'raw_payload' => $attributes['raw_payload'] ?? null,
            'sent_at' => $attributes['sent_at'] ?? now(),
        ]);

        $thread->touchLastMessage($message);

        $this->dispatch('updated', SmsMessageUpdated::class, $thread, $message);

        return $message;
    }

    /**
     * Move a message to a new status and tell the browsers.
     *
     * @param  array<string, mixed>  $extra  Timestamp columns to stamp alongside.
     */
    public function markStatus(SmsMessage $message, string $status, ?string $error = null, array $extra = []): SmsMessage
    {
        $message->status = $status;
        $message->error = $error;

        foreach ($extra as $column => $value) {
            $message->{$column} = $value;
        }

        $message->save();

        $thread = $message->thread;

        if ($thread !== null) {
            $this->dispatch('updated', SmsMessageUpdated::class, $thread, $message);
        }

        return $message;
    }

    /**
     * The application's number -> client hook, or null.
     *
     * The CRM passes the same invokable it gives the call queue
     * (Contracts\CallerEnrichment), so a number that pops a client card on an
     * incoming call also names the client on an incoming text.
     */
    public function clientResolver(): ?callable
    {
        return ModuleConfig::callable('messaging.client_resolver');
    }

    /**
     * The application's client search hook, or null.
     */
    public function clientSearch(): ?callable
    {
        return ModuleConfig::callable('messaging.client_search');
    }

    /**
     * Dispatch one of the module's two events, honouring the class configured
     * for it.
     *
     * Configurable for the same reason the call queue's are: Laravel's
     * Event::fake() keys listeners by EXACT class name, so an application whose
     * listeners are written against its own App\Events\Sms* classes can only be
     * reached by dispatching those classes themselves.
     *
     * A configured name that does not resolve falls back to the package's own
     * class rather than throwing - a typo in config should cost a listener, not
     * the message.
     *
     * @param  class-string  $default
     */
    private function dispatch(string $key, string $default, SmsThread $thread, SmsMessage $message): void
    {
        $configured = ModuleConfig::get('messaging.events.' . $key);

        $class = $default;

        if (is_string($configured) && $configured !== '') {
            if (class_exists($configured)) {
                $class = $configured;
            } else {
                Log::warning('sms configured event class does not exist', [
                    'event' => $key,
                    'class' => $configured,
                ]);
            }
        }

        event(new $class($thread, $message));
    }
}
