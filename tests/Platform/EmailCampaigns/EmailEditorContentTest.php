<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\EmailCampaigns;

use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailList;
use Visnsstudio\VisnsPackages\Models\EmailTemplate;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailCampaignRenderer;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailContent;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailEditorHtml;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailListSync;

/** The React Email editor's content format beside the block format. */
class EmailEditorContentTest extends EmailCampaignTestCase
{
    /** React Email editor output (trimmed). */
    private const FIXTURE = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html dir="ltr" lang="en"><head><meta content="text/html; charset=UTF-8" http-equiv="Content-Type"/><meta name="x-apple-disable-message-reformatting"/><style>@media (prefers-color-scheme: dark){li::marker{color:#c4c4c4}}</style></head><body dir="ltr" lang="en" style="background-color:#ffffff"><table border="0" width="100%" cellpadding="0" cellspacing="0" role="presentation" align="center"><tbody><tr><td style="font-family:-apple-system, sans-serif;font-size:1em;line-height:155%;background-color:#ffffff"><table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;color:#000000;background-color:#ffffff"><tbody><tr style="width:100%"><td style="padding-top:0px"><h1 style="margin:0;font-size:2.25em;font-weight:600">Hello {first_name}</h1><p style="margin:0;padding-top:0.5em">Some <strong>bold</strong> text and a <a href="https://example.com" rel="noopener noreferrer nofollow" style="color:#0670DB" target="_blank">link</a>.</p><hr style="width:100%;border:none;border-top:1px solid #eaeaea"/></td></tr></tbody></table></td></tr></tbody></table></body></html>';

    private const TEXT = "HELLO {FIRST_NAME}\n\nSome bold text and a link [https://example.com].";

    private function editorContent(?string $html = null, string $text = self::TEXT): array
    {
        return ['format' => 'editor', 'doc' => ['type' => 'doc', 'content' => []], 'html' => $html ?? self::FIXTURE, 'text' => $text];
    }

    private function editorCampaign(array $overrides = []): EmailCampaign
    {
        $this->actingAs($this->manager())->postJson(self::BASE . '/lists', ['name' => 'Clients', 'kind' => 'crm', 'groups' => ['c1', 'c2']])->assertCreated();
        app(EmailListSync::class)->sleepUsing(fn () => null)->tick();

        return EmailCampaign::create(array_merge([
            'name' => 'Editor news',
            'subject' => 'News for {first_name}',
            'preview_text' => 'The short version',
            'from_name' => 'Omnia Global',
            'from_email' => 'news@omnia.test',
            'list_id' => EmailList::query()->value('id'),
            'content' => $this->editorContent(),
        ], $overrides));
    }

    /* -- the sanitiser ---------------------------------------------------- */

    public function test_the_sanitiser_strips_anything_that_can_run_or_fetch(): void
    {
        $html = app(EmailEditorHtml::class)->clean(
            '<html><head><style>p{}</style><link rel="stylesheet" href="https://x.test/a.css"></head><body>'
            . '<table class="t" id="x" data-x="1" style="width:100%"><tr><td style="color:#111" onmouseover="steal()">'
            . '<p onclick="x()">Hi<script>alert(1)</script></p>'
            . '<a href="javascript:alert(1)">bad</a><a href=" JaVaScRiPt:alert(1)">bad2</a>'
            . '<a href="https://ok.test/?a=1&amp;b=2">good</a><a href="mailto:a@b.test">mail</a><a href="{first_name}">tag</a>'
            . '<img src="data:image/png;base64,AAAA" alt="d"><img src="https://ok.test/i.png" alt="ok">'
            . '<span style="width:exp/* x */ression(alert(1))">e</span><span style="background:URL (https://x.test/t.gif)">u</span>'
            . '<custom-tag><b>unwrapped</b></custom-tag><!-- a comment --><iframe src="https://x.test">framed</iframe>'
            . '<form><input value="x"><button>press</button></form>'
            . '</td></tr></table></body></html>'
        );

        foreach (['<script', '<style', '<link', 'onclick', 'onmouseover', 'class=', 'id=', 'data-x', 'javascript:', 'JaVaScRiPt', 'data:image', 'ression', 'URL', 'custom-tag', 'a comment', 'iframe', 'framed', 'press', '<form', '<input'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }

        $this->assertStringStartsWith(EmailEditorHtml::DOCTYPE, $html);
        $this->assertStringContainsString('<table style="width:100%"><tr><td style="color:#111">', $html);
        $this->assertStringContainsString('<b>unwrapped</b>', $html);
        $this->assertStringContainsString('href="https://ok.test/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
        // Never percent-encoded: a merge-tag link still works.
        $this->assertStringContainsString('href="{first_name}"', $html);
        $this->assertStringContainsString('src="https://ok.test/i.png"', $html);
    }

    public function test_react_email_output_survives_cleaning_with_its_tables_and_styles(): void
    {
        $html = app(EmailEditorHtml::class)->clean(self::FIXTURE);

        $this->assertStringStartsWith(EmailEditorHtml::DOCTYPE . '<html dir="ltr" lang="en">', $html);
        $this->assertStringContainsString('<meta content="text/html; charset=UTF-8" http-equiv="Content-Type">', $html);
        $this->assertStringContainsString('<body dir="ltr" lang="en" style="background-color:#ffffff">', $html);
        $this->assertStringContainsString('<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;color:#000000;background-color:#ffffff">', $html);
        $this->assertStringContainsString('<h1 style="margin:0;font-size:2.25em;font-weight:600">Hello {first_name}</h1>', $html);
        $this->assertStringContainsString('<a href="https://example.com" rel="noopener noreferrer nofollow" style="color:#0670DB" target="_blank">link</a>', $html);
        $this->assertStringContainsString('<hr style="width:100%;border:none;border-top:1px solid #eaeaea">', $html);
        $this->assertStringNotContainsString('prefers-color-scheme', $html);

        // Cleaning is stable: clean(clean(x)) === clean(x).
        $this->assertSame($html, app(EmailEditorHtml::class)->clean($html));
    }

    public function test_non_ascii_text_survives(): void
    {
        $html = app(EmailEditorHtml::class)->clean('<html><body><p>Café — naïve ✓</p></body></html>');

        $this->assertStringContainsString('<p>Café — naïve ✓</p>', $html);
    }

    /* -- composing -------------------------------------------------------- */

    public function test_compose_adds_the_preview_text_the_footer_and_maps_the_tags(): void
    {
        $email = app(EmailCampaignRenderer::class)->compose($this->editorContent(), ['subject' => 'S', 'preview_text' => 'The short version']);

        $this->assertMatchesRegularExpression('#<body[^>]*><div style="display:none;[^"]*">The short version</div>#', $email['html']);
        $this->assertStringContainsString('Hello {{{contact.first_name|there}}}</h1>', $email['html']);
        $this->assertStringContainsString('1 Hay St, Perth WA 6000', $email['html']);
        $this->assertStringContainsString('href="{{{RESEND_UNSUBSCRIBE_URL}}}"', $email['html']);
        // The footer is the last thing in the body.
        $this->assertMatchesRegularExpression('#Unsubscribe</a></td></tr></table></td></tr></table></body></html>$#', $email['html']);
        $this->assertSame(1, substr_count($email['html'], '<body'));

        // The text copy's upper-cased heading still carries its tag.
        $this->assertStringStartsWith('HELLO {{{contact.first_name|there}}}', $email['text']);
        $this->assertStringEndsWith("Omnia Global\n1 Hay St, Perth WA 6000\n\nUnsubscribe: {{{RESEND_UNSUBSCRIBE_URL}}}", $email['text']);

        $sample = app(EmailCampaignRenderer::class)->compose($this->editorContent(), [], ['first_name' => 'Kim <b>']);

        $this->assertStringContainsString('Hello Kim &lt;b&gt;</h1>', $sample['html']);
        $this->assertStringNotContainsString('{{{', $sample['html']);
        $this->assertStringNotContainsString('<div style="display:none;', $sample['html']);
        $this->assertStringStartsWith('HELLO Kim <b>', $sample['text']);
        $this->assertStringEndsWith('Unsubscribe: #', $sample['text']);
    }

    public function test_a_body_fragment_is_framed_in_the_house_look(): void
    {
        $email = app(EmailCampaignRenderer::class)->compose(['format' => 'editor', 'doc' => null, 'html' => '<h1>Hi {first_name}</h1><script>x()</script>', 'text' => '']);

        $this->assertStringStartsWith(EmailEditorHtml::DOCTYPE, $email['html']);
        $this->assertStringContainsString('max-width:600px', $email['html']);
        $this->assertStringContainsString('background-color:#f5f3ef', $email['html']);
        $this->assertStringContainsString('<h1>Hi {{{contact.first_name|there}}}</h1>', $email['html']);
        $this->assertStringNotContainsString('<script', $email['html']);
        // No text copy from the editor: one is read from the html.
        $this->assertStringStartsWith('Hi {{{contact.first_name|there}}}', $email['text']);
    }

    /* -- sending ---------------------------------------------------------- */

    public function test_send_passes_the_editor_email_to_resend_and_snapshots_it(): void
    {
        $campaign = $this->editorCampaign();

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')->assertOk();

        $broadcast = $this->sent('POST', 'broadcasts');
        $this->assertStringContainsString('Hello {{{contact.first_name|there}}}</h1>', $broadcast['html']);
        $this->assertStringContainsString('{{{RESEND_UNSUBSCRIBE_URL}}}', $broadcast['html']);
        $this->assertStringStartsWith('HELLO {{{contact.first_name|there}}}', $broadcast['text']);
        $this->assertSame($broadcast['html'], $campaign->fresh()->html);
    }

    public function test_the_test_send_fills_the_sample(): void
    {
        $campaign = $this->editorCampaign();

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/test')->assertOk();

        $email = $this->sent('POST', 'emails');
        $this->assertStringContainsString('Hello Dana</h1>', $email['html']);
        $this->assertStringNotContainsString('{{{', $email['html']);
        $this->assertStringStartsWith('HELLO Dana', $email['text']);
    }

    public function test_an_empty_editor_body_blocks_the_send(): void
    {
        $empty = '<!DOCTYPE html><html><head></head><body><table><tr><td><p> &nbsp; </p></td></tr></table></body></html>';
        $campaign = $this->editorCampaign(['content' => $this->editorContent($empty, '')]);

        $this->actingAs($this->manager())
            ->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')
            ->assertStatus(422)
            ->assertJsonFragment(['Add some content: at least a heading or a paragraph.']);

        $this->assertFalse(app(EmailCampaignRenderer::class)->hasContent($this->editorContent($empty)));
        $this->assertTrue(app(EmailCampaignRenderer::class)->hasContent($this->editorContent('<body><img src="https://x.test/i.png"></body>')));
    }

    public function test_the_preview_reads_editor_content_from_the_request_or_the_record(): void
    {
        $campaign = $this->editorCampaign();
        $me = $this->manager();

        $stored = $this->actingAs($me)->postJson(self::BASE . '/campaigns/' . $campaign->id . '/preview')->assertOk();
        $this->assertStringContainsString('Hello Dana</h1>', $stored->json('html'));
        $this->assertSame('News for Dana', $stored->json('subject'));

        $posted = $this->actingAs($me)->postJson(self::BASE . '/campaigns/' . $campaign->id . '/preview', [
            'content' => $this->editorContent('<html><body><p onclick="x()">Unsaved {FIRST_NAME}</p></body></html>'),
        ])->assertOk();

        $this->assertStringContainsString('<p>Unsaved Dana</p>', $posted->json('html'));
        $this->assertStringNotContainsString('onclick', $posted->json('html'));
    }

    /* -- saving ----------------------------------------------------------- */

    public function test_update_and_templates_store_editor_content_cleaned(): void
    {
        $campaign = $this->editorCampaign(['content' => []]);
        $dirty = str_replace('<h1 ', '<h1 onclick="x()" ', self::FIXTURE);
        $content = $this->editorContent($dirty) + ['extra' => 'dropped'];

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, ['content' => $content])->assertOk()
            ->assertJsonPath('campaign.content.format', 'editor')
            ->assertJsonPath('campaign.content.doc.type', 'doc');

        $stored = $campaign->fresh()->content;
        $this->assertSame(['format', 'doc', 'html', 'text'], array_keys($stored));
        $this->assertSame(app(EmailEditorHtml::class)->clean(self::FIXTURE), $stored['html']);
        $this->assertSame(self::TEXT, $stored['text']);

        $template = $this->actingAs($this->manager())->postJson(self::BASE . '/templates', ['name' => 'Editor', 'content' => $content])->assertCreated();
        $saved = EmailTemplate::findOrFail($template->json('template.id'))->content;
        $this->assertTrue(EmailContent::isEditor($saved));
        $this->assertStringNotContainsString('onclick', $saved['html']);

        $this->actingAs($this->manager())->getJson(self::BASE . '/templates')->assertOk()
            ->assertJsonPath('templates.0.format', 'editor')
            ->assertJsonPath('templates.0.blocks', null);

        // A campaign started from it copies it whatever its format.
        $copy = $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns', ['name' => 'From template', 'template_id' => $template->json('template.id')])->assertCreated();
        $this->assertSame($saved, EmailCampaign::findOrFail($copy->json('campaign.id'))->content);

        // Duplicate copies it as is.
        $dup = $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/duplicate')->assertCreated();
        $this->assertSame($stored, EmailCampaign::findOrFail($dup->json('campaign.id'))->content);
    }

    public function test_format_garbage_is_refused(): void
    {
        $campaign = $this->editorCampaign();
        $post = fn (array $content) => $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, ['content' => $content]);

        $post(['format' => 'nonsense', 'html' => '<p>x</p>'])->assertStatus(422);
        $post(['format' => 'editor'])->assertStatus(422);
        $post(['format' => 'editor', 'html' => ['not' => 'a string']])->assertStatus(422);
        $post(['format' => 'editor', 'html' => '<p>x</p>', 'doc' => 'a string'])->assertStatus(422);
        $post(['format' => 'editor', 'html' => str_repeat('x', EmailContent::HTML_MAX + 1)])->assertStatus(422);
        $post(['format' => 'editor', 'html' => '<p>x</p>', 'doc' => ['t' => str_repeat('x', EmailContent::DOC_MAX_BYTES)]])
            ->assertStatus(422)->assertJsonPath('message', 'This email is too large to save. Split it into shorter emails or remove some images.');
        // Unknown block types are still refused.
        $post([['type' => 'script', 'html' => 'x']])->assertStatus(422);
        $this->actingAs($this->manager())->postJson(self::BASE . '/templates', ['name' => 'Bad', 'content' => ['format' => 'nope']])->assertStatus(422);

        $this->assertSame($this->editorContent(), $campaign->fresh()->content);
    }

    public function test_a_doc_sent_as_a_json_string_keeps_its_whitespace_text_nodes(): void
    {
        $campaign = $this->editorCampaign();
        // A Tiptap document whose text nodes are whitespace only: the app's
        // TrimStrings / ConvertEmptyStringsToNull middleware would make " " null
        // inside an array, and Tiptap refuses a document with a null text node.
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Since moving to '],
            ['type' => 'text', 'text' => 'Verkada', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'text', 'text' => 'has seen:'],
        ]]]];

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, [
            'content' => ['format' => 'editor', 'doc' => json_encode($doc), 'html' => self::FIXTURE, 'text' => self::TEXT, 'mode' => 'visual'],
        ])->assertOk()->assertJsonPath('campaign.content.doc.type', 'doc');

        $this->assertSame($doc, $campaign->fresh()->content['doc']);

        // A string that is not a JSON object is refused, and so is a list.
        $post = fn ($doc) => $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, [
            'content' => ['format' => 'editor', 'doc' => $doc, 'html' => '<p>x</p>'],
        ]);
        $post('{not json')->assertStatus(422);
        $post('[1,2]')->assertStatus(422);
        $post(json_encode(['t' => str_repeat('x', EmailContent::DOC_MAX_BYTES)]))
            ->assertStatus(422)->assertJsonPath('message', 'This email is too large to save. Split it into shorter emails or remove some images.');
        $this->assertSame($doc, $campaign->fresh()->content['doc']);
    }

    public function test_mode_round_trips_on_a_campaign_and_a_bad_mode_is_refused(): void
    {
        $campaign = $this->editorCampaign();
        $post = fn (array $content) => $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, ['content' => $content]);

        $post(['format' => 'editor', 'doc' => null, 'html' => self::FIXTURE, 'text' => '', 'mode' => 'html'])->assertOk()
            ->assertJsonPath('campaign.content.mode', 'html')
            ->assertJsonPath('campaign.content.doc', null);
        $this->assertSame(['format', 'doc', 'html', 'text', 'mode'], array_keys($campaign->fresh()->content));

        $post($this->editorContent() + ['mode' => 'visual'])->assertOk()->assertJsonPath('campaign.content.mode', 'visual');

        $post($this->editorContent() + ['mode' => 'code'])->assertStatus(422);
        $post($this->editorContent() + ['mode' => ['html']])->assertStatus(422);
        $this->assertSame('visual', $campaign->fresh()->content['mode']);

        // No mode posted: stored exactly as before the HTML view existed.
        $post($this->editorContent())->assertOk();
        $this->assertSame(['format', 'doc', 'html', 'text'], array_keys($campaign->fresh()->content));
    }

    public function test_html_mode_content_with_no_text_sends_a_text_derived_from_the_html(): void
    {
        $campaign = $this->editorCampaign();

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id, [
            'content' => ['format' => 'editor', 'doc' => null, 'html' => self::FIXTURE, 'text' => '', 'mode' => 'html'],
        ])->assertOk();

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/test')->assertOk();
        $email = $this->sent('POST', 'emails');
        $this->assertStringContainsString('Hello Dana', $email['text']);
        $this->assertStringContainsString('Some bold text', $email['text']);

        $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns/' . $campaign->id . '/send')->assertOk();
        $broadcast = $this->sent('POST', 'broadcasts');
        $this->assertStringContainsString('Hello {{{contact.first_name|there}}}', $broadcast['text']);
        $this->assertStringContainsString('Some bold text', $broadcast['text']);
        $this->assertStringNotContainsString('<h1', $broadcast['text']);
    }

    public function test_a_campaign_from_a_template_keeps_its_mode(): void
    {
        $template = $this->actingAs($this->manager())->postJson(self::BASE . '/templates', [
            'name' => 'Coded',
            'content' => ['format' => 'editor', 'doc' => null, 'html' => self::FIXTURE, 'text' => '', 'mode' => 'html'],
        ])->assertCreated();

        $copy = $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns', ['name' => 'From coded', 'template_id' => $template->json('template.id')])->assertCreated();
        $this->assertSame('html', EmailCampaign::findOrFail($copy->json('campaign.id'))->content['mode']);
    }

    /* -- configuration ---------------------------------------------------- */

    public function test_a_new_campaign_starts_in_the_configured_editor(): void
    {
        $this->actingAs($this->manager())->getJson(self::BASE)->assertOk()
            ->assertJsonPath('settings.editor', 'blocks')
            ->assertJsonPath('settings.brand.button', '#1a7f57')
            ->assertJsonMissingPath('settings.brand.address');

        $blocks = $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns', ['name' => 'Blocks'])->assertCreated();
        $this->assertFalse(EmailContent::isEditor($blocks->json('campaign.content')));

        config([
            'visns-packages.email_campaigns.editor' => 'editor',
            'visns-packages.email_campaigns.brand.logo_url' => 'https://omnia.test/logo.png',
        ]);

        $this->actingAs($this->manager())->getJson(self::BASE)->assertOk()->assertJsonPath('settings.editor', 'editor');

        $created = $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns', ['name' => 'Editor'])->assertCreated();
        $content = EmailCampaign::findOrFail($created->json('campaign.id'))->content;

        $this->assertTrue(EmailContent::isEditor($content));
        $this->assertNull($content['doc']);
        $this->assertSame('', $content['text']);
        $this->assertStringContainsString('<img src="https://omnia.test/logo.png"', $content['html']);
        $this->assertStringContainsString('<h1>', $content['html']);
        $this->assertStringContainsString('<p>Hi {first_name},</p>', $content['html']);
        $this->assertTrue(app(EmailCampaignRenderer::class)->hasContent($content));
    }
}
