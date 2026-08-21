<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Models\VaultEntry;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Shared harness for the vault suite: the module turned on, its two tables
 * built from the shipped migrations, and a way to mint staff holding exactly the
 * permissions a test cares about.
 *
 * The migrations are run rather than restated because they ARE the schema this
 * module ships - a test against a hand-written table would prove nothing about
 * what an application actually gets.
 */
abstract class VaultTestCase extends TestCase
{
    /** A 32-character base32 secret: valid under Google Authenticator's own
     *  power-of-two length rule, so a plain Google2FA can verify our output. */
    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.vault.enabled', true);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration(
            '2026_08_21_090000_create_vault_entries_table.php'
        );
        $this->runPackageMigration(
            '2026_08_21_090100_create_vault_access_logs_table.php'
        );
    }

    protected function staffWith(string ...$permissions): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'name' => 'Staff ' . $seq,
            'firstname' => 'Staff',
            'surname' => (string) $seq,
            'email' => 'staff' . $seq . '@example.test',
            'password' => Hash::make('correct-horse'),
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        return $user;
    }

    /** Somebody who may use the vault but administers nothing. */
    protected function member(): User
    {
        return $this->staffWith('Vault Access');
    }

    /** Somebody holding the administrative grant as well. */
    protected function admin(): User
    {
        return $this->staffWith('Vault Access', 'Vault Manage');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function entryFor(User $owner, array $attributes = []): VaultEntry
    {
        return VaultEntry::create(array_merge([
            'title' => 'Router admin',
            'username' => 'admin',
            'url' => 'https://router.example.test',
            'password' => 'hunter2',
            'visibility' => 'shared',
            'owner_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'password_rotated_at' => now(),
        ], $attributes));
    }
}
