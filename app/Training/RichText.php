<?php

namespace App\Training;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans the HTML the course designer saves, keeping only formatting the
 * editor makes: headings, paragraphs, bold, lists, links, colors, alignment,
 * images and YouTube/Vimeo/Loom players. Anything else (scripts, event
 * handlers, styles beyond color and alignment) is removed, so a page can
 * never run code in someone else's browser.
 */
class RichText
{
    private const TAGS = [
        'p' => [], 'br' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'blockquote' => [], 'pre' => [], 'code' => [], 'span' => [], 'hr' => [],
        'ol' => [], 'ul' => [], 'li' => ['data-list'], 'a' => ['href'], 'img' => ['src', 'alt', 'width', 'height'],
        'iframe' => ['src'], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    ];

    /** Tags removed with everything inside them. */
    private const DROP = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'link', 'meta', 'base', 'template', 'noscript'];

    /** Quill's own classes for alignment, indents and sizes. */
    private const CLASS_PATTERN = '/^ql-(align-(center|right|justify)|indent-[1-8]|size-(small|large|huge)|video)$/';

    private const EMBED_HOSTS = ['www.youtube-nocookie.com', 'www.youtube.com', 'player.vimeo.com', 'www.loom.com'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '' || $html === '<p><br></p>') {
            return '';
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="rich-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('rich-root') ?? $doc->documentElement;
        if (! $root) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }
            self::walk($child);
            if (! array_key_exists($tag, self::TAGS)) {
                // Keep the text, lose the tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }
            self::cleanAttributes($child, $tag);
            if (($tag === 'img' || $tag === 'iframe') && ! $child->hasAttribute('src')) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $keep = self::TAGS[$tag];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            $ok = match (true) {
                $name === 'class' => (bool) ($value = implode(' ', array_filter(preg_split('/\s+/', $value), fn ($c) => preg_match(self::CLASS_PATTERN, $c)))),
                $name === 'style' => (bool) ($value = self::style($value)),
                $name === 'href' => self::safeUrl($value, false),
                $name === 'src' && $tag === 'img' => self::safeUrl($value, true),
                $name === 'src' && $tag === 'iframe' => in_array(parse_url($value, PHP_URL_HOST), self::EMBED_HOSTS, true) && str_starts_with($value, 'https://'),
                in_array($name, ['width', 'height', 'colspan', 'rowspan'], true) => ctype_digit($value),
                $name === 'data-list' => in_array($value, ['bullet', 'ordered', 'checked', 'unchecked'], true),
                default => in_array($name, $keep, true),
            };
            $el->removeAttribute($attr->name);
            if ($ok) {
                $el->setAttribute($name, $value);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'iframe') {
            $el->setAttribute('allowfullscreen', 'allowfullscreen');
            $el->setAttribute('frameborder', '0');
        }
    }

    /** Only text and background color (as #hex or rgb()) and text alignment survive. */
    private static function style(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $rule) {
            [$prop, $val] = array_map('trim', explode(':', $rule, 2) + [1 => '']);
            $prop = strtolower($prop);
            if (in_array($prop, ['color', 'background-color'], true) && preg_match('/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i', $val)) {
                $kept[] = "{$prop}: {$val}";
            } elseif ($prop === 'text-align' && in_array(strtolower($val), ['left', 'center', 'right', 'justify'], true)) {
                $kept[] = "{$prop}: {$val}";
            }
        }

        return implode('; ', $kept);
    }

    private static function safeUrl(string $url, bool $image): bool
    {
        if ($image) {
            // Pictures come from the designer's uploads (or another https site), never data: or javascript:.
            return str_starts_with($url, '/training/images/') || (bool) preg_match('~^https://~i', $url);
        }

        return (bool) preg_match('~^(https?://|mailto:|tel:|/|#)~i', $url);
    }

    /** Plain text, for search and the API's summary. */
    public static function text(?string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h1>', '</h2>', '</h3>'], ' ', (string) $html)))));
    }
}
