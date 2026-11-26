<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\EmailCampaigns;

use Visnsstudio\VisnsPackages\Models\EmailList;
use Visnsstudio\VisnsPackages\Tests\Fixtures\EmailCampaigns\FakeContactSource;
use Visnsstudio\VisnsPackages\Tests\Fixtures\EmailCampaigns\FakePickingContactSource;

/** Lists with people picked one by one from the contact source (EmailContactPicker). */
class EmailListPeopleTest extends EmailCampaignTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['visns-packages.email_campaigns.contact_source' => FakePickingContactSource::class]);
    }

    private function emails(EmailList $list): array
    {
        return $list->members()->where('status', '!=', 'removing')->pluck('email')->sort()->values()->all();
    }

    public function test_people_search_and_the_settings_flag(): void
    {
        $this->actingAs($this->manager())->getJson(self::BASE . '/people?search=solo')->assertOk()
            ->assertJsonPath('people.0.key', '31')
            ->assertJsonPath('people.0.email', 'pat@solo.test');

        $this->actingAs($this->manager())->getJson(self::BASE)->assertOk()->assertJsonPath('settings.source.people', true);

        // A source without the picker: no people, flag off.
        config(['visns-packages.email_campaigns.contact_source' => FakeContactSource::class]);
        $this->actingAs($this->manager())->getJson(self::BASE . '/people?search=solo')->assertOk()->assertJsonPath('people', []);
        $this->actingAs($this->manager())->getJson(self::BASE)->assertOk()->assertJsonPath('settings.source.people', false);
    }

    public function test_a_list_of_picked_people_alone_is_just_them_and_follows_their_address(): void
    {
        $id = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', [
            'name' => 'Picked', 'kind' => 'crm', 'groups' => [], 'contacts' => ['31', '12'],
        ])->assertCreated()->json('list.id');
        $list = EmailList::findOrFail($id);

        $this->assertSame(['lee@acura.test', 'pat@solo.test'], $this->emails($list));

        $this->actingAs($this->manager())->getJson(self::BASE . '/lists/' . $id)->assertOk()
            ->assertJsonPath('list.takes_everyone', false)
            ->assertJsonCount(2, 'list.picked');

        FakePickingContactSource::$loose[0]['email'] = 'pat.new@solo.test';
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $id . '/refresh')->assertOk();
        $this->assertSame(['lee@acura.test', 'pat.new@solo.test'], $this->emails($list));
        FakePickingContactSource::$loose[0]['email'] = 'pat@solo.test';
    }

    public function test_picked_people_join_a_client_list_and_can_leave_it(): void
    {
        $id = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', [
            'name' => 'Bethesda + Pat', 'kind' => 'crm', 'groups' => ['c2'], 'options' => ['primary_only' => true],
        ])->assertCreated()->json('list.id');
        $list = EmailList::findOrFail($id);
        $this->assertSame(['kim@acura.test', 'sam@bethesda.test'], $this->emails($list));

        // Lee is not primary, but picked by hand is on the list anyway.
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $id . '/people', ['add' => ['31', '12']])->assertOk()
            ->assertJsonPath('counts.added', 2);
        $this->assertSame(['kim@acura.test', 'lee@acura.test', 'pat@solo.test', 'sam@bethesda.test'], $this->emails($list));

        // Renaming / changing groups keeps the picked people.
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $id, ['groups' => ['c2'], 'options' => []])->assertOk();
        $this->assertSame(['12', '31'], collect($list->fresh()->filters['contacts'])->sort()->values()->all());

        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $id . '/people', ['remove' => ['31']])->assertOk();
        $this->assertNotContains('pat@solo.test', $this->emails($list));
    }

    public function test_an_everyone_list_refuses_additions_and_a_picked_only_list_cannot_empty_itself(): void
    {
        $everyone = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', ['name' => 'All', 'kind' => 'crm'])->json('list.id');
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $everyone . '/people', ['add' => ['31']])->assertStatus(422);

        $picked = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', ['name' => 'One', 'kind' => 'crm', 'contacts' => ['31']])->json('list.id');
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $picked . '/people', ['remove' => ['31']])->assertStatus(422);
        $this->assertSame(['pat@solo.test'], $this->emails(EmailList::findOrFail($picked)));
    }

    public function test_an_imported_list_gains_picked_people_and_keeps_its_own(): void
    {
        $id = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', [
            'name' => 'Imported', 'kind' => 'import', 'rows' => [['email' => 'a@x.test'], ['email' => 'kim@acura.test']],
        ])->assertCreated()->json('list.id');
        $list = EmailList::findOrFail($id);

        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $id . '/people', ['add' => ['11', '31']])->assertOk()
            ->assertJsonPath('counts.added', 1);
        $this->assertSame(['a@x.test', 'kim@acura.test', 'pat@solo.test'], $this->emails($list));

        $this->actingAs($this->manager())->postJson(self::BASE . '/lists/' . $id . '/people', ['remove' => ['31']])->assertStatus(422);
    }

    public function test_reading_needs_access_and_adding_needs_manage(): void
    {
        $id = $this->actingAs($this->manager())->postJson(self::BASE . '/lists', ['name' => 'One', 'kind' => 'crm', 'contacts' => ['31']])->json('list.id');
        $this->flushSession();
        $this->actingAs($this->staff('Email Campaigns Access'))->postJson(self::BASE . '/lists/' . $id . '/people', ['add' => ['12']])->assertForbidden();
    }
}
