<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

/**
 * With the module disabled - which is how it ships - the package registers no
 * vault routes at all.
 *
 * This is the test that matters most on upgrade day: this package is installed
 * into applications that never asked for a credential store, and a new release
 * must not quietly open one on them. A 404 here means the endpoint does not
 * exist, not that it exists and refused.
 */
class VaultDisabledTest extends VaultTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // Back to the shipped default, after VaultTestCase turned it on.
        $app['config']->set('visns-packages.vault.enabled', false);
    }

    public function test_the_list_endpoint_does_not_exist(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/ajax/vault')
            ->assertNotFound();
    }

    public function test_the_write_endpoints_do_not_exist(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/ajax/vault', ['title' => 'Anything'])
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'correct-horse'])
            ->assertNotFound();
    }

    public function test_the_secret_endpoints_do_not_exist(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/reveal')
            ->assertNotFound();

        $this->actingAs($admin)
            ->getJson('/ajax/vault/' . $entry->id . '/otp')
            ->assertNotFound();
    }
}
