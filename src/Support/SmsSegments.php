<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * How many SMS a body will actually be billed as.
 *
 * A text is not one message. The carrier encodes it in GSM-7 when every
 * character is in the GSM alphabet and in UCS-2 when even one is not, and the
 * two have wildly different budgets:
 *
 *              one message    each part of a concatenated one
 *   GSM-7      160            153   (7 septets go to the part header)
 *   UCS-2       70             67   (6 bytes go to the part header)
 *
 * Which means one curly apostrophe - the character Word and every phone
 * keyboard produce instead of ' - takes a 158-character message from one
 * segment to three. That is the whole reason this class exists: the campaign
 * preview says "3 segments" BEFORE somebody sends it to four hundred people,
 * and nobody has to know why.
 *
 * ## Two deliberate simplifications, both in the safe direction
 *
 * **The GSM extension table is treated as non-GSM.** `^ { } [ ] ~ | \` and the
 * euro sign are in GSM-7, but each costs TWO septets because it is written as
 * an escape plus a character. Rather than model that, a body containing one is
 * counted as UCS-2 - which over-counts. An over-count tells somebody their
 * message is dearer than it is; an under-count is a bill they did not expect,
 * and a body that got silently truncated on a handset.
 *
 * **UCS-2 length is counted in UTF-16 CODE UNITS, not characters.** An emoji
 * outside the basic multilingual plane is one character and two code units, and
 * the carrier budgets in code units. `mb_strlen()` would answer one and be
 * wrong by a factor of two on a body full of them.
 *
 * Nothing here talks to a provider: it is arithmetic over the text, so it is
 * the same answer under every transport including the null one.
 */
class SmsSegments
{
    /** A body of only GSM-7 characters. */
    public const GSM7 = 'gsm7';

    /** A body needing 16-bit encoding. */
    public const UCS2 = 'ucs2';

    public const GSM7_SINGLE = 160;
    public const GSM7_CONCATENATED = 153;
    public const UCS2_SINGLE = 70;
    public const UCS2_CONCATENATED = 67;

    /**
     * The GSM 03.38 BASIC alphabet, in one string.
     *
     * Written out rather than computed because it is not a range: it is a
     * historical table with Greek capitals, a handful of accented Latin
     * characters and several currency symbols in it, and anything "clever"
     * enough to generate it would be wrong somewhere nobody would look.
     *
     * The escape-table characters (`^{}[]~|\` and €) are NOT here, on purpose -
     * see the class docblock.
     */
    private const GSM7_ALPHABET =
        // SINGLE quotes for every run that contains a currency symbol: PHP
        // allows bytes 0x80-0xFF in an identifier, so "$¥" in a double-quoted
        // string is read as a VARIABLE and the file will not even parse. The
        // two control characters are the only things that need double quotes.
        '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r"
        . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /**
     * How many segments this body will be sent as.
     *
     * An empty body answers 1: nothing here is ever asked about a body nobody
     * intends to send, and answering 0 would make a preview read as though the
     * message would cost nothing.
     */
    public static function count(string $text): int
    {
        $gsm = self::isGsm7($text);

        $length = $gsm ? mb_strlen($text, 'UTF-8') : self::utf16Units($text);

        if ($length === 0) {
            return 1;
        }

        $single = $gsm ? self::GSM7_SINGLE : self::UCS2_SINGLE;

        if ($length <= $single) {
            return 1;
        }

        $part = $gsm ? self::GSM7_CONCATENATED : self::UCS2_CONCATENATED;

        return (int) ceil($length / $part);
    }

    /**
     * Which of the two encodings this body needs.
     */
    public static function alphabet(string $text): string
    {
        return self::isGsm7($text) ? self::GSM7 : self::UCS2;
    }

    /**
     * Is every character of this body in the basic GSM alphabet?
     */
    public static function isGsm7(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        // Not str_split: the alphabet and the text are both UTF-8 and several
        // of the characters in it are multi-byte, so splitting by byte would
        // compare halves of characters.
        //
        // The flipped alphabet is memoised. Safe to hold across requests - it is
        // derived from a constant and holds nothing anybody typed - and worth
        // it, because a campaign preview counts this for every recipient.
        static $allowed = null;

        if ($allowed === null) {
            $allowed = array_flip(
                preg_split('//u', self::GSM7_ALPHABET, -1, PREG_SPLIT_NO_EMPTY) ?: []
            );
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            if (! isset($allowed[$character])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The length a carrier budgets a UCS-2 message in.
     *
     * UTF-16BE is two bytes per code unit, so the byte length halved is the
     * count - and a character outside the basic multilingual plane correctly
     * counts as the two units it really is.
     */
    private static function utf16Units(string $text): int
    {
        $converted = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

        return (int) (strlen((string) $converted) / 2);
    }
}
