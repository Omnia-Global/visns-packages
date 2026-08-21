<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\StubClientDetails;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\StubClientResolver;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging\StubClientSearch;

/**
 * The inbox: finding, starting, labelling, reading and archiving conversations.
 */
class MessagingThreadsTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StubClientDetails::reset();
        StubClientResolver::reset();
        StubClientSearch::reset();
    }

    /**
     * An inbound message, without going through a transport or a webhook.
     */
    private function inbound(SmsThread $thread, string $body, array $attributes = []): SmsMessage
    {
        return SmsMessage::create(array_merge([
            'thread_id' => $thread->id,
            'direction' => 'in',
            'body' => $body,
            'status' => 'received',
            'received_at' => now(),
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Starting a conversation
    |--------------------------------------------------------------------------
    */

    public function test_a_new_thread_is_created_for_a_normalised_number(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $response = $this->actingAs($member)
            ->postJson(self::BASE . '/threads', [
                'line_id' => $line->id,
                'to' => '0412 345 678',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('thread.external_number', '+61412345678');
        $response->assertJsonPath('thread.display_number', '0412 345 678');
        $response->assertJsonPath('message', null);

        $this->assertSame(1, SmsThread::count());
    }

    public function test_starting_a_conversation_twice_reuses_the_same_thread(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $first = $this->actingAs($member)
            ->postJson(self::BASE . '/threads', ['line_id' => $line->id, 'to' => '0412 345 678'])
            ->json('thread.id');

        // A different spelling of the same number must not open a second
        // conversation - the client would then have two histories.
        $second = $this->actingAs($member)
            ->postJson(self::BASE . '/threads', ['line_id' => $line->id, 'to' => '+61 412 345 678'])
            ->json('thread.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SmsThread::count());
    }

    public function test_an_unparseable_number_is_refused_rather_than_guessed_at(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads', ['line_id' => $line->id, 'to' => '9375 2549'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['to']]);

        $this->assertSame(0, SmsThread::count());
    }

    public function test_a_thread_cannot_be_started_on_a_line_the_user_cannot_see(): void
    {
        $member = $this->member();
        $theirs = $this->line();

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads', ['line_id' => $theirs->id, 'to' => '0412345678'])
            ->assertStatus(404);
    }

    public function test_a_new_thread_is_matched_to_a_client_by_the_configured_resolver(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_resolver',
            StubClientResolver::class
        );

        $member = $this->member();
        $line = $this->line([$member]);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads', ['line_id' => $line->id, 'to' => '0412345678'])
            ->assertStatus(201)
            ->assertJsonPath('thread.client.id', 7)
            ->assertJsonPath('thread.client.name', 'Cleo Client');

        $this->assertSame(['+61412345678'], StubClientResolver::$calls);
    }

    public function test_a_throwing_resolver_costs_the_thread_its_client_not_the_thread(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_resolver',
            StubClientResolver::class
        );
        StubClientResolver::$shouldThrow = true;

        $member = $this->member();
        $line = $this->line([$member]);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads', ['line_id' => $line->id, 'to' => '0412345678'])
            ->assertStatus(201)
            ->assertJsonPath('thread.client', null);

        $this->assertSame(1, SmsThread::count());
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function test_threads_are_listed_newest_conversation_first(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $old = $this->thread($line, '+61400000001', ['last_message_at' => now()->subDay()]);
        $new = $this->thread($line, '+61400000002', ['last_message_at' => now()]);

        $ids = $this->actingAs($member)
            ->getJson(self::BASE . '/threads')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$new->id, $old->id], $ids);
    }

    public function test_search_matches_a_contact_name_a_client_name_and_a_typed_number(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $labelled = $this->thread($line, '+61400000001', [
            'contact_name' => "Bob's accountant",
            'last_message_at' => now(),
        ]);
        $client = $this->thread($line, '+61400000002', [
            'client_name' => 'Cleo Client',
            'last_message_at' => now(),
        ]);
        $number = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $this->assertSame(
            [$labelled->id],
            $this->actingAs($member)->getJson(self::BASE . '/threads?search=accountant')->json('data.*.id')
        );

        $this->assertSame(
            [$client->id],
            $this->actingAs($member)->getJson(self::BASE . '/threads?search=Cleo')->json('data.*.id')
        );

        // Typed the way it is shown on screen, not the way it is stored.
        $this->assertSame(
            [$number->id],
            $this->actingAs($member)->getJson(self::BASE . '/threads?search=0412 345 678')->json('data.*.id')
        );
    }

    public function test_search_finds_a_thread_by_what_was_last_said_in_it(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $this->thread($line, '+61400000001', [
            'last_message_at' => now(),
            'last_message_preview' => 'Running late for the review',
        ]);
        $this->thread($line, '+61400000002', ['last_message_at' => now()]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads?search=review')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_archived_threads_are_out_of_the_way_until_they_are_asked_for(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $open = $this->thread($line, '+61400000001', ['last_message_at' => now()]);
        $filed = $this->thread($line, '+61400000002', [
            'last_message_at' => now(),
            'archived_at' => now(),
        ]);

        $this->assertSame(
            [$open->id],
            $this->actingAs($member)->getJson(self::BASE . '/threads')->json('data.*.id')
        );

        $this->assertSame(
            [$filed->id],
            $this->actingAs($member)->getJson(self::BASE . '/threads?archived=1')->json('data.*.id')
        );
    }

    public function test_a_thread_can_be_archived_and_brought_back(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/archive')
            ->assertStatus(204);

        $this->assertNotNull($thread->fresh()->archived_at);

        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/unarchive')
            ->assertStatus(204);

        $this->assertNull($thread->fresh()->archived_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Unread
    |--------------------------------------------------------------------------
    */

    public function test_unread_is_counted_per_user_not_per_thread(): void
    {
        $alice = $this->member();
        $bob = $this->member();
        $line = $this->line([$alice, $bob]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $this->inbound($thread, 'Are you free Thursday?');
        $this->inbound($thread, 'Or Friday?');

        // Alice opens it; Bob has not.
        $this->actingAs($alice)->getJson(self::BASE . '/threads/' . $thread->id)->assertOk();

        $this->actingAs($alice)
            ->getJson(self::BASE . '/unread')
            ->assertOk()
            ->assertJson(['total' => 0]);

        $this->actingAs($bob)
            ->getJson(self::BASE . '/unread')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_thread.' . $thread->id, 2)
            ->assertJsonPath('by_line.' . $line->id, 2);
    }

    public function test_a_message_arriving_after_a_read_is_unread_again(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $this->inbound($thread, 'First');

        $this->actingAs($member)->postJson(self::BASE . '/threads/' . $thread->id . '/read')
            ->assertStatus(204);

        $this->inbound($thread, 'Second');

        $this->actingAs($member)
            ->getJson(self::BASE . '/unread')
            ->assertJsonPath('total', 1);
    }

    public function test_only_inbound_messages_count_as_unread(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        SmsMessage::create([
            'thread_id' => $thread->id,
            'direction' => 'out',
            'body' => 'Booked you in for Thursday.',
            'status' => 'sent',
            'user_id' => $member->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/unread')
            ->assertJsonPath('total', 0);
    }

    public function test_unread_counts_ignore_lines_the_user_is_not_on(): void
    {
        $member = $this->member();
        $other = $this->member();

        $theirs = $this->line([$other]);
        $this->inbound($this->thread($theirs, '+61400000009', ['last_message_at' => now()]), 'Not for you');

        $this->actingAs($member)
            ->getJson(self::BASE . '/unread')
            ->assertJsonPath('total', 0);
    }

    public function test_the_unread_only_view_lists_just_the_threads_with_something_new(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $quiet = $this->thread($line, '+61400000001', ['last_message_at' => now()]);
        $noisy = $this->thread($line, '+61400000002', ['last_message_at' => now()]);

        $this->inbound($noisy, 'Hello?');

        $this->assertSame(
            [$noisy->id],
            $this->actingAs($member)->getJson(self::BASE . '/threads?unread_only=1')->json('data.*.id')
        );

        $this->assertNotContains(
            $quiet->id,
            $this->actingAs($member)->getJson(self::BASE . '/threads?unread_only=1')->json('data.*.id')
        );
    }

    public function test_the_thread_list_reports_an_unread_count_per_row(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $this->inbound($thread, 'One');
        $this->inbound($thread, 'Two');

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a thread
    |--------------------------------------------------------------------------
    */

    public function test_messages_come_back_oldest_first_and_page_backwards(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $ids = [];

        foreach (['one', 'two', 'three'] as $body) {
            $ids[] = $this->inbound($thread, $body)->id;
        }

        $response = $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id . '?limit=2')
            ->assertOk();

        // The newest two, in reading order, and there is more behind them.
        $this->assertSame([$ids[1], $ids[2]], $response->json('messages.*.id'));
        $this->assertTrue($response->json('has_more'));

        $older = $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id . '?limit=2&before=' . $ids[1])
            ->assertOk();

        $this->assertSame([$ids[0]], $older->json('messages.*.id'));
        $this->assertFalse($older->json('has_more'));
    }

    public function test_opening_a_thread_marks_it_read(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $this->inbound($thread, 'Anyone there?');

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('thread.unread_count', 0);

        $this->actingAs($member)
            ->getJson(self::BASE . '/unread')
            ->assertJsonPath('total', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Labelling
    |--------------------------------------------------------------------------
    */

    public function test_a_thread_can_be_linked_to_a_client_by_hand(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->putJson(self::BASE . '/threads/' . $thread->id, [
                'client_id' => 42,
                'client_name' => 'Dana Client',
                'contact_name' => "Dana's mobile",
            ])
            ->assertOk()
            ->assertJsonPath('thread.client.id', 42)
            ->assertJsonPath('thread.client.name', 'Dana Client')
            ->assertJsonPath('thread.contact_name', "Dana's mobile");
    }

    public function test_unlinking_a_client_clears_the_cached_name_too(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', [
            'client_id' => 42,
            'client_name' => 'Dana Client',
        ]);

        $this->actingAs($member)
            ->putJson(self::BASE . '/threads/' . $thread->id, ['client_id' => null])
            ->assertOk()
            ->assertJsonPath('thread.client', null);

        $this->assertNull($thread->fresh()->client_name);
    }

    /*
    |--------------------------------------------------------------------------
    | Client details, for the composer's placeholders
    |--------------------------------------------------------------------------
    */

    public function test_opening_a_thread_enriches_its_client_block_from_the_details_hook(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_details',
            StubClientDetails::class
        );

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', [
            'client_id' => 42,
            'client_name' => 'Client, Cleo (Ms)',
        ]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('thread.client.id', 42)
            // The stub answers with no name of its own, so the one a human
            // linked survives.
            ->assertJsonPath('thread.client.name', 'Client, Cleo (Ms)')
            ->assertJsonPath('thread.client.first_name', 'Cleo')
            ->assertJsonPath('thread.client.last_name', 'Client')
            ->assertJsonPath('thread.client.next_event.title', 'Annual review')
            ->assertJsonPath('thread.client.next_event.date', '2026-08-24T14:30:00+08:00');

        $this->assertSame([42], StubClientDetails::$calls);
    }

    public function test_a_details_hook_may_correct_the_name_but_never_blank_it(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_details',
            StubClientDetails::class
        );
        StubClientDetails::$answer = ['id' => 999, 'name' => '', 'first_name' => 'Cleo'];

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', [
            'client_id' => 42,
            'client_name' => 'Client, Cleo (Ms)',
        ]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            // The hook cannot move the conversation to another client, nor
            // wipe the label somebody typed.
            ->assertJsonPath('thread.client.id', 42)
            ->assertJsonPath('thread.client.name', 'Client, Cleo (Ms)')
            ->assertJsonPath('thread.client.first_name', 'Cleo');

        StubClientDetails::$answer = ['name' => 'Client, Cleopatra (Ms)'];

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('thread.client.name', 'Client, Cleopatra (Ms)');
    }

    public function test_a_thread_with_no_client_never_calls_the_details_hook(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_details',
            StubClientDetails::class
        );

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('thread.client', null);

        $this->assertSame([], StubClientDetails::$calls);
    }

    public function test_no_details_hook_leaves_the_client_block_as_it_was(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', [
            'client_id' => 42,
            'client_name' => 'Client, Cleo (Ms)',
        ]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertExactJson([
                'thread' => [
                    'id' => $thread->id,
                    'line_id' => $line->id,
                    'external_number' => '+61412345678',
                    'display_number' => $thread->display_number,
                    'client' => ['id' => 42, 'name' => 'Client, Cleo (Ms)'],
                    'contact_name' => null,
                    'last_message' => null,
                    'unread_count' => 0,
                    'archived_at' => null,
                    'updated_at' => $thread->fresh()->updated_at?->toIso8601String(),
                ],
                'messages' => [],
                'has_more' => false,
            ]);
    }

    public function test_a_throwing_details_hook_costs_the_placeholders_not_the_conversation(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_details',
            StubClientDetails::class
        );
        StubClientDetails::$shouldThrow = true;

        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', [
            'client_id' => 42,
            'client_name' => 'Client, Cleo (Ms)',
        ]);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('thread.client', ['id' => 42, 'name' => 'Client, Cleo (Ms)']);
    }

    public function test_the_inbox_list_is_not_enriched(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_details',
            StubClientDetails::class
        );

        $member = $this->member();
        $line = $this->line([$member]);
        $this->thread($line, '+61412345678', [
            'client_id' => 42,
            'client_name' => 'Client, Cleo (Ms)',
        ]);

        // Fifty rows would be fifty lookups, for placeholders nobody is typing.
        $this->actingAs($member)
            ->getJson(self::BASE . '/threads')
            ->assertOk()
            ->assertJsonPath('data.0.client', ['id' => 42, 'name' => 'Client, Cleo (Ms)']);

        $this->assertSame([], StubClientDetails::$calls);
    }

    /*
    |--------------------------------------------------------------------------
    | Client search
    |--------------------------------------------------------------------------
    */

    public function test_client_search_proxies_the_configured_hook(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_search',
            StubClientSearch::class
        );

        $member = $this->member();

        $this->actingAs($member)
            ->getJson(self::BASE . '/clients/search?q=Cleo')
            ->assertOk()
            ->assertJsonPath('data.0.id', 7)
            ->assertJsonPath('data.0.numbers.0.number', '+61412345678');
    }

    public function test_client_search_is_an_empty_list_when_no_hook_is_configured(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->getJson(self::BASE . '/clients/search?q=Cleo')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_a_throwing_client_search_costs_the_search_box_not_the_page(): void
    {
        $this->app['config']->set(
            'visns-packages.messaging.client_search',
            StubClientSearch::class
        );
        StubClientSearch::$shouldThrow = true;

        $member = $this->member();

        $this->actingAs($member)
            ->getJson(self::BASE . '/clients/search?q=Cleo')
            ->assertOk()
            ->assertJson(['data' => [], 'error' => 'Client search is unavailable.']);
    }
}
