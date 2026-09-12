<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * The opt-out register: who has asked not to be texted, and how we know.
 *
 * ALWAYS AVAILABLE when messaging is enabled, whether or not the bulk
 * sub-module is. It is not a feature of campaigns; it is the module's
 * compliance floor. The Spam Act 2003 requires a functional unsubscribe
 * facility on a commercial electronic message and requires it to be honoured,
 * and a client texting STOP to a Zoom Phone line produces a webhook payload and
 * nothing else this application can see. If this class did not read it, nobody
 * would.
 *
 * ## What Zoom does with a STOP - corrected 12 Sep 2026
 *
 * It was written here that Zoom performs no STOP handling at all. It does one
 * thing, and only one: once a recipient texts STOP, **Zoom refuses every
 * further outbound message from that Zoom number to that recipient** with code
 * 7037 (Support\ZoomSmsErrors), until the recipient texts START. That is a
 * block on OUR sending, not a register we can read - no endpoint lists it,
 * nothing tells us it happened, and it says nothing about the other lines a
 * practice owns or about what a campaign is entitled to do. So this table is
 * still the only thing that knows who has unsubscribed and is still what stops
 * bulk; Zoom's block is the backstop underneath it, and the reason the
 * confirmation sent back to a STOP is the one message in this module allowed to
 * disappear rather than fail (Services\Sms\SmsService::readOptOutKeyword).
 *
 * ## What it does and does not stop
 *
 * It stops BULK. Services\Sms\SmsCampaignSender consults it before every
 * recipient and skips rather than sends. It deliberately does NOT stop a staff
 * member replying to somebody in the inbox: that is a conversation the client
 * started, and refusing it would be a worse failure than the one this exists to
 * prevent. The thread payload carries `opted_out` so the screen can say so, and
 * says it rather than disabling anything.
 *
 * ## The keyword rule, and why it is not simply "contains STOP"
 *
 * keywordIn() matches the WHOLE message, or its FIRST WORD (or first two, for
 * the two-word keywords), after trimming and stripping surrounding punctuation,
 * case-insensitively.
 *
 *   "STOP"          -> out
 *   "stop."         -> out
 *   "stop please"   -> out
 *   "Please stop"   -> null
 *   "START"         -> in
 *
 * The last one is the whole design. "Please stop sending these on a Sunday" and
 * "can you stop the direct debit" are people TALKING to the practice, and
 * unsubscribing them for it would be acting on a guess - a guess that silently
 * removes somebody from every future communication and that nobody would notice
 * for months. A command is a word somebody typed on its own; a sentence is a
 * message for a human to read.
 */
class SmsOptOuts
{
    /** keywordIn()'s answer for an unsubscribe. */
    public const OUT = 'out';

    /** keywordIn()'s answer for a re-subscribe. */
    public const IN = 'in';

    /**
     * Canonicalise a number the way everything else in this module does.
     *
     * Null for anything that is not a number - a sender ID (`Apple`, a short
     * code) among them, which is right: there is no handset behind one, so
     * there is nothing to unsubscribe.
     */
    public function normalise(string $number): ?string
    {
        return PhoneNumber::toE164(
            $number,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );
    }

    /**
     * Has this number asked not to be texted?
     *
     * Takes an ALREADY normalised number, because it is called once per
     * recipient of a campaign and re-parsing four hundred numbers that were
     * canonicalised when they were imported is four hundred parses for nothing.
     * Callers holding raw input run normalise() first.
     */
    public function isOptedOut(string $e164): bool
    {
        if (trim($e164) === '') {
            return false;
        }

        try {
            return SmsOptOut::query()->where('number', $e164)->exists();
        } catch (QueryException $e) {
            return $this->registerUnreadable($e);
        }
    }

