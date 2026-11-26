<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

/**
 * The React Email editor's HTML, cut to what an email may carry.
 *
 * Run on SAVE (what is stored is already clean) AND on READ (preview, test and
 * send clean it again before composing): record HTML is never trusted because
 * it was clean once.
 *
 * Tables, inline styles and the layout attributes email clients still read are
 * kept, because that is how React Email draws an email. Script, style, frames,
 * forms and the like go with their contents; any other unknown element is
 * unwrapped so its words survive; comments go. Attributes are an allowlist
 * (never `on*`, `class`, `id`, `data-*`), links are http, https, mailto or
 * exactly a merge tag, images http or https, and a style that could fetch or
 * run anything is dropped whole.
 *
 * THE OUTPUT IS SERIALISED HERE, not by libxml: libxml percent-encodes `{` and
 * `}` in href and src, which would turn `{{{RESEND_UNSUBSCRIBE_URL}}}` and a
 * merge-tag link into dead text.
 */
class EmailEditorHtml
{
    public const DOCTYPE = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';

    private const ALLOWED = [
        'html', 'head', 'meta', 'title', 'body',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'img', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 's',
        'br', 'hr', 'blockquote', 'pre', 'code', 'sup', 'sub', 'center',
    ];

    private const REMOVE = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'svg', 'math', 'link', 'base', 'noscript', 'template',
    ];

    private const ATTRIBUTES = [
        'style', 'href', 'src', 'alt', 'title', 'width', 'height', 'align', 'valign', 'border',
        'cellpadding', 'cellspacing', 'role', 'dir', 'lang', 'bgcolor', 'target', 'rel', 'colspan', 'rowspan',
    ];

    private const META_ATTRIBUTES = ['content', 'name', 'http-equiv'];

    private const VOID = ['meta', 'br', 'hr', 'img'];

    private const STYLE_FORBIDDEN = ['expression(', 'javascript:', 'url(', '@import', 'behavior:', '-moz-binding', '\\'];

    /** Is this a whole document (it has a <body>) rather than a body fragment? */
    public function isDocument(string $html): bool
    {
        return (bool) preg_match('/<body[\s>\/]/i', $html);
    }

    /** The cleaned email, always a full HTML document. */
    public function clean(string $html): string
    {
        $dom = $this->cleaned($html);
        $root = $dom->documentElement;

        return self::DOCTYPE . ($root ? $this->serialize($root) : '<html><head></head><body></body></html>');
    }

    /** The cleaned contents of the body only (for a fragment such as the starter). */
    public function cleanFragment(string $html): string
    {
        $body = $this->body($this->cleaned($html));

        return $body ? $this->children($body) : '';
    }

    /** Does the body say anything: words (a merge tag counts) or a picture? */
    public function hasContent(string $html): bool
    {
        $body = $this->body($this->cleaned($html));

        if ($body === null) {
            return false;
        }

        if ($body->getElementsByTagName('img')->length > 0) {
            return true;
        }

        return preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', (string) $body->textContent) !== '';
    }

    /** A plain-text reading of the body, for when the editor sent no text copy. */
    public function text(string $html): string
    {
        $body = $this->body($this->cleaned($html));

        if ($body === null) {
            return '';
        }

        $inner = $this->children($body);
        $inner = preg_replace('#<br\s*/?>#i', "\n", $inner);
        $inner = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote|pre|table)>#i', "\n\n", $inner);
        $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /* ---------------------------------------------------------------------- */

    private function cleaned(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Every non-ASCII character as a numeric reference, so libxml cannot
        // misread the bytes whatever the document claims its charset is.
        $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        if (trim($encoded) === '') {
            $encoded = '<html><body></body></html>';
        }

        $dom->loadHTML($encoded, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;

        if ($root === null || strtolower($root->nodeName) !== 'html') {
            $dom = new \DOMDocument();
            $dom->appendChild($dom->createElement('html'));
            $root = $dom->documentElement;
        }

        foreach (iterator_to_array($root->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), ['dir', 'lang'], true)) {
                $root->removeAttribute($attribute->nodeName);
            }
        }

        $this->walk($root, 0);
        $this->ensureStructure($dom, $root);

        return $dom;
    }

    private function walk(\DOMNode $node, int $depth): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText && ! $child instanceof \DOMCdataSection) {
                continue;
            }

            if (! $child instanceof \DOMElement) {
                // Comments, CDATA, processing instructions.
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::REMOVE, true)) {
                $node->removeChild($child);

                continue;
            }

            $this->walk($child, $depth + 1);

            // html/head/body only where a document has them.
            $structural = in_array($tag, ['head', 'body'], true) && $depth === 0;

            if (! in_array($tag, self::ALLOWED, true) || ($tag === 'html') || (in_array($tag, ['head', 'body'], true) && ! $structural)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            $this->attributes($child, $tag);
        }
    }

    private function attributes(\DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;
            $element->removeAttribute($attribute->nodeName);

            $allowed = in_array($name, self::ATTRIBUTES, true)
                || ($tag === 'meta' && in_array($name, self::META_ATTRIBUTES, true));

            if (! $allowed) {
                continue;
            }

            if ($name === 'href') {
                $value = $this->href($value);
            } elseif ($name === 'src') {
                $value = preg_match('#^https?://#i', trim($value)) ? trim($value) : null;
            } elseif ($name === 'style') {
                $value = $this->style($value);
            }

            if ($value !== null) {
                $element->setAttribute($name, $value);
            }
        }
    }

    private function href(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('#^(https?://|mailto:)#i', $value)) {
            return $value;
        }

        // Exactly a merge tag ({first_name}) or a Resend placeholder ({{{…}}}).
        if (preg_match('/^\{\{\{[^{}]+\}\}\}$/', $value) || preg_match('/^\{[^{}]+\}$/', $value)) {
            return $value;
        }

        return null;
    }

    private function style(string $value): ?string
    {
        $probe = strtolower(preg_replace('#/\*.*?\*/#s', '', $value));
        $probe = preg_replace('/\s+/', '', $probe);

        foreach (self::STYLE_FORBIDDEN as $bad) {
            if (str_contains($probe, $bad)) {
                return null;
            }
        }

        return $value;
    }

    private function ensureStructure(\DOMDocument $dom, \DOMElement $root): void
    {
        $head = $this->child($root, 'head');
        $body = $this->child($root, 'body');

        if ($body === null) {
            $body = $dom->createElement('body');

            // Anything left at the top that is not the head belongs in a body.
            foreach (iterator_to_array($root->childNodes) as $child) {
                if ($child !== $head) {
                    $body->appendChild($child);
                }
            }

            $root->appendChild($body);
        }

        if ($head === null) {
            $head = $dom->createElement('head');
            $root->insertBefore($head, $root->firstChild);
        }

        $hasCharset = false;

        foreach ($head->getElementsByTagName('meta') as $meta) {
            if ($meta->hasAttribute('charset') || stripos($meta->getAttribute('content'), 'charset') !== false) {
                $hasCharset = true;
            }
        }

        if (! $hasCharset) {
            $meta = $dom->createElement('meta');
            $meta->setAttribute('content', 'text/html; charset=UTF-8');
            $meta->setAttribute('http-equiv', 'Content-Type');
            $head->insertBefore($meta, $head->firstChild);
        }
    }

    private function child(\DOMElement $parent, string $tag): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === $tag) {
                return $child;
            }
        }

        return null;
    }

    private function body(\DOMDocument $dom): ?\DOMElement
    {
        return $dom->documentElement ? $this->child($dom->documentElement, 'body') : null;
    }

    private function serialize(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $out = '<' . $tag;

        foreach ($node->attributes as $attribute) {
            $out .= ' ' . strtolower($attribute->nodeName) . '="' . htmlspecialchars((string) $attribute->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        if (in_array($tag, self::VOID, true)) {
            return $out . '>';
        }

        return $out . '>' . $this->children($node) . '</' . $tag . '>';
    }

    private function children(\DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $this->serialize($child);
        }

        return $out;
    }
}
