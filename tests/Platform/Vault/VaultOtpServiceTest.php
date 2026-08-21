<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use InvalidArgumentException;
use Visnsstudio\VisnsPackages\Models\VaultEntry;
use Visnsstudio\VisnsPackages\Services\VaultOtpService;

/**
 * Turning whatever a provider hands a user into a seed this module can use.
 *
 * The `otpauth://` form is the one that matters: it is what sits behind every
 * provider's QR code, and it is the only form that carries the digits, period
 * and algorithm. A module that accepted only the bare secret would store an
 * 8-digit entry as a 6-digit one and generate confidently wrong codes forever.
 */
class VaultOtpServiceTest extends VaultTestCase
{
    private function service(): VaultOtpService
    {
        return new VaultOtpService();
    }

    public function test_a_bare_secret_takes_the_totp_defaults(): void
    {
        $this->assertSame([
            'secret' => self::SECRET,
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'sha1',
        ], $this->service()->normaliseSecret(self::SECRET));
    }

    public function test_the_formatting_authenticator_apps_add_is_stripped(): void
    {
        $pretty = strtolower(implode(' ', str_split(self::SECRET, 4))) . '==';

        $this->assertSame(
            self::SECRET,
            $this->service()->normaliseSecret($pretty)['secret']
        );
    }

    public function test_an_otpauth_uri_carries_its_parameters_across(): void
    {
        $uri = 'otpauth://totp/Acme:me@acme.test?secret=' . self::SECRET
            . '&digits=8&period=60&algorithm=SHA256&issuer=Acme';

        $this->assertSame([
            'secret' => self::SECRET,
            'digits' => 8,
            'period' => 60,
            'algorithm' => 'sha256',
        ], $this->service()->normaliseSecret($uri));
    }

    public function test_an_otpauth_uri_without_parameters_takes_the_defaults(): void
    {
        $uri = 'otpauth://totp/Acme:me@acme.test?secret=' . self::SECRET;

        $this->assertSame(
            ['secret' => self::SECRET, 'digits' => 6, 'period' => 30, 'algorithm' => 'sha1'],
            $this->service()->normaliseSecret($uri)
        );
    }

    public function test_a_counter_based_hotp_uri_is_refused(): void
    {
        // HOTP cannot be shown as a rotating code; storing one would be a
        // promise the module cannot keep.
        $this->expectException(InvalidArgumentException::class);

        $this->service()->normaliseSecret(
            'otpauth://hotp/Acme?secret=' . self::SECRET . '&counter=1'
        );
    }

    public function test_a_non_base32_secret_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->normaliseSecret('not-a-secret!!!!!!!!');
    }

    public function test_a_short_secret_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->normaliseSecret('JBSWY3DP');
    }

    public function test_an_unsupported_digit_count_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->normaliseSecret(
            'otpauth://totp/Acme?secret=' . self::SECRET . '&digits=7'
        );
    }

    public function test_an_out_of_range_period_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->normaliseSecret(
            'otpauth://totp/Acme?secret=' . self::SECRET . '&period=600'
        );
    }

    public function test_an_unsupported_algorithm_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->normaliseSecret(
            'otpauth://totp/Acme?secret=' . self::SECRET . '&algorithm=MD5'
        );
    }

    public function test_a_secret_is_proved_by_generating_a_code_not_by_its_shape(): void
    {
        $normalised = $this->service()->normaliseSecret(self::SECRET);

        // No exception is the assertion; validateSecret() generates a real code.
        $this->service()->validateSecret($normalised);

        $this->assertTrue(true);
    }

    public function test_an_eight_digit_entry_produces_an_eight_digit_code(): void
    {
        $entry = new VaultEntry([
            'title' => 'Eight digits',
            'totp_secret' => self::SECRET,
            'totp_digits' => 8,
            'totp_period' => 60,
            'totp_algorithm' => 'sha256',
        ]);

        $code = $this->service()->currentCode($entry);

        $this->assertSame(8, strlen($code['code']));
        $this->assertSame(60, $code['period']);
        $this->assertGreaterThan(0, $code['expires_in']);
        $this->assertLessThanOrEqual(60, $code['expires_in']);
    }

    public function test_the_expiry_counts_down_to_the_period_boundary(): void
    {
        $entry = new VaultEntry([
            'title' => 'Router',
            'totp_secret' => self::SECRET,
            'totp_digits' => 6,
            'totp_period' => 30,
            'totp_algorithm' => 'sha1',
        ]);

        // One second before a boundary: a UI told it had a full period of
        // runway here would have people typing dead codes.
        $this->travelTo(now()->setTimestamp(1700000009));

        $this->assertSame(1, $this->service()->currentCode($entry)['expires_in']);
    }

    public function test_an_entry_with_no_seed_cannot_produce_a_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->currentCode(new VaultEntry(['title' => 'No seed']));
    }

    public function test_an_invalid_secret_is_a_validation_error_on_the_endpoint(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/ajax/vault', [
                'title' => 'Broken',
                'visibility' => 'shared',
                'totp_secret' => 'definitely not base32 !!!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('totp_secret');
    }

    public function test_an_otpauth_uri_pasted_into_the_endpoint_is_stored_normalised(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/ajax/vault', [
                'title' => 'Payroll portal',
                'visibility' => 'shared',
                'totp_secret' => 'otpauth://totp/Acme:me@acme.test?secret=' . self::SECRET
                    . '&digits=8&period=60&algorithm=SHA256',
            ])
            ->assertCreated()
            ->assertJsonPath('has_totp', true)
            ->assertJsonPath('totp_digits', 8)
            ->assertJsonPath('totp_period', 60);

        $entry = VaultEntry::first();

        $this->assertSame(self::SECRET, $entry->totp_secret);
        $this->assertSame('sha256', $entry->totp_algorithm);
    }

    public function test_clearing_the_seed_resets_the_parameters_with_it(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, [
            'totp_secret' => self::SECRET,
            'totp_digits' => 8,
            'totp_period' => 60,
            'totp_algorithm' => 'sha256',
        ]);

        $this->actingAs($admin)
            ->putJson('/ajax/vault/' . $entry->id, [
                'title' => 'Router admin',
                'totp_secret' => null,
            ])
            ->assertOk()
            ->assertJsonPath('has_totp', false)
            ->assertJsonPath('totp_digits', 6)
            ->assertJsonPath('totp_period', 30);

        // Stale parameters left behind on a re-seeded entry would generate
        // confidently wrong codes.
        $this->assertSame('sha1', $entry->fresh()->totp_algorithm);
    }

    public function test_an_absent_seed_key_leaves_the_stored_seed_alone(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['totp_secret' => self::SECRET]);

        $this->actingAs($admin)
            ->putJson('/ajax/vault/' . $entry->id, ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('has_totp', true);

        $this->assertSame(self::SECRET, $entry->fresh()->totp_secret);
    }
}
