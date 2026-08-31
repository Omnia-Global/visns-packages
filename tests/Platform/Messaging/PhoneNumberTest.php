<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use PHPUnit\Framework\Attributes\DataProvider;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Number normalisation.
 *
 * Worth this much attention because it is the module's whole routing rule: an
 * inbound message finds its line by an equality check against a stored E.164
 * number, so a spelling this class gets wrong is a client message that silently
 * lands in no inbox at all.
 */
class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function australianNumbers(): array
    {
        return [
            'mobile, spaced' => ['0412 345 678', '+61412345678'],
            'mobile, bare' => ['0412345678', '+61412345678'],
            'mobile, hyphenated' => ['0412-345-678', '+61412345678'],
            'mobile, already E.164' => ['+61412345678', '+61412345678'],
            'mobile, E.164 spaced' => ['+61 412 345 678', '+61412345678'],
            'mobile, country code no plus' => ['61412345678', '+61412345678'],
            'landline, bracketed area code' => ['(08) 9375 2549', '+61893752549'],
            'landline, spaced' => ['08 9375 2549', '+61893752549'],
            'international dial prefix' => ['0011 61 412 345 678', '+61412345678'],
            'a US number in E.164' => ['+14155552671', '+14155552671'],
            'local number with no area code' => ['9375 2549', null],
            'an extension' => ['304', null],
            'empty' => ['', null],
            'nonsense' => ['not a phone number', null],
        ];
    }

    #[DataProvider('australianNumbers')]
    public function test_it_normalises_the_shapes_people_actually_type(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toE164($input));
    }

    public function test_a_number_too_long_for_e164_is_refused(): void
    {
        $this->assertNull(PhoneNumber::toE164('+1234567890123456789'));
    }

    public function test_a_default_country_other_than_australia_is_honoured(): void
    {
        // 10 digits, no trunk prefix - the North American shape.
        $this->assertSame('+14155552671', PhoneNumber::toE164('415 555 2671', 'US'));

        // The same digits read as Australian are not a number we can send to.
        $this->assertNull(PhoneNumber::toE164('415 555 2671', 'AU'));
    }

    public function test_an_unknown_country_falls_back_to_australia(): void
    {
        $this->assertSame('+61412345678', PhoneNumber::toE164('0412 345 678', 'ZZ'));
    }

    public function test_it_renders_australian_numbers_the_way_a_person_reads_them(): void
    {
        $this->assertSame('0412 345 678', PhoneNumber::toLocal('+61412345678'));
        $this->assertSame('(08) 9375 2549', PhoneNumber::toLocal('+61893752549'));
    }

    public function test_a_foreign_number_is_left_in_e164_rather_than_guessed_at(): void
    {
        $this->assertSame('+14155552671', PhoneNumber::toLocal('+14155552671'));
        $this->assertSame('', PhoneNumber::toLocal(null));
    }

    public function test_two_spellings_of_one_number_match(): void
    {
        $this->assertTrue(PhoneNumber::matches('0412 345 678', '+61412345678'));
        $this->assertTrue(PhoneNumber::matches('61412345678', '(04) 1234 5678'));
        $this->assertFalse(PhoneNumber::matches('0412 345 678', '0412 345 679'));
        $this->assertFalse(PhoneNumber::matches('0412 345 678', ''));
    }

    public function test_two_unparseable_but_identical_strings_still_match(): void
    {
        // Neither side canonicalises; comparing the digits is the fallback, so
        // an extension typed twice is not reported as two different people.
        $this->assertTrue(PhoneNumber::matches('304', '#304'));
        $this->assertFalse(PhoneNumber::matches('304', '305'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function mobileOrNot(): array
    {
        return [
            'an Australian mobile' => ['+61412345678', true],
            'an Australian mobile on 045' => ['+61455123456', true],
            'a Perth landline' => ['+61893752549', false],
            'a Sydney landline' => ['+61298765432', false],
            'an Australian 1300 number' => ['+611300123456', false],
            'a US number, country unknown' => ['+14155552671', true],
            'a New Zealand mobile' => ['+64211234567', true],
            'not E.164 at all' => ['0412 345 678', false],
            'too short for E.164' => ['+123456', false],
            'too long for E.164' => ['+1234567890123456', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Which numbers an SMS can actually reach.
     *
     * The landline row is the one with a cost attached: it normalises cleanly,
     * so nothing upstream refuses it, and a message sent to it is billed and
     * recorded against the client without ever being read.
     */
    #[DataProvider('mobileOrNot')]
    public function test_it_knows_which_numbers_can_receive_a_text(string $e164, bool $expected): void
    {
        $this->assertSame($expected, PhoneNumber::isMobile($e164));
    }

    public function test_an_unknown_number_outside_australia_is_taken_on_trust(): void
    {
        // Telling a French mobile from a French landline needs metadata this
        // package does not carry; refusing every overseas number outright
        // would be the worse of the two mistakes.
        $this->assertTrue(PhoneNumber::isMobile('+33612345678'));
        $this->assertTrue(PhoneNumber::isMobile('+33145678901'));

        // Australia is the one plan it does know, so it is held to it.
        $this->assertFalse(PhoneNumber::isMobile('+61393752549'));
    }

    /*
    |--------------------------------------------------------------------------
    | Sender IDs
    |--------------------------------------------------------------------------
    */

    public static function senderIds(): array
    {
        return [
            'apples own sender id' => ['Apple', 'Apple'],
            'a bank' => ['ANZ', 'ANZ'],
            'a short code' => ['27311', '27311'],
            'a 1300 number no line can text' => ['1300123456', '1300123456'],
            'whitespace is collapsed, case is not' => ['  Apple   Pay ', 'Apple Pay'],
            'nothing at all' => ['', null],
            'blank' => ['   ', null],
            'punctuation is not a sender' => ['---', null],
            'e164 belongs to toE164, not here' => ['+61412345678', null],
            'wider than the column' => [str_repeat('A', 33), null],
            'a control character' => ["App\x00le", null],
        ];
    }

    #[DataProvider('senderIds')]
    public function test_it_reads_the_senders_that_are_not_numbers(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toSenderId($input));
    }

    /**
     * The two readers must never both claim the same value: `isSenderId` is the
     * discriminator the payload, the webhook and the compose box all branch on,
     * and one address answering yes to both would put a Send button on a thread
     * the endpoint refuses.
     */
    public function test_a_number_and_a_sender_id_are_never_the_same_thing(): void
    {
        foreach (['+61412345678', '0412 345 678', '61412345678', '0011 64 21234567'] as $number) {
            $e164 = PhoneNumber::toE164($number);

            $this->assertNotNull($e164, $number . ' should read as a number');
            $this->assertNull(PhoneNumber::toSenderId($e164), $e164 . ' should not read as a sender id');
            $this->assertFalse(PhoneNumber::isSenderId($e164), $e164 . ' is a number');
        }

        foreach (['Apple', 'ANZ', '27311'] as $senderId) {
            $this->assertNull(PhoneNumber::toE164($senderId), $senderId . ' should not read as a number');
            $this->assertTrue(PhoneNumber::isSenderId($senderId), $senderId . ' is a sender id');
        }
    }

    public function test_a_sender_id_is_never_a_mobile_and_is_left_alone_for_display(): void
    {
        $this->assertFalse(PhoneNumber::isMobile('Apple'));
        $this->assertSame('Apple', PhoneNumber::toLocal('Apple'));
    }

    public function test_a_null_is_not_a_mobile(): void
    {
        $this->assertFalse(PhoneNumber::isMobile(null));
    }
}
