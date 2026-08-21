<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsSystemMessage;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * Application-originated texts: login codes, portal OTPs, anything the software
 * sends on its own behalf.
 *
 * This is a SEPARATE path from SmsService::send() and that is the entire reason
 * it exists. SmsService writes a message into a thread, and a thread is an inbox
 * every staff member attached to the line can read - so routing a two-factor
 * code through it would publish one person's second factor to their colleagues.
 * Here there is no thread, no broadcast, no unread count, and the body is never
 * written down: what survives is an SmsSystemMessage saying a `two_factor` text
 * went to a number at a time, which is what an incident review needs and
 * nothing more.
 *
 * The other half of the separation lives in SmsWebhookHandler: Zoom answers
 * every send with a `phone.sms_sent` event, and without a guard the handler
 * would helpfully thread the login code it has never seen before as "an
 * outbound from the Zoom app". The provider id written here is what that guard
 * matches on, which is why the row is written before the provider is called.
 *
 * NOTHING here throws. A caller that must refuse on failure (the two-factor
 * sender does) decides that from the result; a caller that must not (a
 * reminder) simply carries on. An exception escaping into a login controller
 * would be a 500 on the login page.
 */
class SmsSystemSender
{
    /** Ids Zoom might give a sent message, in the order they are trusted. */
    private const ID_KEYS = ['message_id', 'id', 'data.message_id', 'data.id'];

    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Send one transactional text.
     *
     * @param  string  $to       The recipient, in any spelling; normalised here.
     * @param  string  $body     The rendered message. Never stored, never logged.
     * @param  string  $purpose  Why the application is texting: 'two_factor',
     *                           'portal_otp', or whatever the application calls
     *                           its own. Free-form, and the only thing an
     *                           auditor can group these rows by.
     */
    public function send(string $to, string $body, string $purpose): SmsSystemResult
    {
        $e164 = PhoneNumber::toE164(
            $to,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );

        if ($e164 === null) {
            // Nothing is written: there is no number to record it against, and
            // a row full of "we could not read this" would be noise in the one
            // table an auditor reads.
            Log::warning('sms.system send has an unusable number', ['purpose' => $purpose]);

            return SmsSystemResult::failed('unusable number');
        }

        $line = SmsLineResolver::resolve();

        if ($line === null) {
            Log::warning('sms.system send has no line to send from', [
                'purpose' => $purpose,
            ]);

            return SmsSystemResult::failed('No SMS line is configured');
        }

        // Written BEFORE the provider is contacted, for two reasons: a provider
        // that accepts the text and then times out must not leave no trace of a
        // code the user is about to type in, and the webhook guard needs a row
        // to match the provider id against by the time the event arrives - Zoom
        // can beat its own HTTP response.
        $record = SmsSystemMessage::create([
            'line_id' => $line->id,
            'purpose' => $purpose,
            'to_number' => $e164,
            'status' => SmsSystemMessage::STATUS_QUEUED,
        ]);

        return $this->deliver($record, $line, $e164, $body, $purpose);
    }

    /**
     * Hand the text to whichever transport is configured, and stamp the row with
     * what happened.
     *
     * Dispatching on the transport's NAME rather than calling the transport
     * itself is deliberate: Contracts\SmsTransport::send() takes an SmsMessage,
     * and an SmsMessage means a thread - the very thing this path exists to
     * avoid. The three names are the three the module ships; anything else is
     * treated as "not connected", which is the safe direction.
     */
    private function deliver(
        SmsSystemMessage $record,
        SmsLine $line,
        string $to,
        string $body,
        string $purpose
    ): SmsSystemResult {
        $transport = $this->sms->transportName();

        if ($transport === 'zoom') {
            return $this->viaZoom($record, $line, $to, $body, $purpose);
        }

        if ($transport === 'log') {
            return $this->viaLog($record, $line, $to, $purpose);
        }

        return $this->finishFailed(
            $record,
            'SMS is not connected',
            SmsSystemMessage::STATUS_NOT_CONNECTED
        );
    }

    /**
     * The real path: Zoom Phone.
     */
    private function viaZoom(
        SmsSystemMessage $record,
        SmsLine $line,
        string $to,
        string $body,
        string $purpose
    ): SmsSystemResult {
        try {
            $client = app(ZoomSmsClient::class);

            $result = $client->sendSms(
                (string) $line->phone_number,
                $to,
                $body,
                $line->zoom_user_id !== null ? (string) $line->zoom_user_id : null
            );
        } catch (\Throwable $e) {
            // The row already exists and something has to end up in its status
            // column - and a throwing HTTP client must never become a 500 on
            // the login page.
            Log::error('sms.system send threw', [
                'purpose' => $purpose,
                'system_message_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            return $this->finishFailed($record, 'Could not reach Zoom: ' . $e->getMessage());
        }

        if (! ($result['success'] ?? false)) {
            return $this->finishFailed($record, $client->errorMessage($result));
        }

        $raw = is_array($result['data'] ?? null) ? $result['data'] : [];

        return $this->finishSent($record, $this->providerId($raw));
    }

    /**
     * The development path: say it happened, send nothing.
     *
     * The body is NOT logged, unlike LogSmsTransport's - that one exists to let
     * a developer read the text they just composed, and this one would be
     * writing somebody's login code into storage/logs. Purpose, recipient and
     * line are enough to prove the wiring works.
     */
    private function viaLog(
        SmsSystemMessage $record,
        SmsLine $line,
        string $to,
        string $purpose
    ): SmsSystemResult {
        Log::info('sms.system log transport send', [
            'purpose' => $purpose,
            'to' => $to,
            'from' => $line->phone_number,
            'system_message_id' => $record->id,
        ]);

        // Derived from the row rather than random so the id in the log line and
        // the id in the table are the same string.
        return $this->finishSent($record, 'log-' . $record->id);
    }

    private function finishSent(SmsSystemMessage $record, ?string $providerMessageId): SmsSystemResult
    {
        // The webhook may already have claimed the row (see
        // SmsWebhookHandler::systemMessage()); its id and status win.
        $record->refresh();

        $record->forceFill([
            'status' => $record->status === SmsSystemMessage::STATUS_QUEUED
                ? SmsSystemMessage::STATUS_SENT
                : $record->status,
            'provider_message_id' => $record->provider_message_id ?? $providerMessageId,
            'error' => $record->status === SmsSystemMessage::STATUS_FAILED ? $record->error : null,
            'sent_at' => $record->sent_at ?? now(),
        ])->save();

        return SmsSystemResult::sent($providerMessageId, $record);
    }

    private function finishFailed(
        SmsSystemMessage $record,
        string $error,
        string $status = SmsSystemMessage::STATUS_FAILED
    ): SmsSystemResult {
        $record->forceFill([
            'status' => $status,
            'error' => $error,
        ])->save();

        return SmsSystemResult::failed($error, $record);
    }

    /**
     * Zoom's id for the message just sent, if it gave one.
     *
     * A send with no id back is still a send; it only means the delivery webhook
     * cannot be matched to this row - and, more to the point here, that the
     * webhook guard cannot recognise it and will fall through to its ordinary
     * "an outbound from the Zoom app" handling. Worth a log line for that
     * reason, not worth failing the login over.
     */
    private function providerId(array $raw): ?string
    {
        foreach (self::ID_KEYS as $key) {
            $value = Arr::get($raw, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        Log::info('sms.system send returned no message id', ['keys' => array_keys($raw)]);

        return null;
    }
}
