<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Support\Arr;

/**
 * Zoom Phone SMS refusal codes, and the sentence each one means.
 *
 * ## Why this exists at all
 *
 * Zoom answers a refused send with `{"code": N, "message": "..."}` and the
 * `message` is NOT always a sentence. Code **7037** — the one that took a
 * production inbox down to a red bubble on 11 Sep 2026 — arrives as
 *
 *     {"code": 7037, "message": "61415033181"}
 *
 * The recipient's own number, and nothing else. Services\Zoom\ZoomSmsClient
 * used to hand that straight to the message row's `error`, so the inbox drew
 * **Failed — 61415033181** with a Retry link beside it: a message that reads as
 * this application being broken, over the one refusal that is working exactly
 * as designed.
 *
 * So a code we recognise wins over `data.message`, and an unknown one falls
 * through to it unchanged. The provider's own body is kept verbatim on the
 * result's `raw` either way — the sentence is for the person reading the inbox,
 * not a replacement for the evidence.
 *
 * ## 7037 is Zoom enforcing STOP, and it is permanent until START
 *
 * Once a recipient texts STOP to a Zoom Phone number, **Zoom blocks every
 * further outbound message from that number to that recipient**, in its own
 * layer, before anything reaches a carrier. Nothing this module does lifts it;
 * only the recipient replying START does. That is why `blocksTheNumber()` is a
 * separate question from "was this refused": a caller that knows the send can
 * never work behaves differently from one looking at an ordinary failure, and
 * Services\Sms\SmsService's opt-out confirmation is the first such caller.
 *
 * It is also why the code is never `retryable` — see
 * Services\Sms\ZoomSmsTransport::retryable(), which reads the HTTP status and
 * answers false for every 4xx.
 */
final class ZoomSmsErrors
{
    /**
     * "This consumer number has opted out of receiving SMS".
     *
     * Zoom's own STOP enforcement. The message body is the recipient's number.
     */
    public const OPTED_OUT = 7037;

    /**
     * "You do not have permission to send SMS for this user" - the identity
     * refusal a Server-to-Server token gets for any sender but the account
     * owner. See ZoomSmsClient's header for the whole story.
     */
    public const WRONG_SENDER = 7639;

    /**
     * Code -> what it actually means, in the words the inbox should print.
     *
     * Deliberately short. A code is only in here when its meaning has been
     * confirmed against a live tenant AND Zoom's own text is either absent,
     * misleading or (7037) not a sentence at all; everything else reads better
     * in Zoom's own words than in a guess of ours, and a table full of
     * half-remembered codes is a table that eventually contradicts the provider.
     *
     * @var array<int, string>
     */
    private const SENTENCES = [
        self::OPTED_OUT => 'This number has opted out of texts from this line with Zoom, so nothing can be sent to it until it replies START.',
        self::WRONG_SENDER => 'Zoom will not send as this line\'s user. The Zoom SMS integration has to be connected while signed in to Zoom as the user who holds this number.',
    ];

    /**
     * The code off a client result, when it carried one.
     *
     * `data.code` is where Zoom puts it on every refusal shape seen so far. It
     * is read `is_numeric` rather than `is_int` because the body is decoded
     * JSON and a provider that starts quoting its codes must not silently stop
     * being understood.
     *
     * @param  array{success?: bool, http_code?: int, data?: mixed}  $result
     */
    public static function codeIn(array $result): ?int
    {
        $code = Arr::get($result, 'data.code');

        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * The sentence for a code, or null for one we have nothing better to say
     * about than the provider does.
     */
    public static function sentence(?int $code): ?string
    {
        return $code === null ? null : (self::SENTENCES[$code] ?? null);
    }

    /**
     * Has Zoom blocked this recipient, rather than refused this message?
     *
     * The difference is the whole of it: a refusal is about the request and may
     * be worth showing somebody, and a block is about the number and will
     * answer identically for ever.
     */
    public static function blocksTheNumber(?int $code): bool
    {
        return $code === self::OPTED_OUT;
    }
}
