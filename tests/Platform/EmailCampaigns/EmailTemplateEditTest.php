<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\EmailCampaigns;

use Visnsstudio\VisnsPackages\Models\EmailTemplate;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailEditorHtml;

/** Editing a saved template: show, update, preview. */
class EmailTemplateEditTest extends EmailCampaignTestCase
{
    private const HTML = '<html><body><h1>Hello {first_name}</h1><p>Our news.</p></body></html>';

    private function editorTemplate(array $extra = []): EmailTemplate
    {
        return EmailTemplate::create([
            'name' => 'Monthly news',
            'content' => ['format' => 'editor', 'doc' => null, 'html' => self::HTML, 'text' => 'Hello'] + $extra,
        ]);
    }

    private function blockTemplate(): EmailTemplate
    {
        return EmailTemplate::create([
            'name' => 'Blocks',
            'content' => [['type' => 'heading', 'level' => 1, 'text' => 'Hi {first_name}']],
        ]);
    }

    private function url(EmailTemplate $template, string $suffix = ''): string
    {
        return self::BASE . '/templates/' . $template->id . $suffix;
    }

    public function test_show_answers_the_template(): void
    {
        $template = $this->editorTemplate([
            'subject' => 'News for {first_name}',
            'source' => ['provider' => 'resend', 'id' => 'tpl_1', 'updated_at' => '2026-11-01T00:00:00.000Z', 'hash' => hash('sha256', self::HTML)],
        ]);

        $this->actingAs($this->staff('Email Campaigns Access'))->getJson($this->url($template))->assertOk()
            ->assertJsonPath('template.id', $template->id)
            ->assertJsonPath('template.name', 'Monthly news')
            ->assertJsonPath('template.format', 'editor')
            ->assertJsonPath('template.source', 'resend')
            ->assertJsonPath('template.subject', 'News for {first_name}')
            ->assertJsonPath('template.content.html', self::HTML);

        $this->actingAs($this->manager())->getJson($this->url($this->blockTemplate()))->assertOk()
            ->assertJsonPath('template.format', 'blocks')
            ->assertJsonPath('template.source', null)
            ->assertJsonPath('template.subject', null)
            ->assertJsonPath('template.content.0.type', 'heading');
    }

    public function test_an_unknown_or_deleted_template_is_404(): void
    {
        $template = $this->editorTemplate();
        $template->delete();
        $me = $this->manager();

        $this->actingAs($me)->getJson($this->url($template))->assertNotFound();
        $this->actingAs($me)->postJson($this->url($template), ['name' => 'Back'])->assertNotFound();
        $this->actingAs($me)->postJson($this->url($template, '/preview'))->assertNotFound();
        $this->actingAs($me)->getJson(self::BASE . '/templates/999999')->assertNotFound();
        $this->assertSame('Monthly news', EmailTemplate::withTrashed()->findOrFail($template->id)->name);
    }

    public function test_update_renames(): void
    {
        $template = $this->editorTemplate();

        $this->actingAs($this->manager())->postJson($this->url($template), ['name' => 'Quarterly news'])->assertOk()
            ->assertJsonPath('template.name', 'Quarterly news');

        $this->assertSame('Quarterly news', $template->fresh()->name);
        $this->assertSame(self::HTML, $template->fresh()->content['html']);

        $this->actingAs($this->manager())->postJson($this->url($template), ['name' => ''])->assertStatus(422);
        $this->actingAs($this->manager())->postJson($this->url($template), ['name' => str_repeat('x', 192)])->assertStatus(422);
    }

