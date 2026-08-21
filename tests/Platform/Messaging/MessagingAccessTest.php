<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

/**
 * Who gets in, and what they can see once they are.
 *
 * The rule under test is the one the whole module rests on: a line you are not
 * attached to does not exist, and neither do its threads. Every miss is a 404,
 * never a 403 - a 403 on a thread id would answer "is this person texting the
 * practice" for anyone who cared to enumerate.
 */
class MessagingAccessTest extends MessagingTestCase
{
    public function test_a_signed_out_visitor_is_turned_away(): void
    {
        $this->getJson(self::BASE . '/status')->assertStatus(401);
    }

    public function test_a_user_without_the_access_permission_is_refused(): void
    {
        $nobody = $this->staffWith();

        $this->actingAs($nobody)
            ->getJson(self::BASE . '/status')
            ->assertStatus(403);
    }

    public function test_a_member_sees_only_the_lines_they_are_attached_to(): void
    {
        $member = $this->member();
        $mine = $this->line([$member], ['label' => 'Reception']);
        $this->line([], ['label' => 'Advisers']);

        $response = $this->actingAs($member)
            ->getJson(self::BASE . '/lines')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $response);
        $this->assertSame($mine->id, $response[0]['id']);
        $this->assertSame('Reception', $response[0]['label']);
    }

    public function test_an_administrator_sees_every_line(): void
    {
        $admin = $this->admin();
        $this->line([], ['label' => 'Reception']);
        $this->line([], ['label' => 'Advisers']);

        $this->actingAs($admin)
            ->getJson(self::BASE . '/lines')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_line_carries_its_number_in_both_spellings(): void
    {
        $member = $this->member();
        $this->line([$member], ['phone_number' => '+61893752549']);

        $row = $this->actingAs($member)->getJson(self::BASE . '/lines')->json('data.0');

        $this->assertSame('+61893752549', $row['phone_number']);
        $this->assertSame('(08) 9375 2549', $row['display_number']);
    }

    public function test_a_thread_on_somebody_elses_line_is_a_404_not_a_403(): void
    {
        $member = $this->member();
        $other = $this->member();

        $theirLine = $this->line([$other]);
        $theirThread = $this->thread($theirLine);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $theirThread->id)
            ->assertStatus(404);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $theirThread->id . '/messages', ['body' => 'hello'])
            ->assertStatus(404);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $theirThread->id . '/read')
            ->assertStatus(404);
    }

    public function test_an_administrator_reaches_a_thread_on_a_line_nobody_is_attached_to(): void
    {
        $admin = $this->admin();
        $line = $this->line();
        $thread = $this->thread($line);

        $this->actingAs($admin)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('thread.id', $thread->id);
    }

    public function test_filtering_by_a_line_the_user_cannot_see_returns_nothing_rather_than_erroring(): void
    {
        $member = $this->member();
        $mine = $this->line([$member]);
        $this->thread($mine, '+61412345678', ['last_message_at' => now()]);

        $theirs = $this->line();
        $this->thread($theirs, '+61412000000', ['last_message_at' => now()]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads?line_id=' . $theirs->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_status_reports_the_transport_and_is_honest_about_not_being_connected(): void
    {
        $member = $this->member();
        $this->line([$member]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/status')
            ->assertOk()
            ->assertJson([
                'transport' => 'null',
                'connected' => false,
                'lines_count' => 1,
                'unread_total' => 0,
            ]);
    }

    public function test_status_reports_connected_only_under_the_zoom_transport(): void
    {
        $this->app['config']->set('visns-packages.messaging.transport', 'zoom');

        $member = $this->member();

        $this->actingAs($member)
            ->getJson(self::BASE . '/status')
            ->assertOk()
            ->assertJson(['transport' => 'zoom', 'connected' => true]);
    }
}
