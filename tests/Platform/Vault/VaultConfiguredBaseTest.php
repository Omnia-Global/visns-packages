<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

/**
 * Every endpoint hangs off one configurable base, so an application can move the
 * whole module with a single config key and nothing is left behind at the old
 * address.
 */
class VaultConfiguredBaseTest extends VaultTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.vault.uris.base', 'ajax/secrets');
    }

    public function test_the_module_answers_at_the_configured_base(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/ajax/secrets')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_nothing_is_left_at_the_default_base(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/ajax/vault')
            ->assertNotFound();
    }
}