    public function test_editor_content_is_cleaned_and_the_import_bookkeeping_kept(): void
    {
        $source = ['provider' => 'resend', 'id' => 'tpl_1', 'updated_at' => '2026-11-01T00:00:00.000Z', 'hash' => hash('sha256', self::HTML)];
        $template = $this->editorTemplate(['subject' => 'News for {first_name}', 'source' => $source]);
        $dirty = '<html><body><h1 onclick="x()">Edited {first_name}</h1><script>alert(1)</script></body></html>';

        $this->actingAs($this->manager())->postJson($this->url($template), [
            'content' => [
                'format' => 'editor',
                'doc' => ['type' => 'doc', 'content' => []],
                'html' => $dirty,
                'text' => 'Edited',
                // Never taken from the request.
                'source' => ['provider' => 'resend', 'id' => 'tpl_other', 'hash' => 'forged'],
                'subject' => 'Forged',
                'extra' => 'dropped',
            ],
        ])->assertOk()
            ->assertJsonPath('template.source', 'resend')
            ->assertJsonPath('template.subject', 'News for {first_name}');

        $stored = $template->fresh()->content;
        $this->assertSame(['format', 'doc', 'html', 'text', 'subject', 'source'], array_keys($stored));
        $this->assertSame(app(EmailEditorHtml::class)->clean($dirty), $stored['html']);
        $this->assertStringNotContainsString('onclick', $stored['html']);
        $this->assertStringNotContainsString('<script', $stored['html']);
        $this->assertSame($source, $stored['source']);
        $this->assertSame('News for {first_name}', $stored['subject']);
        $this->assertSame(['type' => 'doc', 'content' => []], $stored['doc']);
    }

    public function test_a_template_of_our_own_gains_no_source(): void
    {
        $template = $this->editorTemplate();

        $this->actingAs($this->manager())->postJson($this->url($template), [
            'content' => ['format' => 'editor', 'html' => '<p>Ours</p>', 'source' => ['provider' => 'resend', 'id' => 'x'], 'subject' => 'S'],
        ])->assertOk()->assertJsonPath('template.source', null)->assertJsonPath('template.subject', null);

        $this->assertSame(['format', 'doc', 'html', 'text'], array_keys($template->fresh()->content));
    }

    public function test_block_content_is_stored_whole_and_unknown_types_refused(): void
    {
        $template = $this->blockTemplate();
        $blocks = [
            ['type' => 'heading', 'level' => 2, 'text' => 'New words'],
            ['type' => 'button', 'label' => 'Go', 'url' => 'https://example.com', 'align' => 'left'],
        ];

        $this->actingAs($this->manager())->postJson($this->url($template), ['content' => $blocks])->assertOk()
            ->assertJsonPath('template.format', 'blocks')
            ->assertJsonPath('template.content.0.text', 'New words');

        $this->assertSame($blocks, $template->fresh()->content);

        $this->actingAs($this->manager())->postJson($this->url($template), ['content' => [['type' => 'script', 'html' => 'x']]])->assertStatus(422);
        $this->actingAs($this->manager())->postJson($this->url($template), ['content' => ['format' => 'nonsense', 'html' => 'x']])->assertStatus(422);
        $this->assertSame($blocks, $template->fresh()->content);
    }

