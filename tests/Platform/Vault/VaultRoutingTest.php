<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use Visnsstudio\VisnsPackages\Middleware\EnsureVaultPasswordConfirmed;

/**
 * The module is gated when it is on. (That it registers nothing at all while it
 * is off is VaultDisabledTest's job - that needs a container booted with the
 * module disabled, which is a whole test class, not a config poke.)
 */
class VaultRoutingTest extends VaultTestCase
{
    public function test_a_user_without_the_access_permission_is_refused(): void
    {
        $nobody = $this->staffWith();

        $this->actingAs($nobody)->getJson('/ajax/vault')->assertForbidden();
    }

    public function test_a_user_without_the_access_permission_cannot_reach_a_single_entry(): void
    {
        $owner = $this->admin();
        $entry = $this->entryFor($owner);

        $this->actingAs($this->staffWith())
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertForbidden();
    }

    public function test_the_confirmation_middleware_alias_is_registered(): void
    {
        $this->assertSame(
            EnsureVaultPasswordConfirmed::class,
            $this->app['router']->getMiddleware()['vault.confirmed']
        );
    }

    /**
     * A literal segment must not be swallowed by the `{id}` route - without the
     * numeric constraint, `GET /ajax/vault/log` resolves to "show me the entry
     * called log" and the administrator's log endpoint 404s forever.
     */
    public function test_the_log_endpoint_is_not_shadowed_by_the_show_route(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/ajax/vault/log')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_a_non_numeric_entry_id_does_not_resolve(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/ajax/vault/not-an-id')
            ->assertNotFound();
    }
}
