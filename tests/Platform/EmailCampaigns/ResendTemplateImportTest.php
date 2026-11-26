<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\EmailCampaigns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailTemplate;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailContent;

/** Import from Resend: the team's Resend templates copied in as editor templates. */
class ResendTemplateImportTest extends EmailCampaignTestCase
{
    private const IMPORT = self::BASE . '/templates/import-resend';

    /** A real 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const LOGO = 'https://cdn.resend.app/0b7d1c2e-1111-4a5b-9c8d-000000000001';

    private const HERO = 'https://cdn.resend.app/0b7d1c2e-2222-4a5b-9c8d-000000000002';

    private const FAILED = "an image could not be copied (it stays on Resend's server)";

    protected function setUp(): void
    {
        parent::setUp();

        $this->templatePageSize = 1;
        $this->resendTemplates = [
            'tpl_welcome' => [
                'id' => 'tpl_welcome',
                'name' => 'Welcome',
                'alias' => 'welcome',
                'status' => 'published',
                'subject' => 'Welcome, {{{FIRST_NAME}}}',
                'html' => '<html><body><h1>Hi {{{FIRST_NAME|there}}} {{{contact.last_name}}}</h1>'
                    . '<p>Your address is {{{ EMAIL }}} at {{{Contact.Company}}}.</p>'
                    . '<p>Buy {{{PRODUCT}}} for {{{PRICE|$10}}} by {{{DEADLINE}}}.</p>'
                    . '<script>alert(1)</script>'
                    . '<a href="{{{RESEND_UNSUBSCRIBE_URL}}}">Leave</a></body></html>',
                'text' => 'Hi {{{contact.first_name}}}, buy {{{PRODUCT}}} by {{{DEADLINE}}}.',
                'variables' => [
                    ['key' => 'DEADLINE', 'type' => 'string', 'fallback_value' => 'Friday <soon>'],
                    ['key' => 'PRODUCT', 'type' => 'string', 'fallback_value' => null],
                ],
                'created_at' => '2026-11-01T00:00:00.000Z',
                'updated_at' => '2026-11-02T00:00:00.000Z',
            ],
            'tpl_draft' => [
                'id' => 'tpl_draft',
                'name' => 'Draft news',
                'status' => 'draft',
                'subject' => null,
                'html' => '<p>Draft for {{{first_name}}}</p>',
                'text' => null,
                'variables' => [],
                'created_at' => '2026-11-01T00:00:00.000Z',
                'updated_at' => '2026-11-03T00:00:00.000Z',
            ],
        ];
    }

    private function png(string $tail = ''): string
    {
        // Bytes after IEND still sniff as PNG: a second, distinct image.
        return base64_decode(self::PNG) . $tail;
    }

    /** Where EmailImageStore puts these bytes, and the URL it answers. */
    private function stored(string $bytes, string $ext = 'png'): array
    {
        $path = 'email-campaigns/resend/' . hash('sha256', $bytes) . '.' . $ext;
        $url = Storage::disk('public')->url($path);

        return [$path, preg_match('#^https?://#i', $url) ? $url : rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/')];
    }

    /** Both templates carry the logo; the draft also has a hero image. */
    private function withImages(): void
    {
        $this->resendTemplates['tpl_welcome']['html'] = '<html><body><img src="' . self::LOGO . '" alt="Logo"><p>Hi</p></body></html>';
        $this->resendTemplates['tpl_welcome']['text'] = 'Hi';
        $this->resendTemplates['tpl_draft']['html'] = '<table><tr><td><img src="' . self::LOGO . '" alt="Logo"></td></tr></table>'
            . '<p><a href="' . self::HERO . '"><img alt="Hero" src=\'' . self::HERO . '\' width="600"></a></p>'
            . '<p><img src="https://example.com/elsewhere.png" alt="Not Resend"></p>';
        $this->cdn[self::LOGO] = [$this->png()];
        $this->cdn[self::HERO] = [$this->png('hero'), 'image/png'];
    }

    private function imported(string $resendId): EmailTemplate
    {
        return EmailTemplate::query()->get()->first(fn (EmailTemplate $t) => (((array) $t->content)['source']['id'] ?? null) === $resendId);
    }

    public function test_it_imports_every_template_and_maps_the_variables(): void
    {
        $response = $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('imported', ['Welcome', 'Draft news'])
            ->assertJsonPath('updated', [])
            ->assertJsonPath('skipped', [])
            ->assertJsonPath('stopped', null);

        // Two list pages, then each template in full.
        $this->assertSame(['GET templates', 'GET templates', 'GET templates/tpl_welcome', 'GET templates/tpl_draft'], $this->calls);

        $warnings = collect($response->json('warnings'))->where('name', 'Welcome')->pluck('text')->all();
        $this->assertContains("has its own unsubscribe link as well as the footer's", $warnings);
        $this->assertContains('{{{PRODUCT}}} has no value here; edit the template', $warnings);
        $this->assertCount(2, $warnings, 'each warning once');

        $content = $this->imported('tpl_welcome')->content;
        $this->assertTrue(EmailContent::isEditor($content));
        $this->assertNull($content['doc']);
        $html = $content['html'];
        $this->assertStringContainsString('Hi {first_name} {last_name}', $html);
        $this->assertStringContainsString('Your address is {email} at {company}.', $html);
        $this->assertStringContainsString('Buy {{{PRODUCT}}} for $10 by Friday &lt;soon&gt;.', $html);
        $this->assertStringContainsString('href="{{{RESEND_UNSUBSCRIBE_URL}}}"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertSame('Hi {first_name}, buy {{{PRODUCT}}} by Friday <soon>.', $content['text']);
        $this->assertSame('Welcome, {{{FIRST_NAME}}}', $content['subject']);
        $this->assertSame(['provider' => 'resend', 'id' => 'tpl_welcome', 'updated_at' => '2026-11-02T00:00:00.000Z', 'hash' => hash('sha256', $html)], $content['source']);

        $this->assertStringContainsString('Draft for {first_name}', $this->imported('tpl_draft')->content['html']);

        $this->actingAs($this->manager())->getJson(self::BASE . '/templates')->assertOk()
            ->assertJsonPath('templates.0.name', 'Draft news')
            ->assertJsonPath('templates.0.source', 'resend')
            ->assertJsonPath('templates.0.format', 'editor');
    }

    public function test_a_second_run_is_idempotent_and_a_newer_template_updates_an_untouched_import(): void
    {
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk();
        $before = $this->imported('tpl_draft')->content;

        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', [])
            ->assertJsonPath('updated', [])
            ->assertJsonPath('unchanged', ['Welcome', 'Draft news'])
            ->assertJsonPath('warnings', []);
        $this->assertSame(2, EmailTemplate::query()->count());

        $this->resendTemplates['tpl_draft']['html'] = '<p>Second draft</p>';
        $this->resendTemplates['tpl_draft']['updated_at'] = '2026-11-10T00:00:00.000Z';

        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', [])
            ->assertJsonPath('updated', ['Draft news']);

        $after = $this->imported('tpl_draft')->content;
        $this->assertStringContainsString('Second draft', $after['html']);
        $this->assertSame('2026-11-10T00:00:00.000Z', $after['source']['updated_at']);
        $this->assertNotSame($before['source']['hash'], $after['source']['hash']);
        $this->assertSame(2, EmailTemplate::query()->count());
    }

    public function test_a_template_edited_here_is_left_alone(): void
    {
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk();

        $row = $this->imported('tpl_draft');
        $content = $row->content;
        $content['html'] = '<p>Our own words</p>';
        $row->content = $content;
        $row->save();

        $this->resendTemplates['tpl_draft']['updated_at'] = '2026-11-10T00:00:00.000Z';

        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('updated', [])
            ->assertJsonPath('skipped', [['name' => 'Draft news', 'reason' => 'edited here since the last import']]);

        $this->assertSame('<p>Our own words</p>', $this->imported('tpl_draft')->content['html']);
    }

    public function test_a_name_taken_by_a_template_of_our_own_gets_a_suffix_and_empty_html_is_skipped(): void
    {
        EmailTemplate::create(['name' => 'Welcome', 'content' => [['type' => 'text', 'html' => '<p>x</p>']]]);
        $this->resendTemplates['tpl_draft']['html'] = '';

        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', ['Welcome (Resend)'])
            ->assertJsonPath('skipped', [['name' => 'Draft news', 'reason' => 'no HTML (a React-only template?)']]);
    }

    public function test_a_429_stops_the_import_and_keeps_what_was_done(): void
    {
        // Two list pages and the first template, then Resend says slow down.
        $this->throttleAfter = 3;

        $response = $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('imported', ['Welcome']);

        $this->assertStringContainsString('slow down after 1 template was read', (string) $response->json('stopped'));
        $this->assertSame(1, EmailTemplate::query()->count());

        // Run again: it finishes.
        $this->throttleAfter = null;
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', ['Draft news'])
            ->assertJsonPath('stopped', null);
        $this->assertSame(2, EmailTemplate::query()->count());
    }

    public function test_resend_down_is_ok_false_and_not_connected_is_422(): void
    {
        $this->failWith = 503;
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'Resend could not list the templates: Resend refused it.');
        $this->assertSame(0, EmailTemplate::query()->count());

        config(['visns-packages.email_campaigns.api_key' => '']);
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertStatus(422)
            ->assertJsonPath('message', 'Resend is not connected (RESEND_KEY).');
    }

    public function test_an_access_only_user_may_not_import(): void
    {
        $this->actingAs($this->staff('Email Campaigns Access'))->postJson(self::IMPORT)->assertForbidden();
        $this->assertSame([], $this->calls);
    }

    public function test_a_campaign_from_an_imported_template_gets_editor_content_without_the_bookkeeping(): void
    {
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk();
        $template = $this->imported('tpl_welcome');

        $created = $this->actingAs($this->manager())->postJson(self::BASE . '/campaigns', ['name' => 'From Resend', 'template_id' => $template->id])->assertCreated();
        $content = EmailCampaign::findOrFail($created->json('campaign.id'))->content;

        // No source/subject; an imported email opens as code (mode html).
        $this->assertSame(['format', 'doc', 'html', 'text', 'mode'], array_keys($content));
        $this->assertSame('html', $content['mode']);
        $this->assertSame($template->content['html'], $content['html']);
    }

    public function test_resend_images_are_copied_to_our_disk_and_the_html_points_at_the_copy(): void
    {
        Storage::fake('public');
        $this->withImages();

        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', ['Welcome', 'Draft news'])
            ->assertJsonPath('warnings', []);

        [$logoPath, $logoUrl] = $this->stored($this->png());
        [$heroPath, $heroUrl] = $this->stored($this->png('hero'));

        Storage::disk('public')->assertExists($logoPath);
        Storage::disk('public')->assertExists($heroPath);
        $this->assertSame($this->png(), Storage::disk('public')->get($logoPath));
        // The same image in two templates: fetched once, stored once.
        $this->assertSame([self::LOGO, self::HERO], $this->cdnFetches);
        $this->assertCount(2, Storage::disk('public')->allFiles('email-campaigns'));

        $welcome = $this->imported('tpl_welcome')->content['html'];
        $draft = $this->imported('tpl_draft')->content['html'];
        $this->assertStringStartsWith('http', $logoUrl);
        $this->assertStringContainsString('src="' . $logoUrl . '"', $welcome);
        $this->assertStringContainsString('src="' . $logoUrl . '"', $draft);
        $this->assertStringContainsString('src="' . $heroUrl . '"', $draft);
        // Every occurrence of the URL, the link around the image too.
        $this->assertStringContainsString('href="' . $heroUrl . '"', $draft);
        $this->assertStringNotContainsString('cdn.resend.app', $welcome . $draft);
        // Another host is left alone.
        $this->assertStringContainsString('src="https://example.com/elsewhere.png"', $draft);
        $this->assertSame(hash('sha256', $draft), $this->imported('tpl_draft')->content['source']['hash']);

        // A second run with the same Resend content writes identical html.
        $this->cdnFetches = [];
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', [])
            ->assertJsonPath('updated', [])
            ->assertJsonPath('unchanged', ['Welcome', 'Draft news'])
            ->assertJsonPath('warnings', []);
        $this->assertSame([self::LOGO, self::HERO], $this->cdnFetches, 'fetched once a run');
        $this->assertCount(2, Storage::disk('public')->allFiles('email-campaigns'));
        $this->assertSame($draft, $this->imported('tpl_draft')->content['html']);
    }

    public function test_an_image_that_cannot_be_copied_keeps_resends_url_with_a_warning(): void
    {
        Storage::fake('public');
        config(['visns-packages.email_campaigns.image_max_kb' => 1]);

        $page = 'https://cdn.resend.app/not-an-image';
        $big = 'https://cdn.resend.app/too-big';
        $svg = 'https://cdn.resend.app/vector';
        $gone = 'https://cdn.resend.app/gone';
        $this->resendTemplates['tpl_welcome']['html'] = '<p><img src="' . $page . '"><img src="' . $big . '"><img src="' . $svg . '"><img src="' . $gone . '"><img src="' . self::LOGO . '"></p>';
        $this->resendTemplates['tpl_welcome']['text'] = null;
        unset($this->resendTemplates['tpl_draft']);
        $this->cdn[$page] = ['<!doctype html><html><body>Blocked</body></html>', 'image/png'];
        $this->cdn[$big] = [$this->png(str_repeat('x', 2048))];
        $this->cdn[$svg] = ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>', 'image/svg+xml'];
        $this->cdn[self::LOGO] = [$this->png()];

        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', ['Welcome'])
            ->assertJsonPath('warnings', [['name' => 'Welcome', 'text' => self::FAILED]]);

        $html = $this->imported('tpl_welcome')->content['html'];
        foreach ([$page, $big, $svg, $gone] as $url) {
            $this->assertStringContainsString('src="' . $url . '"', $html);
        }
        [$logoPath, $logoUrl] = $this->stored($this->png());
        $this->assertStringContainsString('src="' . $logoUrl . '"', $html);
        $this->assertSame([$logoPath], Storage::disk('public')->allFiles('email-campaigns'));
    }

    public function test_a_template_imported_before_rehosting_is_updated_with_copied_images(): void
    {
        Storage::fake('public');
        $this->withImages();

        // The import as it was: no rehosting.
        config(['visns-packages.email_campaigns.import.rehost_hosts' => []]);
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()->assertJsonPath('imported', ['Welcome', 'Draft news']);
        $this->assertStringContainsString(self::LOGO, $this->imported('tpl_welcome')->content['html']);
        $this->assertSame([], $this->cdnFetches);

        // Same Resend content and updated_at; the improved import rewrites it.
        config(['visns-packages.email_campaigns.import.rehost_hosts' => ['cdn.resend.app']]);
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('imported', [])
            ->assertJsonPath('updated', ['Welcome', 'Draft news'])
            ->assertJsonPath('unchanged', []);

        [, $logoUrl] = $this->stored($this->png());
        $content = $this->imported('tpl_welcome')->content;
        $this->assertStringContainsString('src="' . $logoUrl . '"', $content['html']);
        $this->assertStringNotContainsString('cdn.resend.app', $content['html']);
        $this->assertSame(hash('sha256', $content['html']), $content['source']['hash']);
        $this->assertSame(2, EmailTemplate::query()->count());

        // And then it settles.
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('updated', [])
            ->assertJsonPath('unchanged', ['Welcome', 'Draft news']);
    }

    public function test_an_edited_template_with_images_is_skipped_and_nothing_is_fetched_for_it(): void
    {
        Storage::fake('public');
        $this->withImages();
        unset($this->resendTemplates['tpl_welcome']);
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk();

        $row = $this->imported('tpl_draft');
        $content = $row->content;
        $content['html'] = '<p>Our own words</p>';
        $row->content = $content;
        $row->save();

        $this->cdnFetches = [];
        $this->actingAs($this->manager())->postJson(self::IMPORT)->assertOk()
            ->assertJsonPath('updated', [])
            ->assertJsonPath('skipped', [['name' => 'Draft news', 'reason' => 'edited here since the last import']]);
        $this->assertSame([], $this->cdnFetches);
        $this->assertSame('<p>Our own words</p>', $this->imported('tpl_draft')->content['html']);
    }

    public function test_an_uploaded_image_follows_the_same_rule(): void
    {
        Storage::fake('public');

        $url = $this->actingAs($this->manager())
            ->post(self::BASE . '/images', ['image' => UploadedFile::fake()->createWithContent('logo.svg', $this->png())], ['Accept' => 'application/json'])
            ->assertCreated()->json('url');
        $this->assertMatchesRegularExpression('#^https?://.+/email-campaigns/\d{4}/\d{2}/[A-Za-z0-9]{24}\.png$#', $url);
        $this->assertCount(1, Storage::disk('public')->allFiles('email-campaigns'));

        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
        $this->actingAs($this->manager())
            ->post(self::BASE . '/images', ['image' => UploadedFile::fake()->createWithContent('logo.png', $svg)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Use a JPEG, PNG, GIF or WebP image.');
        $this->assertCount(1, Storage::disk('public')->allFiles('email-campaigns'));
    }
}
