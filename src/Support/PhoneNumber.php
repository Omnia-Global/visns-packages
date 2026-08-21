<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * The one place a phone number is turned into the canonical form the messaging
 * module stores and compares.
 *
 * Everything on the way in - a webhook payload, a staff member typing into the
 * "to" box, a client record's mobile column - goes through toE164() before it
 * touches the database, because the module's whole routing rule is an equality
 * check between a stored `sms_lines.phone_number` and a number Zoom sent. Two
 * spellings of the same number would silently create a second thread, or worse,
 * fail to match a line at all and drop an inbound message.
 *
 * This is NOT a general-purpose libphonenumber replacement and does not try to
 * be. It handles the shapes an Australian financial-advice practice actually
 * types, plus already-canonical international numbers, and returns null for
 * anything it cannot be confident about - a null is a 422 to the user, which is
 * a far better outcome than a confidently wrong +61.
 *
 * Deliberately dependency-free (no giovanni/libphonenumber): this package is
 * installed into applications that did not ask for a 3MB metadata blob, and the
 * country list in scope is one.
 */
class PhoneNumber
{
    /**
     * Country calling codes this class knows how to expand a national number
     * into. Adding a country here means answering two questions for it: the
     * calling code, and whether national numbers carry a trunk '0'.
     *
     * @var array<string, array{code: string, trunk: string, national_length: array<int, int>}>
     */
    private const COUNTRIES = [
        'AU' => ['code' => '61', 'trunk' => '0', 'national_length' => [9]],
        'NZ' => ['code' => '64', 'trunk' => '0', 'national_length' => [8, 9]],
        'GB' => ['code' => '44', 'trunk' => '0', 'national_length' => [10]],
        'US' => ['code' => '1', 'trunk' => '', 'national_length' => [10]],
        'CA' => ['code' => '1', 'trunk' => '', 'national_length' => [10]],
    ];

    /** E.164 allows at most 15 digits after the plus. */
    private const MAX_DIGITS = 15;

    /** Below this it is an extension or a typo, not a phone number. */
    private const MIN_DIGITS = 6;

    /**
     * Canonicalise a number to E.164 ("+61412345678"), or null when it cannot
     * be understood.
     *
     * Accepted, in order of how it is decided:
     *
     *   +61 412 345 678    already E.164, punctuation stripped
     *   0011 61 4...       an Australian international dial prefix
     *   61412345678        the country code with no plus, when the remainder
     *                      is a plausible national number for that country
     *   0412 345 678       a national number with the trunk prefix
     *   (08) 9375 2549     the same, with the punctuation people actually type
     *   9375 2549          refused: without an area code this is ambiguous
     *
     * @param  string  $input           Anything a human or a provider produced.
     * @param  string  $defaultCountry  ISO 3166-1 alpha-2; see COUNTRIES.
     */
    public static function toE164(string $input, string $defaultCountry = 'AU'): ?string
    {
        $raw = trim($input);

        if ($raw === '') {
            return null;
        }

        $country = self::COUNTRIES[strtoupper($defaultCountry)]
            ?? self::COUNTRIES['AU'];

        // An explicit plus is a promise that what follows is already E.164;
        // take the digits and believe it.
        if (str_starts_with($raw, '+')) {
            return self::finish(self::digits($raw));
        }

        $digits = self::digits($raw);

        if ($digits === '') {
            return null;
        }

        // International dial prefixes: 0011 (AU/NZ), 00 (most of the world),
        // 011 (North America). Whatever follows is a country code already.
        foreach (['0011', '011', '00'] as $idd) {
            if (str_starts_with($digits, $idd) && strlen($digits) > strlen($idd) + self::MIN_DIGITS) {
                return self::finish(substr($digits, strlen($idd)));
            }
        }

        $code = $country['code'];
        $trunk = $country['trunk'];

        // "61412345678": the country code written without a plus. Only accepted
        // when the remainder is the right length for a national number there -
        // otherwise "61 89 12 34" (a short local number that happens to start
        // with 61) would be mangled into an international one.
        if (str_starts_with($digits, $code)) {
            $remainder = substr($digits, strlen($code));

            if (in_array(strlen($remainder), $country['national_length'], true)) {
                return self::finish($digits);
            }
        }

        // "0412345678" / "(08) 9375 2549": strip the trunk prefix, prepend the
        // country code.
        if ($trunk !== '' && str_starts_with($digits, $trunk)) {
            $national = substr($digits, strlen($trunk));

            if (in_array(strlen($national), $country['national_length'], true)) {
                return self::finish($code . $national);
            }
        }

        // A bare national number with no trunk prefix, in a country that does
        // not use one (North America).
        if ($trunk === '' && in_array(strlen($digits), $country['national_length'], true)) {
            return self::finish($code . $digits);
        }

        // Anything else - a local number with no area code, an extension, a
        // truncated paste. Refused on purpose.
        return null;
    }

    /**
     * The readable form, for a UI that would otherwise show every Australian
     * number as +61.
     *
     * Falls back to returning the input unchanged rather than guessing: an
     * international number is more useful in E.164 than in a made-up grouping.
     */
    public static function toLocal(?string $e164, string $defaultCountry = 'AU'): string
    {
        $e164 = trim((string) $e164);

        if ($e164 === '') {
            return '';
        }

        $country = self::COUNTRIES[strtoupper($defaultCountry)] ?? null;

        if ($country === null || ! str_starts_with($e164, '+' . $country['code'])) {
            return $e164;
        }

        $national = substr($e164, strlen($country['code']) + 1);

        if (! in_array(strlen($national), $country['national_length'], true)) {
            return $e164;
        }

        $local = $country['trunk'] . $national;

        // Australian grouping: mobiles 04xx xxx xxx, landlines (0x) xxxx xxxx.
        if (strtoupper($defaultCountry) === 'AU' && strlen($local) === 10) {
            return str_starts_with($local, '04')
                ? substr($local, 0, 4) . ' ' . substr($local, 4, 3) . ' ' . substr($local, 7)
                : '(' . substr($local, 0, 2) . ') ' . substr($local, 2, 4) . ' ' . substr($local, 6);
        }

        return $local;
    }

    /**
     * Do two numbers, however they were written, mean the same handset?
     *
     * Both sides are canonicalised first; two numbers neither of which can be
     * canonicalised fall back to comparing their digits, so a pair of
     * unparseable-but-identical strings still matches.
     */
    public static function matches(?string $a, ?string $b, string $defaultCountry = 'AU'): bool
    {
        $a = trim((string) $a);
        $b = trim((string) $b);

        if ($a === '' || $b === '') {
            return false;
        }

        $left = self::toE164($a, $defaultCountry);
        $right = self::toE164($b, $defaultCountry);

        if ($left !== null && $right !== null) {
            return $left === $right;
        }

        return self::digits($a) === self::digits($b);
    }

    /**
     * Digits only - everything a human might type between them is noise.
     */
    public static function digits(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }

    /**
     * Last gate: a plausible E.164 body becomes "+<digits>", anything else
     * becomes null.
     */
    private static function finish(string $digits): ?string
    {
        $digits = ltrim($digits, '0');

        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        return '+' . $digits;
    }
}
