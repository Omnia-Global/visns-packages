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
}
