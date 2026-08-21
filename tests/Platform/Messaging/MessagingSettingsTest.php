<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsTemplate;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\FakeZoomSmsClient;

/**
 * Settings: the lines, who works them, and the canned bodies.
 */
class MessagingSettingsTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeZoomSmsClient::reset();
    }

    /*
    |--------------------------------------------------------------------------
    | Lines
    |--------------------------------------------------------------------------
    */

    public function test_a_member_cannot_reach_the_line_settings(): void
    {
        $this->actingAs($this->member())
            ->getJson(self::BASE . '/settings/lines')
            ->assertStatus(403);
    }

    public function test_an_administrator_sees_every_line_with_its_staff(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->line([$member], ['label' => 'Reception']);

        $row = $this->actingAs($admin)
            ->getJson(self::BASE . '/settings/lines')
            ->assertOk()
            ->assertJsonPath('zoom_connected', false)
            ->assertJsonPath('transport', 'null')
            ->json('data.0');

        $this->assertSame('Reception', $row['label']);
        $this->assertSame($member->id, $row['users'][0]['id']);
        $this->assertSame($member->name, $row['users'][0]['name']);
        $this->assertFalse($row['deleted']);
    }

    public function test_a_line_is_created_with_its_number_normalised_and_its_staff_attached(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)
            ->postJson(self::BASE . '/settings/lines', [
                'label' => 'Reception',
                'phone_number' => '(08) 9375 2549',
                'user_ids' => [$member->id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('phone_number', '+61893752549')
            ->assertJsonPath('display_number', '(08) 9375 2549')
            ->assertJsonPath('users.0.id', $member->id);

        $this->assertTrue(
            SmsLine::first()->users()->where('users.id', $member->id)->exists()
        );
    }

    public function test_two_lines_cannot_claim_the_same_number_however_it_is_typed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE . '/settings/lines', [
            'label' => 'Reception',
            'phone_number' => '+61893752549',
        ])->assertStatus(201);

        // The same inbox, spelled differently: two rows claiming it would make
        // every inbound message for it ambiguous.
        $this->actingAs($admin)
            ->postJson(self::BASE . '/settings/lines', [
                'label' => 'Reception again',
                'phone_number' => '08 9375 2549',
            ])
            ->assertStatus(422);

        $this->assertSame(1, SmsLine::count());
    }

    public function test_a_number_the_module_could_never_text_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/settings/lines', [
                'label' => 'Extension',
                'phone_number' => '304',
            ])
            ->assertStatus(422);
    }

    public function test_updating_a_line_replaces_its_staff_list(): void
    {
        $admin = $this->admin();
        $alice = $this->member();
        $bob = $this->member();

        $line = $this->line([$alice]);

        $this->actingAs($admin)
            ->putJson(self::BASE . '/settings/lines/' . $line->id, [
                'label' => 'Advisers',
                'user_ids' => [$bob->id],
            ])
            ->assertOk()
            ->assertJsonPath('label', 'Advisers')
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $bob->id);
    }

    public function test_saving_a_line_without_a_user_list_leaves_the_staff_alone(): void
    {
        // A form that never loaded the user list must not silently detach
        // everybody.
        $admin = $this->admin();
        $alice = $this->member();
        $line = $this->line([$alice]);

        $this->actingAs($admin)
            ->putJson(self::BASE . '/settings/lines/' . $line->id, ['label' => 'Renamed'])
            ->assertOk()
            ->assertJsonCount(1, 'users');
    }

    public function test_a_line_keeps_its_own_number_when_it_is_saved_again(): void
    {
        $admin = $this->admin();
        $line = $this->line([], ['phone_number' => '+61893752549']);

        $this->actingAs($admin)
            ->putJson(self::BASE . '/settings/lines/' . $line->id, [
                'label' => 'Reception',
                'phone_number' => '08 9375 2549',
            ])
            ->assertOk()
            ->assertJsonPath('phone_number', '+61893752549');
    }

    public function test_deleting_a_line_is_a_soft_delete_and_keeps_the_history(): void
    {
        $admin = $this->admin();
        $line = $this->line();
        $thread = $this->thread($line);

        $this->actingAs($admin)
            ->deleteJson(self::BASE . '/settings/lines/' . $line->id)
            ->assertStatus(204);

        $this->assertNotNull(SmsLine::withTrashed()->find($line->id)->deleted_at);
        $this->assertDatabaseHas('sms_threads', ['id' => $thread->id]);

        $this->actingAs($admin)
            ->getJson(self::BASE . '/settings/lines')
            ->assertJsonPath('data.0.deleted', true);
    }

    public function test_a_member_cannot_create_a_line(): void
    {
        $this->actingAs($this->member())
            ->postJson(self::BASE . '/settings/lines', [
                'label' => 'Mine',
                'phone_number' => '+61893752549',
            ])
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Zoom, when it is connected
    |--------------------------------------------------------------------------
    */

    public function test_the_settings_page_offers_zooms_own_numbers_when_zoom_answers(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$users = [
            'success' => true,
            'users' => [[
                'id' => 'zoom-user-1',
                'email' => 'reception@example.test',
                'display_name' => 'Reception',
                'phone_numbers' => ['+61893752549'],
            ]],
        ];

        $this->actingAs($this->admin())
            ->getJson(self::BASE . '/settings/lines')
            ->assertOk()
            ->assertJsonPath('zoom_connected', true)
            ->assertJsonPath('zoom_users.0.email', 'reception@example.test')
            ->assertJsonPath('zoom_users.0.phone_numbers.0', '+61893752549');
    }

    public function test_zoom_being_down_warns_rather_than_taking_the_settings_page_with_it(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');
        $this->app->instance(ZoomSmsClient::class, new FakeZoomSmsClient());

        FakeZoomSmsClient::$shouldThrow = true;

        $this->line([], ['label' => 'Reception']);

        $response = $this->actingAs($this->admin())
            ->getJson(self::BASE . '/settings/lines')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('zoom_users', []);

        $this->assertStringContainsString('Could not reach Zoom', $response->json('zoom_error'));
    }

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    */

    public function test_anyone_with_access_can_read_the_templates(): void
    {
        SmsTemplate::create(['name' => 'Review booked', 'body' => 'Your review is booked.', 'sort' => 1]);
        SmsTemplate::create(['name' => 'Running late', 'body' => 'We are running late.', 'sort' => 0]);

        $names = $this->actingAs($this->member())
            ->getJson(self::BASE . '/templates')
            ->assertOk()
            ->json('data.*.name');

        // Ordered the way the practice arranged them, not alphabetically.
        $this->assertSame(['Running late', 'Review booked'], $names);
    }

    public function test_an_inactive_template_is_hidden_from_the_composer(): void
    {
        SmsTemplate::create(['name' => 'Retired', 'body' => 'Old text', 'active' => false]);

        $this->actingAs($this->member())
            ->getJson(self::BASE . '/templates')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // But an administrator asking for them gets them, or the one they just
        // deactivated would vanish off the screen they did it on.
        $this->actingAs($this->admin())
            ->getJson(self::BASE . '/templates?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_member_cannot_write_a_template(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->postJson(self::BASE . '/templates', ['name' => 'Mine', 'body' => 'Hello'])
            ->assertStatus(403);

        $template = SmsTemplate::create(['name' => 'Theirs', 'body' => 'Hello']);

        $this->actingAs($member)
            ->putJson(self::BASE . '/templates/' . $template->id, ['name' => 'Changed'])
            ->assertStatus(403);

        $this->actingAs($member)
            ->deleteJson(self::BASE . '/templates/' . $template->id)
            ->assertStatus(403);
    }

    public function test_an_administrator_creates_updates_and_retires_a_template(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin)
            ->postJson(self::BASE . '/templates', [
                'name' => 'Review booked',
                'body' => 'Your review is booked for Thursday.',
                'sort' => 3,
            ])
            ->assertStatus(201)
            ->assertJsonPath('active', true)
            ->json('id');

        $this->actingAs($admin)
            ->putJson(self::BASE . '/templates/' . $id, ['active' => false])
            ->assertOk()
            ->assertJsonPath('active', false)
            // A partial update leaves the rest alone.
            ->assertJsonPath('name', 'Review booked');

        $this->actingAs($admin)
            ->deleteJson(self::BASE . '/templates/' . $id)
            ->assertStatus(204);

        $this->assertNull(SmsTemplate::find($id));
        $this->assertNotNull(SmsTemplate::withTrashed()->find($id));
    }

    public function test_a_template_body_over_the_configured_maximum_is_refused(): void
    {
        $this->app['config']->set('visns-packages.messaging.max_body_length', 20);

        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/templates', [
                'name' => 'Too long',
                'body' => str_repeat('x', 21),
            ])
            ->assertStatus(422);
    }
}
