<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The editor's blocks, as the email a mail client will actually draw.
 *
 * Tables and inline styles throughout, 600px wide, because that is what Outlook,
 * Gmail and Apple Mail agree on; a flexbox email is an email that arrives as a
 * column of fragments in half the inboxes it goes to.
 *
 * BLOCKS: heading, text, image, button, divider, spacer, columns. Anything else
 * is dropped rather than guessed at.
 *
 * MERGE TAGS are typed as `{first_name}`, `{last_name}`, `{name}`, `{company}`,
 * `{email}`. For a broadcast they become Resend's contact syntax
 * (`{{{contact.first_name|there}}}`) and Resend fills them per recipient; for a
 * test send or a preview they are filled from a sample here.
 *
 * THE FOOTER IS NOT OPTIONAL: who it is from, their address, and an unsubscribe
 * link. Every commercial email needs the first two to identify the sender and
 * the third to be lawful, so no block layout can leave them out.
 *
 * Rich text is typed by staff, but it ends up in thousands of inboxes and in a
 * preview on this origin, so it is cut to a small allowlist here: no script, no
 * style, no event handler, and links only to http, https and mailto.
 */
class EmailCampaignRenderer
{
    public const TAGS = ['first_name', 'last_name', 'name', 'company', 'email'];

    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'span'];

    private EmailEditorHtml $editorHtml;

    public function __construct(?EmailEditorHtml $editorHtml = null)
    {
        $this->editorHtml = $editorHtml ?? new EmailEditorHtml();
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array{preview_text?: ?string, subject?: ?string}  $meta
     * @param  array<string, string>|null  $sample  fill merge tags from this; null = Resend syntax
     */
    public function render(array $blocks, array $meta = [], ?array $sample = null): string
    {
        $brand = $this->brand();
        $body = '';

        foreach ($blocks as $block) {
            if (is_array($block)) {
                $body .= $this->block($block, $brand, $sample);
            }
        }

        $header = $brand['logo_url'] !== ''
            ? '<img src="' . e($brand['logo_url']) . '" alt="' . e($brand['company']) . '" style="display:block; max-height:40px; max-width:220px; border:0;">'
            : '<span style="font-size:20px; font-weight:700; color:' . $brand['ink'] . ';">' . e($brand['company']) . '</span>';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="x-apple-disable-message-reformatting">'
            . '<title>' . e((string) ($meta['subject'] ?? '')) . '</title></head>'
            . '<body style="margin:0; padding:0; background-color:' . $brand['background'] . '; -webkit-font-smoothing:antialiased;">'
            . $this->previewHtml($meta)
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $brand['background'] . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:8px;">'
            . '<tr><td style="padding:24px 32px 8px 32px;">' . $header . '</td></tr>'
            . $body
            . '<tr>' . $this->footerCell($brand, $sample) . '</tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * The email and its plain-text part, whichever format the content is in:
     * the one place a reader of `content` turns it into what is sent.
     *
     * @param  array<int|string, mixed>  $content  blocks, or the editor's object
     * @param  array{preview_text?: ?string, subject?: ?string}  $meta
     * @param  array<string, string>|null  $sample  fill merge tags from this; null = Resend syntax
     * @return array{html: string, text: string}
     */
    public function compose(array $content, array $meta = [], ?array $sample = null): array
    {
        if (EmailContent::isEditor($content)) {
            return $this->composeEditor($content, $meta, $sample);
        }

        return ['html' => $this->render($content, $meta, $sample), 'text' => $this->text($content, $sample)];
    }

    /** Does this content say anything worth sending? */
    public function hasContent(array $content): bool
    {
        if (EmailContent::isEditor($content)) {
            return $this->editorHtml->hasContent((string) ($content['html'] ?? ''));
        }

        foreach ($content as $block) {
            $type = $block['type'] ?? null;

            if (($type === 'heading' && trim((string) ($block['text'] ?? '')) !== '')
                || ($type === 'text' && trim(strip_tags((string) ($block['html'] ?? ''))) !== '')
                || ($type === 'columns' && trim(strip_tags((string) ($block['left'] ?? '') . ($block['right'] ?? ''))) !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The React Email editor's document, cleaned again (it was cleaned on save;
     * record HTML is cleaned on read too), with the preview text first in the
     * body, the same footer as the block emails last, and the merge tags mapped.
     *
     * @return array{html: string, text: string}
     */
    private function composeEditor(array $content, array $meta, ?array $sample): array
    {
        $brand = $this->brand();
        $source = (string) ($content['html'] ?? '');

        $html = $this->editorHtml->isDocument($source)
            ? $this->editorHtml->clean($source)
            : $this->editorWrapper($this->editorHtml->cleanFragment($source), $brand, $meta);

        // Our serialiser writes exactly one <body …> and one </body>, and text
        // and attribute values are escaped, so these two edits cannot land
        // inside the editor's own markup.
        $preview = $this->previewHtml($meta);

        if ($preview !== '') {
            $html = preg_replace_callback('/<body\b[^>]*>/', fn ($m) => $m[0] . $preview, $html, 1);
        }

        $footer = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:0 12px 24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">'
            . '<tr>' . $this->footerCell($brand, $sample) . '</tr>'
            . '</table></td></tr></table>';

        $at = strrpos($html, '</body>');
        $html = $at === false ? $html . $footer : substr($html, 0, $at) . $footer . substr($html, $at);

        $text = trim((string) ($content['text'] ?? ''));

        if ($text === '') {
            $text = $this->editorHtml->text($source);
        }

        $out = [trim($this->tags($text, $sample, false))];
        array_push($out, ...$this->footerText($brand, $sample));

        return [
            'html' => $this->tags($html, $sample),
            'text' => implode("\n\n", array_filter($out, fn ($line) => $line !== '')),
        ];
    }

    /** A body fragment (the starter) inside the house frame: 600px, centred, brand colours. */
    private function editorWrapper(string $inner, array $brand, array $meta): string
    {
        return EmailEditorHtml::DOCTYPE
            . '<html lang="en"><head><meta content="text/html; charset=UTF-8" http-equiv="Content-Type">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="x-apple-disable-message-reformatting">'
            . '<title>' . e((string) ($meta['subject'] ?? '')) . '</title></head>'
            . '<body style="margin:0; padding:0; background-color:' . $brand['background'] . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $brand['background'] . ';"><tr><td align="center" style="padding:24px 12px 0 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:8px;">'
            . '<tr><td style="padding:24px 32px; font-family:' . self::FONT . '; font-size:15px; line-height:1.65; color:#374151;">' . $inner . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** The hidden preview-text line, or '' when there is none. */
    private function previewHtml(array $meta): string
    {
        $preview = trim((string) ($meta['preview_text'] ?? ''));

        return $preview !== ''
            ? '<div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">' . e($preview) . '</div>'
            : '';
    }

    /** The footer cell every email carries: who, where, and the unsubscribe link. */
    private function footerCell(array $brand, ?array $sample): string
    {
        $unsubscribe = $sample === null ? '{{{RESEND_UNSUBSCRIBE_URL}}}' : '#';

        $footerLines = array_filter([
            $brand['company'] !== '' ? e($brand['company']) : null,
            $brand['address'] !== '' ? e($brand['address']) : null,
            $brand['website'] !== '' ? '<a href="' . e($brand['website']) . '" style="color:' . $brand['muted'] . ';">' . e(preg_replace('#^https?://#', '', $brand['website'])) . '</a>' : null,
        ]);

        return '<td style="padding:24px 32px 28px 32px; font-family:' . self::FONT . '; font-size:12px; line-height:1.6; color:' . $brand['muted'] . '; border-top:1px solid #e5e7eb;">'
            . implode('<br>', $footerLines)
            . '<br><br>You are receiving this because you are one of our contacts. '
            . '<a href="' . $unsubscribe . '" style="color:' . $brand['muted'] . '; text-decoration:underline;">Unsubscribe</a>'
            . '</td>';
    }

    /** @return array<int, string> the plain-text footer lines */
    private function footerText(array $brand, ?array $sample): array
    {
        return [
            trim($brand['company'] . "\n" . $brand['address']),
            'Unsubscribe: ' . ($sample === null ? '{{{RESEND_UNSUBSCRIBE_URL}}}' : '#'),
        ];
    }

    /** One line (a subject) with its merge tags filled from `$sample`, or in Resend's syntax. */
    public function line(string $text, ?array $sample = null): string
    {
        return $this->tags($text, $sample, false);
    }

    /** The plain-text part, from the same blocks. */
    public function text(array $blocks, ?array $sample = null): string
    {
        $out = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            $piece = match ($type) {
                'heading' => (string) ($block['text'] ?? ''),
                'text' => html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) ($block['html'] ?? ''))), ENT_QUOTES | ENT_HTML5),
                'button' => trim((string) ($block['label'] ?? '')) . ': ' . (string) ($block['url'] ?? ''),
                'columns' => html_entity_decode(strip_tags((string) ($block['left'] ?? '') . "\n" . (string) ($block['right'] ?? '')), ENT_QUOTES | ENT_HTML5),
                default => '',
            };

            if (trim($piece) !== '') {
                $out[] = trim($this->tags($piece, $sample, false));
            }
        }

        array_push($out, ...$this->footerText($this->brand(), $sample));

        return implode("\n\n", array_filter($out, fn ($line) => $line !== ''));
    }

    private const FONT = "'Helvetica Neue', Helvetica, Arial, sans-serif";

    private function block(array $block, array $brand, ?array $sample): string
    {
        $align = $block['align'] ?? 'left';
        $align = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
        $cell = '<tr><td style="padding:8px 32px; font-family:' . self::FONT . '; text-align:' . $align . ';">';
        $end = '</td></tr>';

        switch ($block['type'] ?? null) {
            case 'heading':
                $level = (int) ($block['level'] ?? 1) === 2 ? 2 : 1;
                $size = $level === 1 ? '26px' : '20px';
                $text = $this->tags(e((string) ($block['text'] ?? '')), $sample);

                return $text === '' ? '' : $cell
                    . '<h' . $level . ' style="margin:8px 0 4px 0; font-size:' . $size . '; line-height:1.3; font-weight:700; color:' . $brand['ink'] . ';">' . $text . '</h' . $level . '>'
                    . $end;

            case 'text':
                $html = $this->tags($this->clean((string) ($block['html'] ?? ''), $brand), $sample);

                return trim(strip_tags($html)) === '' ? '' : $cell
                    . '<div style="font-size:15px; line-height:1.65; color:#374151;">' . $html . '</div>'
                    . $end;

            case 'image':
                $url = $this->url($block['url'] ?? null, false);

                if ($url === null) {
                    return '';
                }

                $width = max(20, min(100, (int) ($block['width'] ?? 100)));
                $img = '<img src="' . e($url) . '" alt="' . e((string) ($block['alt'] ?? '')) . '" width="' . (int) round(536 * $width / 100) . '" style="display:inline-block; width:' . $width . '%; max-width:100%; height:auto; border:0; border-radius:4px;">';
                $href = $this->url($block['href'] ?? null, true);

                return $cell . ($href ? '<a href="' . e($href) . '">' . $img . '</a>' : $img) . $end;

            case 'button':
                $url = $this->url($block['url'] ?? null, true);
                $label = $this->tags(e(trim((string) ($block['label'] ?? ''))), $sample);

                if ($url === null || $label === '') {
                    return '';
                }

                return $cell
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table; margin:8px 0;"><tr>'
                    . '<td align="center" bgcolor="' . $brand['button'] . '" style="border-radius:6px;">'
                    . '<a href="' . e($url) . '" style="display:inline-block; padding:12px 24px; font-family:' . self::FONT . '; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:6px;">' . $label . '</a>'
                    . '</td></tr></table>'
                    . $end;

            case 'divider':
                return '<tr><td style="padding:12px 32px;"><div style="border-top:1px solid #e5e7eb; font-size:0; line-height:0;">&nbsp;</div></td></tr>';

            case 'spacer':
                $height = max(8, min(80, (int) ($block['height'] ?? 24)));

                return '<tr><td style="height:' . $height . 'px; font-size:0; line-height:0;">&nbsp;</td></tr>';

            case 'columns':
                $left = $this->tags($this->clean((string) ($block['left'] ?? ''), $brand), $sample);
                $right = $this->tags($this->clean((string) ($block['right'] ?? ''), $brand), $sample);
                $style = 'width:50%; vertical-align:top; font-family:' . self::FONT . '; font-size:15px; line-height:1.65; color:#374151;';

                return '<tr><td style="padding:8px 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                    . '<td style="' . $style . ' padding-right:12px;">' . $left . '</td>'
                    . '<td style="' . $style . ' padding-left:12px;">' . $right . '</td>'
                    . '</tr></table></td></tr>';
        }

        return '';
    }

    /**
     * Merge tags. `$escaped` is true when the text is already HTML-escaped, so a
     * sample value is escaped to match; Resend fills its own tags safely.
     */
    private function tags(string $text, ?array $sample, bool $escaped = true): string
    {
        return preg_replace_callback('/\{\s*(first_name|last_name|name|company|email)\s*\}/i', function ($hit) use ($sample, $escaped) {
            $tag = strtolower($hit[1]);

            if ($sample !== null) {
                $value = (string) ($sample[$tag] ?? '');

                return $escaped ? e($value) : $value;
            }

            return match ($tag) {
                'first_name' => '{{{contact.first_name|there}}}',
                'last_name' => '{{{contact.last_name|}}}',
                'name' => '{{{contact.first_name|}}} {{{contact.last_name|}}}',
                'company' => '{{{contact.company|}}}',
                'email' => '{{{contact.email}}}',
            };
        }, $text);
    }

    /** Rich text, cut to the allowlist, links made safe and styled for email. */
    public function clean(string $html, ?array $brand = null): string
    {
        $brand = $brand ?? $this->brand();

        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('root') ?? $dom->documentElement;

        if ($root === null) {
            return e(strip_tags($html));
        }

        $this->walk($root, $brand);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function walk(\DOMNode $node, array $brand): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            // Dangerous containers go with their contents; anything else not on
            // the list is unwrapped, so its words survive.
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template'], true)) {
                $node->removeChild($child);

                continue;
            }

            $this->walk($child, $brand);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            $href = $tag === 'a' ? $this->url($child->getAttribute('href'), true) : null;

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->nodeName);
            }

            if ($tag === 'a') {
                if ($href === null) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                $child->setAttribute('href', $href);
                $child->setAttribute('style', 'color:' . $brand['button'] . '; text-decoration:underline;');
            } elseif ($tag === 'p') {
                $child->setAttribute('style', 'margin:0 0 12px 0;');
            } elseif (in_array($tag, ['ul', 'ol'], true)) {
                $child->setAttribute('style', 'margin:0 0 12px 0; padding-left:22px;');
            }
        }
    }

    /** http(s) only (and mailto for links); anything else is no URL at all. */
    private function url($value, bool $allowMailto): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        if ($allowMailto && preg_match('/^mailto:[^\s<>"]+@[^\s<>"]+$/i', $url)) {
            return $url;
        }

        return preg_match('#^https?://[^\s<>"]+$#i', $url) ? $url : null;
    }

    /** @return array<string, string> the house look, colours checked, URLs http(s) only */
    public function brand(): array
    {
        $brand = (array) ModuleConfig::get('email_campaigns.brand', []);
        $hex = fn ($value, $fallback) => preg_match('/^#[0-9a-f]{3,8}$/i', (string) $value) ? (string) $value : $fallback;

        return [
            'company' => trim((string) ($brand['company'] ?? config('app.name', ''))),
            'logo_url' => (string) ($this->url($brand['logo_url'] ?? null, false) ?? ''),
            'address' => trim((string) ($brand['address'] ?? '')),
            'website' => (string) ($this->url($brand['website'] ?? null, false) ?? ''),
            'accent' => $hex($brand['accent'] ?? null, '#3cbf7d'),
            // Buttons and links: dark enough for white text on it, and for it
            // as text on white, to pass 4.5:1. The light accent does neither.
            'button' => $hex($brand['button'] ?? null, '#1a7f57'),
            'ink' => $hex($brand['ink'] ?? null, '#0b2b2d'),
            'muted' => $hex($brand['muted'] ?? null, '#6b7280'),
            'background' => $hex($brand['background'] ?? null, '#f5f3ef'),
        ];
    }
}
