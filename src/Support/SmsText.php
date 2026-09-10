<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * The one place Zoom's escaping of an SMS body is undone.
 *
 * Zoom's SMS webhooks carry the text JSON-escaped a second time — observed
 * live twice over. A text sent with real line breaks came back in
 * phone.sms_sent with literal `\n\n` in `message`, and a client's thumbs-up
 * reply to a meeting reminder arrived as two six-character sequences, the
 * JSON escapes of the emoji's UTF-16 surrogate pair (backslash-u D83D,
 * backslash-u DC4D), and the inbox showed the staff member exactly that. The handset showed both correctly, so this
 * is the webhook's encoding, not the message's content.
 *
 * unescape() turns `\r\n`, `\n` and `\r` into newlines and decodes every
 * `\uXXXX` run as the JSON string it is, which is what pairs the surrogates
 * back into one code point. It runs on the way in (the webhook handler) AND on
 * the way out (the body and preview accessors), so rows stored before it
 * existed read correctly too, without a data repair. The body a client who
 * really typed a backslash-n loses is vanishingly rarer than the login codes,
 * multi-line texts and emoji replies this mends.
 */
final class SmsText
{
    public static function unescape(?string $text): string
    {
        $text = (string) $text;

        if (! str_contains($text, '\\')) {
            return $text;
        }

        $text = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $text);

        return (string) preg_replace_callback(
            '/(?:\\\\u[0-9a-fA-F]{4})+/',
            static function (array $match): string {
                $decoded = json_decode('"'.$match[0].'"');

                // A lone surrogate is not valid JSON; leave it as it came
                // rather than replace it with nothing.
                return is_string($decoded) ? $decoded : $match[0];
            },
            $text
        );
    }
}