    /**
     * Which of these numbers are opted out, as a set keyed by the number.
     *
     * The batched form, for a page of threads. One `whereIn` rather than one
     * query per row: the inbox list draws fifty threads, and fifty existence
     * checks to grey out a badge is the N+1 that makes a list feel broken.
     *
     * @param  array<int, string>  $numbers
     * @return array<string, bool>
     */
    public function optedOutAmong(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter(
            $numbers,
            fn ($number) => is_string($number) && trim($number) !== ''
        )));

        if ($numbers === []) {
            return [];
        }

        try {
            return SmsOptOut::query()
                ->whereIn('number', $numbers)
                ->pluck('number')
                ->mapWithKeys(fn ($number) => [(string) $number => true])
                ->all();
        } catch (QueryException $e) {
            $this->registerUnreadable($e);

            return [];
        }
    }

    /**
     * The register could not be read.
     *
     * There is one ordinary way this happens: an application has updated the
     * package and has not yet run the new migration. Between those two steps
     * every thread payload asks this class a question, and a 500 there would
     * take the whole inbox down over a table nothing had needed the day before.
     *
     * So the READ degrades to "not opted out" - the same answer the module gave
     * before this feature existed - and says so in the log. It is deliberately
     * only the reads: a WRITE that cannot be stored is an unsubscribe that did
     * not happen, and its caller (SmsService::recordInbound) logs and carries on
     * for the webhook's sake rather than pretending it worked.
     *
     * Only QueryException, never Throwable: a broken query is the case this
     * covers, and swallowing everything would hide real bugs in here.
     */
    private function registerUnreadable(QueryException $e): bool
    {
        Log::warning('sms.opt-out register could not be read; treating the number as not opted out', [
            'error' => $e->getMessage(),
        ]);

        return false;
    }

    /**
     * Record an opt-out. Idempotent on the number.
     *
     * updateOrCreate rather than create: somebody who texts STOP twice, or who
     * texts it after an administrator already recorded the request by hand, must
     * not produce a unique-constraint violation on the webhook's hot path. The
     * newest reason wins, which is the useful one - it says how we most recently
     * heard.
     *
     * @param  string       $source     SmsOptOut::SOURCE_KEYWORD | SOURCE_MANUAL
     * @param  int|null     $lineId     The line that received the keyword.
     * @param  int|null     $messageId  The inbound message that triggered it - the evidence.
     * @param  mixed        $user       Who recorded a manual one.
     */
    public function record(
        string $e164,
        string $source,
        ?int $lineId = null,
        ?int $messageId = null,
        $user = null,
        ?string $note = null
    ): SmsOptOut {
        $userId = is_object($user) ? ($user->id ?? null) : $user;

        return SmsOptOut::updateOrCreate(
            ['number' => $e164],
            [
                'source' => $source,
                'line_id' => $lineId,
                'message_id' => $messageId,
                'user_id' => $userId === null ? null : (int) $userId,
                'note' => $note,
            ]
        );
    }

    /**
     * Opt a number back in.
     *
     * The row is REMOVED rather than flagged. An opt-out exists to be consulted
     * on every bulk send, and a soft-deleted row that some future scope forgot
     * to exclude would be a text to somebody who asked us to stop - which is the
     * one direction this register must never fail in. Who released it, and when,
     * is a question for the application's own auditing.
     *
     * Returns whether there was anything to remove, so a screen can say "that
     * number was not on the list" rather than reporting a success that did
     * nothing.
     */
    public function release(string $e164): bool
    {
        return SmsOptOut::query()->where('number', $e164)->delete() > 0;
    }

    /**
     * Read an inbound body as a command, if it is one.
     *
     * @return string|null  self::OUT, self::IN, or null for an ordinary message.
     */
    public function keywordIn(string $body): ?string
    {
        $candidates = $this->candidates($body);

        if ($candidates === []) {
            return null;
        }

        // Out is tested first. The two lists are configured separately and
        // nothing stops an application putting the same word in both; if it
        // does, the safe reading of an ambiguous instruction is the one that
        // stops texting somebody.
        if ($this->matches($candidates, 'messaging.opt_out.keywords')) {
            return self::OUT;
        }

        if ($this->matches($candidates, 'messaging.opt_out.opt_in_keywords')) {
            return self::IN;
        }

        return null;
    }

    /**
     * The confirmation to send back, or null for "say nothing".
     *
     * @param  string  $direction  self::OUT or self::IN
     */
    public function confirmation(string $direction): ?string
    {
        $key = $direction === self::IN
            ? 'messaging.opt_out.opt_in_reply'
            : 'messaging.opt_out.reply';

        $reply = ModuleConfig::get($key);

        return is_string($reply) && trim($reply) !== '' ? trim($reply) : null;
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * The three readings of a message that may be a command: the whole of it,
     * its first word, and its first two words.
     *
     * All three are reduced the same way - whitespace collapsed, surrounding
     * punctuation stripped, upper-cased - so "Stop!" and "STOP" and " stop "
     * are one string by the time anything is compared. The first two words are
     * what makes "OPT OUT" reachable without a second matching rule.
     *
     * @return array<int, string>
     */
    private function candidates(string $body): array
    {
        $whole = $this->reduce((string) preg_replace('/\s+/u', ' ', $body));

        if ($whole === '') {
            return [];
        }

        $words = array_values(array_filter(
            array_map(fn ($word) => $this->reduce($word), explode(' ', $whole)),
            fn ($word) => $word !== ''
        ));

        $candidates = [$whole];

        if (isset($words[0])) {
            $candidates[] = $words[0];
        }

        if (isset($words[0], $words[1])) {
            $candidates[] = $words[0] . ' ' . $words[1];
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Trim, strip punctuation off both ends, upper-case.
     *
     * Only the ENDS: a keyword with punctuation inside it does not exist, and
     * stripping throughout would turn "stop-loss" into "STOPLOSS" and, worse,
     * "e-stop" into something that starts with a keyword.
     */
    private function reduce(string $value): string
    {
        $value = trim($value);

        // \p{L}\p{N} rather than a list of punctuation: the message arrived from
        // a handset and may carry anything, and naming what to keep is the only
        // version of this that cannot be surprised.
        $value = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $value);

        return mb_strtoupper(trim($value));
    }

    /**
     * Does any reading of the message equal any configured keyword?
     *
     * @param  array<int, string>  $candidates
     */
    private function matches(array $candidates, string $configKey): bool
    {
        $keywords = ModuleConfig::get($configKey, []);

        if (! is_array($keywords)) {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            // The configured word goes through the same reduction as the
            // message. An application that wrote "Opt-Out" or " stop " into
            // config meant the same thing the default does, and a list that only
            // worked when it was typed in capitals would be a trap.
            $needle = $this->reduce((string) preg_replace('/\s+/u', ' ', $keyword));

            if ($needle !== '' && in_array($needle, $candidates, true)) {
                return true;
            }
        }

        return false;
    }
}