    public function test_an_imported_template_cannot_turn_into_blocks(): void
    {
        $template = $this->editorTemplate(['source' => ['provider' => 'resend', 'id' => 'tpl_1', 'hash' => 'h']]);

        $this->actingAs($this->manager())->postJson($this->url($template), ['content' => [['type' => 'heading', 'text' => 'x']]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A template imported from Resend stays in the email editor.');
    }

    public function test_access_only_may_look_but_not_change(): void
    {
        $template = $this->editorTemplate();
        $viewer = $this->staff('Email Campaigns Access');

        $this->actingAs($viewer)->getJson($this->url($template))->assertOk();
        $this->actingAs($viewer)->postJson($this->url($template, '/preview'))->assertOk();
        $this->actingAs($viewer)->postJson($this->url($template), ['name' => 'Mine now'])->assertForbidden();

        $this->assertSame('Monthly news', $template->fresh()->name);
    }

    public function test_preview_fills_the_sample_from_the_request_or_the_record(): void
    {
        $template = $this->editorTemplate(['subject' => 'News for {first_name}']);
        $me = $this->manager();

        $stored = $this->actingAs($me)->postJson($this->url($template, '/preview'))->assertOk();
        $this->assertStringContainsString('Hello Dana</h1>', $stored->json('html'));
        $this->assertSame('News for Dana', $stored->json('subject'));

        $posted = $this->actingAs($me)->postJson($this->url($template, '/preview'), [
            'content' => ['format' => 'editor', 'html' => '<html><body><p onclick="x()">Unsaved {first_name}</p></body></html>'],
        ])->assertOk();
        $this->assertStringContainsString('<p>Unsaved Dana</p>', $posted->json('html'));
        $this->assertStringNotContainsString('onclick', $posted->json('html'));

        $blocks = $this->actingAs($me)->postJson($this->url($this->blockTemplate(), '/preview'))->assertOk();
        $this->assertStringContainsString('Hi Dana', $blocks->json('html'));
        $this->assertSame('', $blocks->json('subject'));
    }

    public function test_after_an_edit_the_resend_import_leaves_it_alone(): void
    {
        $this->resendTemplates = [
            'tpl_news' => [
                'id' => 'tpl_news',
                'name' => 'News',
                'status' => 'published',
                'subject' => 'News',
                'html' => '<p>From Resend</p>',
                'text' => null,
                'variables' => [],
                'created_at' => '2026-11-01T00:00:00.000Z',
                'updated_at' => '2026-11-02T00:00:00.000Z',
            ],
        ];
        $import = self::BASE . '/templates/import-resend';

        $this->actingAs($this->manager())->postJson($import)->assertOk()->assertJsonPath('imported', ['News']);
        $template = EmailTemplate::query()->firstOrFail();

        $this->actingAs($this->manager())->postJson($this->url($template), [
            'content' => ['format' => 'editor', 'html' => '<p>Our own words</p>', 'text' => ''],
        ])->assertOk();

        $this->resendTemplates['tpl_news']['updated_at'] = '2026-11-10T00:00:00.000Z';

        $this->actingAs($this->manager())->postJson($import)->assertOk()
            ->assertJsonPath('imported', [])
            ->assertJsonPath('updated', [])
            ->assertJsonPath('skipped', [['name' => 'News', 'reason' => 'edited here since the last import']]);

        $this->assertSame(1, EmailTemplate::query()->count());
        $this->assertStringContainsString('Our own words', $template->fresh()->content['html']);
    }

    public function test_mode_round_trips_and_the_import_bookkeeping_is_kept_with_it(): void
    {
        $source = ['provider' => 'resend', 'id' => 'tpl_1', 'updated_at' => '2026-11-01T00:00:00.000Z', 'hash' => hash('sha256', self::HTML)];
        $template = $this->editorTemplate(['subject' => 'News for {first_name}', 'source' => $source]);
        $html = '<html><body><table width="600"><tr><td><h1>Coded {first_name}</h1></td></tr></table></body></html>';

        $this->actingAs($this->manager())->postJson($this->url($template), [
            'content' => ['format' => 'editor', 'doc' => null, 'html' => $html, 'text' => '', 'mode' => 'html'],
        ])->assertOk()
            ->assertJsonPath('template.content.mode', 'html')
            ->assertJsonPath('template.source', 'resend')
            ->assertJsonPath('template.subject', 'News for {first_name}');

        $stored = $template->fresh()->content;
        $this->assertSame(['format', 'doc', 'html', 'text', 'mode', 'subject', 'source'], array_keys($stored));
        $this->assertSame('html', $stored['mode']);
        $this->assertSame($source, $stored['source']);
        $this->assertNull($stored['doc']);

        $this->actingAs($this->manager())->postJson($this->url($template), [
            'content' => ['format' => 'editor', 'doc' => ['type' => 'doc', 'content' => []], 'html' => $html, 'text' => 'Coded', 'mode' => 'visual'],
        ])->assertOk()->assertJsonPath('template.content.mode', 'visual');

        $this->actingAs($this->manager())->postJson($this->url($template), [
            'content' => ['format' => 'editor', 'html' => $html, 'mode' => 'code'],
        ])->assertStatus(422);
        $this->assertSame('visual', $template->fresh()->content['mode']);
    }
}
