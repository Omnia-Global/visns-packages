<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use Visnsstudio\VisnsPackages\Models\VaultEntry;

/**
 * The list and the CRUD endpoints: what a payload may contain, who may see
 * what, and who may change it.
 */
class VaultEntriesTest extends VaultEntriesTestSupport
{
    /* ----------------------------------------------------------------- */
    /* What leaves the server                                            */
    /* ----------------------------------------------------------------- */

    public function test_the_list_never_carries_a_secret(): void
    {
        $admin = $this->admin();

        $this->entryFor($admin, [
            'password' => 'hunter2',
            'notes' => 'the recovery answers are in the safe',
            'totp_secret' => self::SECRET,
        ]);

        $response = $this->actingAs($admin)->getJson('/ajax/vault')->assertOk();

        $row = $response->json('data.0');

        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('totp_secret', $row);
        $this->assertArrayNotHasKey('notes', $row);

        // Assert on the wire itself as well: a key can be absent from the
        // decoded array and still be sitting in the body under a nested model.
        $body = $response->getContent();

        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString('recovery answers', $body);
        $this->assertStringNotContainsString('"password"', $body);
        $this->assertStringNotContainsString('"totp_secret"', $body);
        $this->assertStringNotContainsString('"notes"', $body);
    }

    public function test_the_list_row_carries_the_fields_the_front_end_needs(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['tags' => ['prod', 'network']]);

