<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Validation\Rule;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The one reading of a campaign's (or template's) `content` format.
 *
 * TWO FORMATS. The block editor stores a JSON LIST of blocks, rendered by
 * `EmailCampaignRenderer::render()`. The React Email editor stores a JSON
 * OBJECT:
 *
 *     {"format": "editor", "doc": <Tiptap JSON|null>, "html": "<email html>", "text": "<plain text>"}
 *
 * `html` is the editor's complete email document (or, for a fresh campaign,
 * the starter body fragment); `doc` is only for re-editing and is never
 * rendered. Which editor a new campaign starts in is `email_campaigns.editor`.
 */
class EmailContent
{
    public const FORMAT_EDITOR = 'editor';

    /** Bytes of JSON a Tiptap document may take. */
    public const DOC_MAX_BYTES = 1000000;

    public const HTML_MAX = 512000;

    public const TEXT_MAX = 200000;

    public const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'columns'];

    /** Is this content the React Email editor's object (rather than a list of blocks)? */
    public static function isEditor(?array $content): bool
    {
        return is_array($content) && ($content['format'] ?? null) === self::FORMAT_EDITOR;
    }

    /** The editor new campaigns start in: 'blocks' or 'editor'. */
    public static function configuredEditor(): string
    {
        return ModuleConfig::get('email_campaigns.editor', 'blocks') === self::FORMAT_EDITOR
            ? self::FORMAT_EDITOR
            : 'blocks';
    }

    /**
     * Validation rules for a posted `content`. An object that names a `format`
     * is judged as editor content (so `format: "nonsense"` is a 422, not a
     * block list); anything else as blocks, exactly as before.
     *
     * @param  mixed  $raw  `$request->input('content')`
     * @return array<string, array<int, mixed>>
     */
    public static function rules($raw, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        if (is_array($raw) && array_key_exists('format', $raw)) {
            return [
                'content' => [$presence, 'array'],
                'content.format' => ['required', 'string', Rule::in([self::FORMAT_EDITOR])],
                'content.doc' => ['nullable', 'array'],
                'content.html' => ['required', 'string', 'max:' . self::HTML_MAX],
                'content.text' => ['nullable', 'string', 'max:' . self::TEXT_MAX],
            ];
        }

        return [
            'content' => [$presence, 'array', 'max:100'],
            'content.*.type' => [$required ? 'required' : 'required_with:content', 'string', 'in:' . implode(',', self::BLOCK_TYPES)],
        ];
    }

    /** A sentence when the editor's Tiptap document is too big to keep, else null. */
    public static function docProblem(array $raw): ?string
    {
        $doc = $raw['doc'] ?? null;

        if ($doc === null) {
            return null;
        }

        $json = json_encode($doc);

        return $json === false || strlen($json) > self::DOC_MAX_BYTES
            ? 'This email is too large to save. Split it into shorter emails or remove some images.'
            : null;
    }

    /**
     * Editor content as it is stored: the four keys and nothing else, the html
     * cleaned (a document stays a document, the starter fragment a fragment).
     *
     * @return array{format: string, doc: ?array, html: string, text: string}
     */
    public static function storedEditor(array $raw, EmailEditorHtml $sanitiser): array
    {
        $html = (string) ($raw['html'] ?? '');

        return [
            'format' => self::FORMAT_EDITOR,
            'doc' => is_array($raw['doc'] ?? null) ? $raw['doc'] : null,
            'html' => $sanitiser->isDocument($html) ? $sanitiser->clean($html) : $sanitiser->cleanFragment($html),
            'text' => (string) ($raw['text'] ?? ''),
        ];
    }

    /**
     * A new campaign's editor content: a body fragment the front end loads
     * (`doc ?? html`) and re-saves as the editor's full output.
     *
     * @param  array<string, string>  $brand  `EmailCampaignRenderer::brand()`
     * @return array{format: string, doc: null, html: string, text: string}
     */
    public static function editorStarter(array $brand): array
    {
        $logo = ($brand['logo_url'] ?? '') !== ''
            ? '<p><img src="' . e($brand['logo_url']) . '" alt="' . e($brand['company'] ?? '') . '" style="display:block; max-height:40px; max-width:220px; border:0;"></p>'
            : '';

        return [
            'format' => self::FORMAT_EDITOR,
            'doc' => null,
            'html' => $logo
                . '<h1>Your headline</h1>'
                . '<p>Hi {first_name},</p>'
                . '<p>Write your message here.</p>',
            'text' => '',
        ];
    }
}
