<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use PHPUnit\Framework\Attributes\DataProvider;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;

/**
 * Opt-outs: the keyword rule, what an inbound STOP does, and the register.
 *
 * The module's compliance floor, so every one of these runs with the bulk
 * sub-module OFF - which is the state most deployments are in, and the state
 * where this has to work anyway.
 */
class MessagingOptOutTest extends MessagingTestCase
{
    private function optOuts(): SmsOptOuts
    {
        return app(SmsOptOuts::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The keyword rule
    |--------------------------------------------------------------------------
    |
    | A table, because the whole value of this method is in the line between a
    | COMMAND and a SENTENCE - and that line is only visible when both sides of
    | it are written down together.
    */

    public static function keywords(): array
    {
        return [
            // The plain commands.
            'STOP' => ['STOP', SmsOptOuts::OUT],
            'lower case' => ['stop', SmsOptOuts::OUT],
            'mixed case' => ['Stop', SmsOptOuts::OUT],
            'trailing full stop' => ['stop.', SmsOptOuts::OUT],
            'shouted' => ['STOP!!!', SmsOptOuts::OUT],
            'padded' => ['   stop   ', SmsOptOuts::OUT],
            'first word' => ['stop please', SmsOptOuts::OUT],
            'first word punctuated' => ['stop, please', SmsOptOuts::OUT],
            'unsubscribe' => ['UNSUBSCRIBE', SmsOptOuts::OUT],
            'quit' => ['quit', SmsOptOuts::OUT],
            'end' => ['End', SmsOptOuts::OUT],
            'cancel' => ['cancel', SmsOptOuts::OUT],
            'two words' => ['opt out', SmsOptOuts::OUT],
            'two words, then more' => ['OPT OUT please', SmsOptOuts::OUT],
            'one word spelling' => ['optout', SmsOptOuts::OUT],

            // Opting back in.
            'start' => ['START', SmsOptOuts::IN],
            'unstop' => ['unstop', SmsOptOuts::IN],
            'subscribe' => ['Subscribe', SmsOptOuts::IN],
            'start, then more' => ['start again please', SmsOptOuts::IN],

            // NOT commands. Every one of these is a person talking to the
            // practice, and unsubscribing them for it would be acting on a
            // guess that nobody would notice for months.
            'a sentence containing it' => ['Please stop sending these on a Sunday', null],
            'a question' => ['Can you cancel my direct debit?', null],
            'buried' => ['I want to unsubscribe from the newsletter', null],
            'a word beginning with it' => ['Stopping by at 3', null],
            'ordinary' => ['Running late, see you at 4', null],
            'empty' => ['', null],
            'punctuation only' => ['???', null],
        ];
    }

    #[DataProvider('keywords')]
    public function test_the_keyword_rule(string $body, ?string $expected): void
    {
        $this->assertSame($expected, $this->optOuts()->keywordIn($body));
    }

    public function test_a_configured_keyword_is_matched_however_it_was_typed(): void
    {
        $this->app['config']->set('visns-packages.messaging.opt_out.keywords', [' remove me ']);

        // The config value goes through the same reduction the message does, so
        // a list that only worked when it was typed in capitals would be a trap.
        $this->assertSame(SmsOptOuts::OUT, $this->optOuts()->keywordIn('Remove Me'));
    }

    public function test_an_empty_keyword_list_matches_nothing(): void
    {
        $this->app['config']->set('visns-packages.messaging.opt_out.keywords', []);
        $this->app['config']->set('visns-packages.messaging.opt_out.opt_in_keywords', []);

        $this->assertNull($this->optOuts()->keywordIn('STOP'));
    }

    /*
    |--------------------------------------------------------------------------
    | An inbound STOP
    |--------------------------------------------------------------------------
    */

    public function test_an_inbound_stop_records_the_opt_out_and_confirms_it(): void
    {
        $line = $this->line();
        $thread = $this->thread($line, '+61412345678');

        $message = app(SmsService::class)->recordInbound($thread, 'STOP');

        $optOut = SmsOptOut::first();

        $this->assertNotNull($optOut);
        $this->assertSame('+61412345678', $optOut->number);
        $this->assertSame(SmsOptOut::SOURCE_KEYWORD, $optOut->source);
        $this->assertSame((int) $line->id, (int) $optOut->line_id);
        // The evidence: "they asked us to stop" is an assertion with nothing
        // behind it unless it names the message.
        $this->assertSame((int) $message->id, (int) $optOut->message_id);
        $this->assertNull($optOut->user_id);

        // The confirmation went out on the same thread. Under the null transport
        // nothing leaves the building, but the ROW is what proves the send was
        // attempted - which is the same evidence the practice has for every
        // other message it has tried to send.
        $reply = SmsMessage::query()
            ->where('direction', SmsMessage::DIRECTION_OUT)
            ->first();

        $this->assertNotNull($reply);
        $this->assertSame((int) $thread->id, (int) $reply->thread_id);
        $this->assertStringContainsString('unsubscribed', (string) $reply->body);
        $this->assertStringContainsString('START', (string) $reply->body);
        // Nobody wrote it.
        $this->assertNull($reply->user_id);
    }

    public function test_a_second_stop_is_idempotent(): void
    {
        $line = $this->line();
        $thread = $this->thread($line, '+61412345678');

        app(SmsService::class)->recordInbound($thread, 'STOP');
        app(SmsService::class)->recordInbound($thread, 'stop please');

        // One row, not a unique-constraint violation on the webhook's hot path.
        $this->assertSame(1, SmsOptOut::count());
    }

    public function test_start_releases_it_and_confirms(): void
    {
        $line = $this->line();
        $thread = $this->thread($line, '+61412345678');

        $sms = app(SmsService::class);

        $sms->recordInbound($thread, 'STOP');
        $this->assertTrue($this->optOuts()->isOptedOut('+61412345678'));

        $sms->recordInbound($thread, 'START');

        $this->assertFalse($this->optOuts()->isOptedOut('+61412345678'));
        $this->assertSame(0, SmsOptOut::count());

        $reply = SmsMessage::query()
            ->where('direction', SmsMessage::DIRECTION_OUT)
            ->orderByDesc('id')
            ->first();

        $this->assertStringContainsString('subscribed again', (string) $reply->body);
    }

    public function test_a_null_reply_records_the_opt_out_and_says_nothing(): void
    {
        $this->app['config']->set('visns-packages.messaging.opt_out.reply', null);

        $line = $this->line();
        $thread = $this->thread($line, '+61412345678');

        app(SmsService::class)->recordInbound($thread, 'STOP');

        $this->assertTrue($this->optOuts()->isOptedOut('+61412345678'));
        $this->assertSame(
            0,
            SmsMessage::query()->where('direction', SmsMessage::DIRECTION_OUT)->count()
        );
    }

    public function test_an_ordinary_message_changes_nothing(): void
    {
        $line = $this->line();
        $thread = $this->thread($line, '+61412345678');

        app(SmsService::class)->recordInbound($thread, 'Please stop sending these on a Sunday');

        $this->assertSame(0, SmsOptOut::count());
        $this->assertSame(
            0,
            SmsMessage::query()->where('direction', SmsMessage::DIRECTION_OUT)->count()
        );
    }

    public function test_a_sender_id_is_never_unsubscribed_and_never_answered(): void
    {
        $line = $this->line();
        // `Apple`, `ANZ`, a five-digit short code: no handset behind it, so
        // there is nothing to unsubscribe and a reply would be billed and read
        // by nobody.
        $thread = $this->thread($line, 'Apple');

        app(SmsService::class)->recordInbound($thread, 'STOP');

        $this->assertSame(0, SmsOptOut::count());
        $this->assertSame(
            0,
            SmsMessage::query()->where('direction', SmsMessage::DIRECTION_OUT)->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Archiving, and the reply that brings a thread back
    |--------------------------------------------------------------------------
    */

    public function test_an_inbound_message_un_archives_the_thread(): void
    {
        $line = $this->line();
        $thread = $this->thread($line, '+61412345678', ['archived_at' => now()]);

        app(SmsService::class)->recordInbound($thread, 'Yes please');

        // The whole bargain of a campaign archiving the threads it creates: a
        // reply brings it straight back, or the archive becomes a way of losing
        // answers.
        $this->assertNull($thread->fresh()->archived_at);
    }

    public function test_an_un_archived_thread_keeps_its_summary(): void
    {
        $line = $this->line();
        $thread = $this->thread($line, '+61412345678', ['archived_at' => now()]);

        app(SmsService::class)->recordInbound($thread, 'Yes please');

        $fresh = $thread->fresh();

        // The un-archive is written before touchLastMessage re-stamps the
        // denormalised summary, so the list and the archive view cannot disagree
        // about a thread that has just spoken.
        $this->assertNull($fresh->archived_at);
        $this->assertSame('Yes please', $fresh->last_message_preview);
        $this->assertSame(SmsMessage::DIRECTION_IN, $fresh->last_direction);
    }

    /*
    |--------------------------------------------------------------------------
    | The thread payload says so
    |--------------------------------------------------------------------------
    */

    public function test_the_thread_payload_carries_opted_out(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $quiet = $this->thread($line, '+61412345678');
        $ordinary = $this->thread($line, '+61498765432');

        $this->optOuts()->record('+61412345678', SmsOptOut::SOURCE_MANUAL);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $quiet->id)
            ->assertOk()
            ->assertJsonPath('thread.opted_out', true);

        // On every thread, like can_reply: a key present on some rows and absent
        // on others would read as false wherever it was missing.
        $this->actingAs($member)
            ->getJson(self::BASE . '/threads/' . $ordinary->id)
            ->assertOk()
            ->assertJsonPath('thread.opted_out', false);
    }

    public function test_the_thread_list_says_so_too(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678', ['last_message_at' => now()]);

        $this->optOuts()->record('+61412345678', SmsOptOut::SOURCE_MANUAL);

        $this->actingAs($member)
            ->getJson(self::BASE . '/threads')
            ->assertOk()
            ->assertJsonPath('data.0.id', $thread->id)
            ->assertJsonPath('data.0.opted_out', true);
    }

    public function test_an_opt_out_labels_the_thread_and_does_not_block_a_reply(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);
        $thread = $this->thread($line, '+61412345678');

        $this->optOuts()->record('+61412345678', SmsOptOut::SOURCE_MANUAL);

        // An opt-out stops BULK. A staff member answering somebody who has
        // written in is a conversation, and refusing it would be a worse failure
        // than the one opting out protects against.
        $this->actingAs($member)
            ->postJson(self::BASE . '/threads/' . $thread->id . '/messages', [
                'body' => 'Understood — we have taken you off the list.',
            ])
            ->assertStatus(201);
    }

    /*
    |--------------------------------------------------------------------------
    | The register's endpoints
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_list_add_and_remove(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(self::BASE . '/opt-outs', [
                'number' => '0412 345 678',
                'note' => 'Asked on the phone',
            ])
            ->assertStatus(201)
            ->assertJsonPath('opt_out.number', '+61412345678')
            ->assertJsonPath('opt_out.display_number', '0412 345 678')
            ->assertJsonPath('opt_out.source', SmsOptOut::SOURCE_MANUAL)
            ->assertJsonPath('opt_out.note', 'Asked on the phone')
            ->assertJsonPath('opt_out.user.id', $admin->id);

        $this->actingAs($admin)
            ->getJson(self::BASE . '/opt-outs')
            ->assertOk()
            ->assertJsonCount(1, 'opt_outs')
            ->assertJsonPath('opt_outs.0.number', '+61412345678');

        $id = SmsOptOut::first()->id;

        $this->actingAs($admin)
            ->deleteJson(self::BASE . '/opt-outs/' . $id)
            ->assertNoContent();

        $this->assertSame(0, SmsOptOut::count());
    }

    public function test_a_number_that_cannot_be_read_is_refused(): void
    {
        // Adding the wrong number silently stops texting somebody who never
        // asked, and nobody would ever find out.
        $this->actingAs($this->admin())
            ->postJson(self::BASE . '/opt-outs', ['number' => 'n/a'])
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', 'That does not look like a phone number we can text.');

        $this->assertSame(0, SmsOptOut::count());
    }

    public function test_the_search_finds_a_number_spelled_the_way_it_is_shown(): void
    {
        $admin = $this->admin();

        $this->optOuts()->record('+61412345678', SmsOptOut::SOURCE_MANUAL);
        $this->optOuts()->record('+61498765432', SmsOptOut::SOURCE_MANUAL);

        // Typed off the screen, where it reads "0412 345 678".
        $this->actingAs($admin)
            ->getJson(self::BASE . '/opt-outs?search=' . urlencode('0412 345'))
            ->assertOk()
            ->assertJsonCount(1, 'opt_outs')
            ->assertJsonPath('opt_outs.0.number', '+61412345678');
    }

    public function test_the_register_is_manage_only(): void
    {
        $member = $this->member();

        $this->actingAs($member)->getJson(self::BASE . '/opt-outs')->assertStatus(403);
        $this->actingAs($member)
            ->postJson(self::BASE . '/opt-outs', ['number' => '0412345678'])
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | The service's own contract
    |--------------------------------------------------------------------------
    */

    public function test_normalise_refuses_a_sender_id(): void
    {
        $this->assertSame('+61412345678', $this->optOuts()->normalise('0412 345 678'));
        $this->assertNull($this->optOuts()->normalise('Apple'));
    }

    public function test_release_says_whether_there_was_anything_to_remove(): void
    {
        $this->optOuts()->record('+61412345678', SmsOptOut::SOURCE_MANUAL);

        $this->assertTrue($this->optOuts()->release('+61412345678'));
        // So a screen can say "that number was not on the list" rather than
        // reporting a success that did nothing.
        $this->assertFalse($this->optOuts()->release('+61412345678'));
    }

    public function test_opted_out_among_answers_the_whole_page_in_one_query(): void
    {
        $this->optOuts()->record('+61412345678', SmsOptOut::SOURCE_MANUAL);

        $among = $this->optOuts()->optedOutAmong([
            '+61412345678',
            '+61498765432',
            '',
        ]);

        $this->assertSame(['+61412345678' => true], $among);
        $this->assertSame([], $this->optOuts()->optedOutAmong([]));
    }
}
