<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;
use Visnsstudio\VisnsPackages\Support\SmsSegments;

/**
 * One campaign body, plus one recipient, equals the text that is actually sent.
 *
 * ONE implementation, used by the sender and by the preview endpoint - and that
 * is the point of the class existing at all. A preview that rendered
 * differently from the send would be worse than no preview: somebody checks
 * three messages, sees them come out right, and authorises four hundred that do
 * not.
 *
 * ## Placeholders
 *
 *   {name}        the recipient's name as imported
 *   {first_name}  the first whitespace-separated word of it
 *   {last_name}   everything after that word
 *   {anything}    any key of the imported row's `extra`, matched
 *                 case-insensitively
 *
 * An UNKNOWN placeholder resolves to an empty string rather than being left on
 * screen. Both answers are bad; this one is quietly bad and the other is loudly
 * bad in a client's pocket. "Hi , your review" reads as a typo. "Hi {frist_name},
 * your review" reads as an organisation that does not know what it is doing -
 * and the preview endpoint exists precisely so the typo is caught before four
 * hundred people see it.
 *
 * There is deliberately no expression language, no conditionals and no
 * formatting. A merge field in an SMS is a name and a date; anything more
 * belongs in an email.
 *
 * ## The footer
 *
 * The unsubscribe instruction is appended by the SERVER, not trusted to
 * whoever typed the body, because it is the compliance requirement rather than
 * a nicety (see the config block). It is skipped when the rendered body already
 * contains it, case-insensitively, so somebody who wrote their own "reply STOP
 * to opt out" is not given it twice - and it is taken from the CAMPAIGN, which
 * snapshotted it at create time, so a config change cannot rewrite what was
 * sent last month.
 */
class SmsCampaignRenderer
{
    /**
     * A placeholder: braces around a name of letters, digits, underscores,
     * hyphens and single spaces.
     *
     * Spaces are allowed because `extra` keys come from a spreadsheet's header
     * row and "Renewal Date" is what a person types there. Anything else - a
     * newline, a brace, a colon - is not a placeholder and is left alone, so a
     * body containing JSON or an emoticon survives unharmed.
     */
    private const PLACEHOLDER = '/\{([A-Za-z0-9_][A-Za-z0-9_ -]*)\}/';

    /**
     * The text that would go to this recipient.
     */
    public function render(SmsCampaign $campaign, SmsCampaignRecipient $recipient): string
    {
        return $this->compose(
            (string) $campaign->body,
            (string) ($campaign->footer ?? ''),
            $recipient->name,
            is_array($recipient->extra) ? $recipient->extra : []
        );
    }

    /**
     * The first few rendered messages, for the preview endpoint.
     *
     * Takes raw rows rather than models because it is called BEFORE a campaign
     * exists - somebody is still typing. The footer is therefore passed in by
     * the caller (which reads it from config, exactly as create does when it
     * snapshots one), so that the preview and the campaign about to be created
     * agree.
     *
     * @param  array<int, array<string, mixed>>  $recipientRows  [{name?, number, extra?}]
     * @return array<int, array{name: string|null, number: string, body: string, segments: int}>
     */
    public function preview(string $body, string $footer, array $recipientRows, int $limit = 3): array
    {
        $previews = [];

        foreach ($recipientRows as $row) {
            if (count($previews) >= max(1, $limit)) {
                break;
            }

            if (! is_array($row)) {
                continue;
            }

            $name = isset($row['name']) && trim((string) $row['name']) !== ''
                ? trim((string) $row['name'])
                : null;

            $number = trim((string) ($row['number'] ?? ''));

            $rendered = $this->compose(
                $body,
                $footer,
                $name,
                isset($row['extra']) && is_array($row['extra']) ? $row['extra'] : []
            );

            $previews[] = [
                'name' => $name,
                // Shown canonicalised where it can be, and verbatim where it
                // cannot: a preview of a row whose number is unreadable still
                // has to be able to draw the row, and the CREATE endpoint is
                // what refuses it.
                'number' => $this->normalisedOr($number),
                'body' => $rendered,
                'segments' => SmsSegments::count($rendered),
            ];
        }

        return $previews;
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * The one rendering. Everything public here funnels through it.
     *
     * @param  array<string, mixed>  $extra
     */
    private function compose(string $body, string $footer, ?string $name, array $extra): string
    {
        $values = $this->values($name, $extra);

        $rendered = (string) preg_replace_callback(
            self::PLACEHOLDER,
            function (array $match) use ($values) {
                $key = $this->key($match[1]);

                return array_key_exists($key, $values) ? $values[$key] : '';
            },
            $body
        );

        return $this->withFooter($rendered, $footer);
    }

    /**
     * Everything a placeholder may resolve to, keyed by its reduced name.
     *
     * The three name fields are written FIRST and the imported columns
     * afterwards, so a spreadsheet with its own "first_name" column wins over
     * the one derived from "name". That is the right way round: a column
     * somebody put in the file is a deliberate answer, and the split is a guess
     * made by this class.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, string>
     */
    private function values(?string $name, array $extra): array
    {
        $name = trim((string) $name);

        // Split on the FIRST run of whitespace: "Mary Jane Watson" is Mary, then
        // Jane Watson. Guessing at middle names would be guessing.
        $parts = $name === '' ? [] : preg_split('/\s+/u', $name, 2);

        $values = [
            'name' => $name,
            'first_name' => (string) ($parts[0] ?? ''),
            'last_name' => (string) ($parts[1] ?? ''),
        ];

        foreach ($extra as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                // An array or an object in a merge field would render as
                // "Array" in a client's text. Nothing is better.
                continue;
            }

            $reduced = $this->key((string) $key);

            if ($reduced === '') {
                continue;
            }

            $values[$reduced] = trim((string) $value);
        }

        return $values;
    }

    /**
     * A placeholder name and a spreadsheet header, reduced to one spelling.
     *
     * Case-insensitive by the requirement; spaces folded to underscores so that
     * a header of "Renewal Date" answers both `{Renewal Date}` and
     * `{renewal_date}`. Somebody typing a placeholder is reading the header off
     * a spreadsheet, and making them reproduce its capitalisation and spacing
     * exactly would be a rule nobody could follow reliably.
     */
    private function key(string $name): string
    {
        $name = trim(mb_strtolower(trim($name)));

        return (string) preg_replace('/[\s-]+/u', '_', $name);
    }

    /**
     * Append the unsubscribe footer, unless it is already in there.
     *
     * `footer_required` false leaves the body exactly as written - for a
     * deployment whose messages are genuinely not commercial, which is a
     * decision for the practice's compliance people and not a default.
     */
    private function withFooter(string $rendered, string $footer): string
    {
        $footer = trim($footer);

        if ($footer === '' || ! (bool) ModuleConfig::get('messaging.bulk.footer_required', true)) {
            return $rendered;
        }

        // mb_stripos, so a body that says "reply stop to opt out" in the middle
        // of a sentence counts. Over-matching is the safe side here: the worst
        // it does is leave out a second copy of an instruction that is already
        // there.
        if (mb_stripos($rendered, $footer) !== false) {
            return $rendered;
        }

        // A newline rather than a space: on a handset the footer reads as a
        // footer, and a wrapped sentence ending "...Reply STOP to opt out."
        // reads as part of the message.
        return rtrim($rendered) === ''
            ? $footer
            : rtrim($rendered) . "\n" . $footer;
    }

    /**
     * E.164 where the number can be read, the raw input where it cannot.
     */
    private function normalisedOr(string $number): string
    {
        $e164 = PhoneNumber::toE164(
            $number,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );

        return $e164 ?? $number;
    }
}
