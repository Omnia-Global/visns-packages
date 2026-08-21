<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use Illuminate\Support\Facades\DB;
use Visnsstudio\VisnsPackages\Models\VaultAccessLog;
use Visnsstudio\VisnsPackages\Models\VaultEntry;

/**
 * The two maintenance commands.
 *
 * `vault:reencrypt` is the second half of an APP_KEY rotation and the only part
 * of it this package can do; `vault:prune-log` is the broom for the one table
 * here that grows without limit.
 */
class VaultCommandsTest extends VaultTestCase
{
    public function test_reencrypt_rewrites_the_ciphertext_and_keeps_the_plaintext(): void
    {
        $admin = $this->admin();

        $entry = $this->entryFor($admin, [
            'password' => 'hunter2',
            'notes' => 'ring the desk on x304 first',
            'totp_secret' => self::SECRET,
        ]);

        $before = $this->rawRow($entry->id);

        $this->artisan('vault:reencrypt')
            ->expectsOutputToContain('Re-encrypted 1 vault entry.')
            ->assertExitCode(0);

        $after = $this->rawRow($entry->id);

        // Laravel's encrypter uses a random IV, so a genuine rewrite always
        // produces different bytes - which is what proves the row was written
        // rather than skipped by the dirty check.
        $this->assertNotSame($before->password, $after->password);
        $this->assertNotSame($before->notes, $after->notes);
        $this->assertNotSame($before->totp_secret, $after->totp_secret);

        $fresh = $entry->fresh();

        $this->assertSame('hunter2', $fresh->password);
        $this->assertSame('ring the desk on x304 first', $fresh->notes);
        $this->assertSame(self::SECRET, $fresh->totp_secret);
    }

    public function test_reencrypt_leaves_empty_secrets_null_and_does_not_touch_updated_at(): void
    {
        $admin = $this->admin();

        $entry = $this->entryFor($admin, [
            'password' => null,
            'notes' => null,
            'totp_secret' => null,
        ]);

        $updatedAt = $this->rawRow($entry->id)->updated_at;

        $this->artisan('vault:reencrypt')->assertExitCode(0);

        $after = $this->rawRow($entry->id);

        $this->assertNull($after->password);
        $this->assertNull($after->notes);
        $this->assertNull($after->totp_secret);

        // Re-encrypting is not an edit and must not look like one in a rotation
        // report.
        $this->assertSame($updatedAt, $after->updated_at);
    }

    public function test_reencrypt_covers_soft_deleted_entries(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);
        $entry->delete();

        $before = $this->rawRow($entry->id);

        $this->artisan('vault:reencrypt')
            ->expectsOutputToContain('Re-encrypted 1 vault entry.')
            ->assertExitCode(0);

        // A deleted entry left on the old key turns a restore into a decryption
        // failure months later.
        $this->assertNotSame($before->password, $this->rawRow($entry->id)->password);
    }

    public function test_reencrypt_on_an_empty_vault_says_so(): void
    {
        $this->artisan('vault:reencrypt')
            ->expectsOutputToContain('Re-encrypted 0 vault entries.')
            ->assertExitCode(0);
    }

    public function test_prune_log_deletes_only_rows_past_the_window(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->logAt($entry->id, $admin->id, now()->subDays(400));
        $this->logAt($entry->id, $admin->id, now()->subDays(366));
        $this->logAt($entry->id, $admin->id, now()->subDays(10));

        $this->artisan('vault:prune-log')
            ->expectsOutputToContain('Deleted 2 vault access log rows')
            ->assertExitCode(0);

        $this->assertSame(1, VaultAccessLog::count());
    }

    public function test_prune_log_takes_a_shorter_window(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->logAt($entry->id, $admin->id, now()->subDays(10));
        $this->logAt($entry->id, $admin->id, now()->subHours(2));

        $this->artisan('vault:prune-log', ['--days' => 7])
            ->expectsOutputToContain('Deleted 1 vault access log row')
            ->assertExitCode(0);

        $this->assertSame(1, VaultAccessLog::count());
    }

    public function test_prune_log_refuses_a_zero_day_window(): void
    {
        // "--days=0" reads like "delete everything" to whoever typed it.
        $this->artisan('vault:prune-log', ['--days' => 0])
            ->expectsOutputToContain('--days must be at least 1.')
            ->assertExitCode(1);
    }

    private function logAt(int $entryId, int $userId, $at): void
    {
        VaultAccessLog::create([
            'vault_entry_id' => $entryId,
            'user_id' => $userId,
            'action' => 'view',
            'ip' => '127.0.0.1',
            'created_at' => $at,
        ]);
    }

    private function rawRow(int $id): object
    {
        return DB::table((new VaultEntry())->getTable())->where('id', $id)->first();
    }
}