        $this->actingAs($admin)
            ->getJson('/ajax/vault')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.title', 'Router admin')
            ->assertJsonPath('data.0.username', 'admin')
            ->assertJsonPath('data.0.has_totp', false)
            ->assertJsonPath('data.0.visibility', 'shared')
            ->assertJsonPath('data.0.tags', ['prod', 'network'])
            ->assertJsonPath('data.0.owner_user_id', $admin->id)
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.deleted_at', null);
    }

    public function test_has_totp_is_true_once_a_seed_is_stored(): void
    {
        $admin = $this->admin();
        $this->entryFor($admin, ['totp_secret' => self::SECRET]);

        $this->actingAs($admin)
            ->getJson('/ajax/vault')
            ->assertOk()
            ->assertJsonPath('data.0.has_totp', true);
    }

    public function test_the_detail_endpoint_adds_notes_but_still_no_password(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, [
            'notes' => 'ring the desk on x304 first',
            'password' => 'hunter2',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertOk()
            ->assertJsonPath('notes', 'ring the desk on x304 first')
            ->assertJsonPath('totp_digits', 6)
            ->assertJsonPath('totp_period', 30)
            ->assertJsonPath('updated_by_user_id', $admin->id)
            // The view this request just recorded is included: the log write
            // happens before the payload is built, deliberately.
            ->assertJsonPath('access_log_count', 1);

        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    /* ----------------------------------------------------------------- */
    /* Visibility                                                        */
    /* ----------------------------------------------------------------- */

    public function test_a_private_entry_is_invisible_to_everybody_else(): void
    {
        $owner = $this->member();
        $other = $this->member();

        $entry = $this->entryFor($owner, [
            'visibility' => 'private',
            'title' => 'Personal banking',
        ]);

        $this->actingAs($other)
            ->getJson('/ajax/vault')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A 404 rather than a 403: a 403 answers "does an entry with that id
        // exist" for anyone who cares to ask.
        $this->actingAs($other)
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertNotFound();
    }

    public function test_a_private_entry_is_hidden_from_an_administrator_too(): void
    {
        // Manage is a grant over shared credentials and the audit trail, not a
        // licence to read somebody's private entries.
        $owner = $this->member();
        $entry = $this->entryFor($owner, ['visibility' => 'private']);

        $this->actingAs($this->admin())
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertNotFound();
    }

    public function test_a_shared_entry_is_visible_to_every_member(): void
    {
        $owner = $this->admin();
        $entry = $this->entryFor($owner, ['visibility' => 'shared']);

        $this->actingAs($this->member())
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertOk()
            ->assertJsonPath('id', $entry->id)
            // Visible, but not theirs to change.
            ->assertJsonPath('can_edit', false);
    }

    /* ----------------------------------------------------------------- */
    /* Creating                                                          */
    /* ----------------------------------------------------------------- */

    public function test_creating_a_shared_entry_requires_the_manage_permission(): void
    {
        $this->actingAs($this->member())
            ->postJson('/ajax/vault', [
                'title' => 'Payroll portal',
                'visibility' => 'shared',
            ])
            ->assertForbidden();

        $this->assertSame(0, VaultEntry::count());
    }

    public function test_a_member_can_create_a_private_entry(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->postJson('/ajax/vault', [
                'title' => 'Personal banking',
                'username' => 'me',
                'password' => 'hunter2',
                'visibility' => 'private',
                'tags' => ['personal', ' personal ', ''],
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Personal banking')
            ->assertJsonPath('visibility', 'private')
            ->assertJsonPath('owner_user_id', $member->id)
            // Trimmed, de-duplicated, blanks dropped.
            ->assertJsonPath('tags', ['personal']);

        $entry = VaultEntry::first();

        $this->assertSame('hunter2', $entry->password);
        $this->assertNotNull($entry->password_rotated_at);
    }

    public function test_an_administrator_can_create_a_shared_entry(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/ajax/vault', [
                'title' => 'Payroll portal',
                'visibility' => 'shared',
            ])
            ->assertCreated()
            ->assertJsonPath('visibility', 'shared');
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/ajax/vault', [
            'title' => 'Payroll portal',
            'password' => 'hunter2',
            'notes' => 'nothing to see',
            'visibility' => 'shared',
        ])->assertCreated();

        $raw = $this->rawRow(VaultEntry::first()->id);

        $this->assertNotSame('hunter2', $raw->password);
        $this->assertNotSame('nothing to see', $raw->notes);
        $this->assertSame('hunter2', VaultEntry::first()->password);
    }

    public function test_a_title_is_required(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/ajax/vault', ['visibility' => 'shared'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_a_url_without_a_scheme_is_accepted_and_a_javascript_url_is_not(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/ajax/vault', [
                'title' => 'Intranet',
                'url' => 'intranet.example.test/login',
                'visibility' => 'shared',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/ajax/vault', [
                'title' => 'Nope',
                'url' => 'javascript:alert(1)',
                'visibility' => 'shared',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    /* ----------------------------------------------------------------- */
    /* Updating                                                          */
    /* ----------------------------------------------------------------- */

    public function test_an_owner_can_edit_their_own_private_entry(): void
    {
        $member = $this->member();
        $entry = $this->entryFor($member, ['visibility' => 'private']);

        $this->actingAs($member)
            ->putJson('/ajax/vault/' . $entry->id, [
                'title' => 'Renamed',
                'username' => 'root',
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed')
            ->assertJsonPath('username', 'root');
    }

    public function test_a_non_owner_without_manage_gets_a_404_on_update(): void
    {
        $owner = $this->member();
        $other = $this->member();

        $entry = $this->entryFor($owner, ['visibility' => 'private']);

        $this->actingAs($other)
            ->putJson('/ajax/vault/' . $entry->id, ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Router admin', $entry->fresh()->title);
    }

    public function test_editing_a_shared_entry_requires_manage_even_for_its_owner(): void
    {
        $member = $this->member();
        $entry = $this->entryFor($member, ['visibility' => 'shared']);

        $this->actingAs($member)
            ->putJson('/ajax/vault/' . $entry->id, ['title' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_publishing_a_private_entry_requires_manage(): void
    {
        $member = $this->member();
        $entry = $this->entryFor($member, ['visibility' => 'private']);

        $this->actingAs($member)
            ->putJson('/ajax/vault/' . $entry->id, [
                'title' => 'Router admin',
                'visibility' => 'shared',
            ])
            ->assertForbidden();

        $this->assertSame('private', $entry->fresh()->visibility);
    }

    public function test_an_absent_password_key_leaves_the_stored_password_alone(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);
        $rotated = $entry->password_rotated_at;

        $this->actingAs($admin)
            ->putJson('/ajax/vault/' . $entry->id, ['title' => 'Renamed'])
            ->assertOk();

        $fresh = $entry->fresh();

        $this->assertSame('hunter2', $fresh->password);
        $this->assertSame(
            $rotated->toDateTimeString(),
            $fresh->password_rotated_at->toDateTimeString()
        );
    }

    public function test_an_empty_password_clears_it(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, ['password' => 'hunter2']);

        $this->actingAs($admin)
            ->putJson('/ajax/vault/' . $entry->id, [
                'title' => 'Router admin',
                'password' => '',
            ])
            ->assertOk();

        $fresh = $entry->fresh();

        $this->assertNull($fresh->password);
        $this->assertNull($fresh->password_rotated_at);
    }

    public function test_a_new_password_rotates_the_timestamp(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin, [
            'password' => 'hunter2',
            'password_rotated_at' => now()->subYear(),
        ]);

        $this->travelTo(now()->addSeconds(5));

        $this->actingAs($admin)
            ->putJson('/ajax/vault/' . $entry->id, [
                'title' => 'Router admin',
                'password' => 'correct-horse-battery',
            ])
            ->assertOk();

        $fresh = $entry->fresh();

        $this->assertSame('correct-horse-battery', $fresh->password);
        $this->assertTrue($fresh->password_rotated_at->isAfter(now()->subMinute()));
    }

    /* ----------------------------------------------------------------- */
    /* Deleting and restoring                                            */
    /* ----------------------------------------------------------------- */

    public function test_an_owner_can_delete_and_an_administrator_can_restore(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $entry = $this->entryFor($member, ['visibility' => 'private']);

        $this->actingAs($member)
            ->deleteJson('/ajax/vault/' . $entry->id)
            ->assertNoContent();

        $this->assertNotNull(VaultEntry::withTrashed()->find($entry->id)->deleted_at);

        // Gone, not merely hidden, for the ordinary endpoints.
        $this->actingAs($member)
            ->getJson('/ajax/vault/' . $entry->id)
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson('/ajax/vault/' . $entry->id . '/restore')
            ->assertOk()
            ->assertJsonPath('deleted_at', null);

        $this->assertNull(VaultEntry::find($entry->id)->deleted_at);
    }

    public function test_a_member_cannot_restore(): void
    {
        $member = $this->member();
        $entry = $this->entryFor($member, ['visibility' => 'private']);
        $entry->delete();

        $this->actingAs($member)
            ->postJson('/ajax/vault/' . $entry->id . '/restore')
            ->assertForbidden();
    }

    public function test_deleted_entries_are_listed_only_for_an_administrator_who_asks(): void
    {
        $admin = $this->admin();
        $entry = $this->entryFor($admin);
        $entry->delete();

        $this->actingAs($admin)
            ->getJson('/ajax/vault')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin)
            ->getJson('/ajax/vault?include_deleted=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry->id);

        // A member asking for the same thing is simply not given it.
        $this->actingAs($this->member())
            ->getJson('/ajax/vault?include_deleted=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ----------------------------------------------------------------- */
    /* Searching, sorting, paging                                        */
    /* ----------------------------------------------------------------- */

    public function test_search_matches_a_tag(): void
    {
        $admin = $this->admin();

        $this->entryFor($admin, ['title' => 'Firewall', 'tags' => ['network', 'prod']]);
        $this->entryFor($admin, ['title' => 'Mailer', 'tags' => ['marketing']]);

        $this->actingAs($admin)
            ->getJson('/ajax/vault?search=network')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Firewall');
    }

    public function test_search_matches_a_title_or_username(): void
    {
        $admin = $this->admin();

        $this->entryFor($admin, ['title' => 'Firewall', 'username' => 'netops']);
        $this->entryFor($admin, ['title' => 'Mailer', 'username' => 'postmaster']);

        $this->actingAs($admin)
            ->getJson('/ajax/vault?search=postmaster')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mailer');
    }

    public function test_search_never_reaches_a_secret_column(): void
    {
        $admin = $this->admin();

        // Even asked to, in config: the column whitelist is what decides.
        config(['visns-packages.vault.search_columns' => ['title', 'password', 'notes']]);

        $this->entryFor($admin, ['title' => 'Firewall', 'password' => 'zebra-secret']);

        $this->actingAs($admin)
            ->getJson('/ajax/vault?search=zebra-secret')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_sort_column_falls_back_to_title(): void
    {
        $admin = $this->admin();

        $this->entryFor($admin, ['title' => 'Zebra']);
        $this->entryFor($admin, ['title' => 'Aardvark']);

        $this->actingAs($admin)
            ->getJson('/ajax/vault?sort=password&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Aardvark')
            ->assertJsonPath('data.1.title', 'Zebra');
    }

    public function test_a_whitelisted_sort_column_is_honoured_in_both_directions(): void
    {
        $admin = $this->admin();

        $this->entryFor($admin, ['title' => 'Zebra']);
        $this->entryFor($admin, ['title' => 'Aardvark']);

        $this->actingAs($admin)
            ->getJson('/ajax/vault?sort=title&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Zebra');
    }

    public function test_per_page_is_capped(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 3; $i++) {
            $this->entryFor($admin, ['title' => 'Entry ' . $i]);
        }

        $this->actingAs($admin)
            ->getJson('/ajax/vault?per_page=100000')
            ->assertOk()
            ->assertJsonPath('per_page', 100);

        $this->actingAs($admin)
            ->getJson('/ajax/vault?per_page=2')
            ->assertOk()
            ->assertJsonPath('per_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3);
    }
}
