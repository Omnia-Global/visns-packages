<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use Visnsstudio\VisnsPackages\Models\VaultAccessLog;

/**
 * Reading the access log.
 *
 * Administrators only, and deliberately so: the log answers "who has been
 * looking at what", which is a question about people rather than about
 * credentials.
 */
class VaultAccessLogTest extends VaultTestCase
{
    public function test_a_member_cannot_read_the_global_log(): void
    {
        $this->actingAs($this->member())
            ->getJson('/ajax/vault/log')
            ->assertForbidden();
    }

    public function test_a_member_cannot_read_one_entrys_log(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->actingAs($this->member())
            ->getJson('/ajax/vault/' . $entry->id . '/log')
            ->assertForbidden();
    }

    public function test_an_entrys_log_is_newest_first_and_names_the_reader(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->actingAs($admin)->getJson('/ajax/vault/' . $entry->id);
        $this->travel(1)->minutes();
        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/log', ['action' => 'copy_username']);

        $this->actingAs($admin)
            ->getJson('/ajax/vault/' . $entry->id . '/log')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'copy_username')
            ->assertJsonPath('data.1.action', 'view')
            ->assertJsonPath('data.0.user.id', $admin->id)
            ->assertJsonPath('data.0.user.name', $admin->name)
            ->assertJsonStructure(['data' => [['id', 'action', 'ip', 'created_at', 'user']]]);
    }

    public function test_the_global_log_names_the_entry_each_row_belongs_to(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['title' => 'Payroll portal']);

        $this->actingAs($admin)->getJson('/ajax/vault/' . $entry->id);

        $this->actingAs($admin)
            ->getJson('/ajax/vault/log')
            ->assertOk()
            ->assertJsonPath('data.0.entry.id', $entry->id)
            ->assertJsonPath('data.0.entry.title', 'Payroll portal');
    }

    public function test_the_global_log_survives_the_entry_being_deleted(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['title' => 'Payroll portal']);

        $this->actingAs($admin)->getJson('/ajax/vault/' . $entry->id);

        $entry->delete();

        // The record that somebody read a credential has to outlive the
        // credential, or removing an entry becomes a way to erase the trail.
        $this->actingAs($admin)
            ->getJson('/ajax/vault/log')
            ->assertOk()
            ->assertJsonPath('data.0.entry.title', 'Payroll portal');
    }

    public function test_the_global_log_filters_by_user_and_action(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $entry = $this->entryFor($admin);

        $this->actingAs($admin)->getJson('/ajax/vault/' . $entry->id);
        $this->actingAs($other)->getJson('/ajax/vault/' . $entry->id);
        $this->actingAs($other)
            ->postJson('/ajax/vault/' . $entry->id . '/log', ['action' => 'copy_username']);

        $this->actingAs($admin)
            ->getJson('/ajax/vault/log?user_id=' . $other->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)
            ->getJson('/ajax/vault/log?action=copy_username')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $other->id);
    }

    public function test_a_failed_confirmation_appears_in_the_global_log_with_no_entry(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/ajax/vault/confirm-password', ['password' => 'nope'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson('/ajax/vault/log?action=confirm_failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entry', null);
    }

    public function test_the_log_for_an_unknown_entry_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/ajax/vault/9999/log')
            ->assertNotFound();
    }

    public function test_a_log_row_records_the_user_agent_truncated(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);

        $this->actingAs($admin)
            ->withHeader('User-Agent', str_repeat('a', 600))
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertOk();

        // Truncated rather than rejected: an absurd user agent must not cost
        // the caller its request.
        $this->assertSame(255, strlen(VaultAccessLog::first()->user_agent));
    }
}
