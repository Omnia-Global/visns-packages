<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Visnsstudio\VisnsPackages\Support\SmsSegments;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The segment counter, on its own.
 *
 * Plain TestCase rather than MessagingTestCase: this is arithmetic over a
 * string and touches no table, no config and no transport - and a test that
 * built seven tables to count the characters in "Hello" would be saying
 * something untrue about what the class depends on.
 */
class SmsSegmentsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Which alphabet
    |--------------------------------------------------------------------------
    */

    public function test_ordinary_text_is_gsm7(): void
    {
        $this->assertTrue(SmsSegments::isGsm7('Your review is booked for Thursday at 2pm.'));
        $this->assertSame(SmsSegments::GSM7, SmsSegments::alphabet('Hello'));
    }

    public function test_the_gsm_alphabet_is_wider_than_ascii(): void
    {
        // The accented characters and the currency symbols really are in the
        // basic table, and a naive "is this ASCII" check would bill a French
        // client's name at twice the rate.
        foreach (['café', 'Öl', '£20', '¥500', 'Ça va', 'Straße'] as $text) {
            $this->assertTrue(SmsSegments::isGsm7($text), $text . ' should be GSM-7');
        }
    }

    public function test_a_curly_apostrophe_is_not(): void
    {
        // The single most expensive character in SMS, and the one every phone
        // keyboard and every copy of Word produces instead of '.
        $this->assertFalse(SmsSegments::isGsm7("It\u{2019}s ready"));
        $this->assertSame(SmsSegments::UCS2, SmsSegments::alphabet("It\u{2019}s ready"));
    }

    public function test_the_escape_table_is_deliberately_treated_as_non_gsm(): void
    {
        // These ARE in GSM-7, at two septets each. Rather than model that, they
        // are counted as UCS-2 - which over-counts. An over-count tells somebody
        // their message is dearer than it is; an under-count is a bill they did
        // not expect. See the class docblock.
        foreach (['{', '}', '[', ']', '~', '|', '^', '\\', '€'] as $character) {
            $this->assertFalse(
                SmsSegments::isGsm7('Hello ' . $character),
                $character . ' should be counted as UCS-2'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The boundaries
    |--------------------------------------------------------------------------
    */

    public function test_gsm7_boundaries(): void
    {
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 1)));
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 160)));

        // 161 characters is TWO segments of 153, not one of 160 plus one of 1:
        // concatenation spends seven septets per part on the header, so the
        // budget shrinks the moment a second part exists.
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 161)));
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 306)));
        $this->assertSame(3, SmsSegments::count(str_repeat('a', 307)));
    }

    public function test_ucs2_boundaries(): void
    {
        $curly = "\u{2019}";

        $this->assertSame(1, SmsSegments::count($curly . str_repeat('a', 69)));
        $this->assertSame(2, SmsSegments::count($curly . str_repeat('a', 70)));
        $this->assertSame(2, SmsSegments::count($curly . str_repeat('a', 133)));
        $this->assertSame(3, SmsSegments::count($curly . str_repeat('a', 134)));
    }

    public function test_one_wrong_character_triples_the_price_of_a_long_message(): void
    {
        $plain = str_repeat('a', 158);

        $this->assertSame(1, SmsSegments::count($plain));
        $this->assertSame(3, SmsSegments::count($plain . "\u{2019}"));
    }

    /*
    |--------------------------------------------------------------------------
    | The awkward cases
    |--------------------------------------------------------------------------
    */

    public function test_an_emoji_costs_two_units_not_one(): void
    {
        // Outside the basic multilingual plane: one character, two UTF-16 code
        // units, and the carrier budgets in units. mb_strlen would answer 35 and
        // be wrong by half.
        $emoji = "\u{1F600}";

        $this->assertSame(1, SmsSegments::count(str_repeat($emoji, 35)));
        $this->assertSame(2, SmsSegments::count(str_repeat($emoji, 36)));
    }

    public function test_an_empty_body_is_one_segment_not_none(): void
    {
        // Nothing here is ever asked about a body nobody intends to send, and
        // answering 0 would make a preview read as though the message were free.
        $this->assertSame(1, SmsSegments::count(''));
    }

    public function test_a_newline_is_gsm7(): void
    {
        // Which matters: the campaign footer is appended after one.
        $this->assertTrue(SmsSegments::isGsm7("Hello\nReply STOP to opt out."));
    }
}
