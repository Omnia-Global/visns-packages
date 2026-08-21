<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use PragmaRX\Google2FA\Google2FA;
use Visnsstudio\VisnsPackages\Models\VaultAccessLog;

/**
 * Getting a secret out: the password confirmation gate, the reveal, the one-time
 * code, and the log rows all three leave behind.
 *
 * The confirmation is the control this module leans on hardest. Everything else
 * here - the rate limits, the no-store headers, the log - is defence in depth
 * behind it.
 */
class VaultSecretsTest extends VaultTestCase
{
    /* ----------------------------------------------------------------- */
    /* Confirming                                                        */
    /* ----------------------------------------------------------------- */

    public function test_the_right_password_confirms_and_answers_204(): void
    {
        $this->actingAs($this->member())
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse'])
            ->assertNoContent();

        $this->assertNotNull(session('visns.vault.confirmed_at'));
    }

    public function test_the_wrong_password_is_refused_and_logged(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That password is not correct.');

        $log = VaultAccessLog::first();

        $this->assertNotNull($log);
        $this->assertSame('confirm_failed', $log->action);
        $this->assertNull($log->vault_entry_id);
        $this->assertSame($member->id, $log->user_id);

        $this->assertNull(session('visns.vault.confirmed_at'));
    }

    public function test_a_password_is_required(): void
    {
        $this->actingAs($this->member())
            ->postJson('/ajax/vault/confirm-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_confirming_is_rate_limited(): void
    {
        $member = $this->member();

        // Five attempts a minute, then the door shuts - it is a password check.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($member)
                ->postJson('/ajax/vault/confirm-password', ['password' => 'nope'])
                ->assertStatus(422);
        }

        $this->actingAs($member)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'nope'])
            ->assertStatus(429);
    }

    /* ----------------------------------------------------------------- */
    /* Revealing                                                         */
    /* ----------------------------------------------------------------- */

    public function test_reveal_is_locked_until_the_password_is_confirmed(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertStatus(423)
            ->assertJsonPath('reason', 'password_confirmation_required')
            ->assertJsonPath('ttl_minutes', 10);

        $this->assertSame(0, VaultAccessLog::where('action', 'reveal_password')->count());
    }

    public function test_reveal_skips_the_confirmation_when_the_consumer_turns_it_off(): void
    {
        config(['visns-packages.vault.require_password_confirmation' => false]);

        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertOk()
            ->assertJsonPath('password', 'hunter2');

        $this->assertSame(1, VaultAccessLog::where('action', 'reveal_password')->count());
    }

    public function test_reveal_returns_the_password_once_confirmed(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse'])
            ->assertNoContent();

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertOk()
            ->assertJsonPath('password', 'hunter2');
    }

    public function test_reveal_is_never_cached(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse']);

        $response = $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertOk();

        // A password sitting in a proxy or a bfcache outlives every other
        // control in this module.
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
    }

    public function test_reveal_answers_null_when_no_password_is_stored(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => null]);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertOk()
            ->assertJsonPath('password', null);
    }

    public function test_reveal_is_logged_with_the_caller_and_their_address(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertOk();

        $log = VaultAccessLog::where('action', 'reveal_password')->first();

        $this->assertNotNull($log);
        $this->assertSame($entry->id, $log->vault_entry_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertNotNull($log->ip);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_confirmation_expires(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse'])
            ->assertNoContent();

        // Just inside the window.
        $this->travel(9)->minutes();

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertOk();

        // And past it.
        $this->travel(2)->minutes();

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertStatus(423);
    }

    public function test_a_confirmation_does_not_unlock_an_entry_you_cannot_see(): void
    {
        $owner = $this->member();
        $other = $this->member();

        $entry = $this->entryFor($owner, [
            'visibility' => 'private',
            'password' => 'hunter2',
        ]);

        $this->actingAs($other)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse']);

        $this->actingAs($other)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertNotFound();
    }

    /* ----------------------------------------------------------------- */
    /* One-time codes                                                    */
    /* ----------------------------------------------------------------- */

    public function test_the_code_matches_the_reference_implementation_at_a_frozen_time(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['totp_secret' => self::SECRET]);

        // A timestamp 20 seconds into a 30-second window, so the expiry is a
        // number rather than a coin toss.
        $this->travelTo(now()->setTimestamp(1700000000));

        $response = $this->actingAs($admin)
            ->getJson('/ajax/vault/' . $entry->id . '/otp')
            ->assertOk();

        $google2fa = new Google2FA();

        // oathTotp with an explicit counter, not getCurrentOtp(): the library's
        // own clock reads microtime() and ignores a frozen Carbon entirely.
        // This is the same arithmetic getCurrentOtp() does, at the frozen time.
        $expected = $google2fa->oathTotp(self::SECRET, intdiv(1700000000, 30));

        $response->assertJsonPath('code', $expected)
            ->assertJsonPath('period', 30)
            ->assertJsonPath('expires_in', 30 - (1700000000 % 30));
    }

    public function test_the_code_response_is_never_cached_and_is_logged(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['totp_secret' => self::SECRET]);

        $response = $this->actingAs($admin)
            ->getJson('/ajax/vault/' . $entry->id . '/otp')
            ->assertOk();

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );

        $this->assertSame(1, VaultAccessLog::where('action', 'otp')->count());
    }

    public function test_an_entry_without_a_seed_has_no_code(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['totp_secret' => null]);

        $this->actingAs($admin)
            ->getJson('/ajax/vault/' . $entry->id . '/otp')
            ->assertNotFound();
    }

    public function test_a_code_is_not_available_for_an_entry_you_cannot_see(): void
    {
        $owner = $this->member();
        $entry = $this->entryFor($owner, [
            'visibility' => 'private',
            'totp_secret' => self::SECRET,
        ]);

        $this->actingAs($this->member())
            ->getJson('/ajax/vault/' . $entry->id . '/otp')
            ->assertNotFound();
    }

    /* ----------------------------------------------------------------- */
    /* Client-reported actions                                           */
    /* ----------------------------------------------------------------- */

    public function test_a_browser_can_report_copying_a_username(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/log', ['action' => 'copy_username'])
            ->assertNoContent();

        $this->assertSame(1, VaultAccessLog::where('action', 'copy_username')->count());
    }

    public function test_a_browser_cannot_write_an_arbitrary_action(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        // An audit trail a client can write free text into is not an audit
        // trail.
        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/log', ['action' => 'reveal_password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertSame(0, VaultAccessLog::count());
    }
}
