<?php

declare(strict_types=1);

namespace Belluga\Events\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

final class EventContentHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'blockquote',
        'br',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'li',
        'ol',
        'p',
        's',
        'strong',
        'ul',
    ];

    public static function sanitize(?string $value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }

        if (! self::looksLikeHtml($trimmed)) {
            return self::wrapPlainText($trimmed);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML(
            mb_convert_encoding(
                '<!DOCTYPE html><html><body><div data-belluga-sanitizer-root="1">'
                    .$trimmed
                    .'</div></body></html>',
                'HTML-ENTITIES',
                'UTF-8'
            ),
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = self::findSanitizerRoot($document);
        if ($root === null) {
            return self::wrapPlainText(self::normalizeTextContent($document->textContent ?? ''));
        }

        self::sanitizeNode($root);

        $textContent = self::normalizeTextContent($root->textContent ?? '');
        if ($textContent === '') {
            return '';
        }

        $sanitized = self::innerHtml($root, $document);
        if ($sanitized === null) {
            return self::wrapPlainText($textContent);
        }

        $sanitized = self::normalizeBreakTags(self::decodeNumericEntities(trim($sanitized)));
        if ($sanitized === '') {
            return '';
        }

        if (! self::containsBlockTag($sanitized)) {
            return self::wrapInlineFragment($sanitized);
        }

        return $sanitized;
    }

    private static function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<[^>]+>/', $value);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);

                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tagName = strtolower($child->nodeName);
            if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
                if (in_array($tagName, ['script', 'style'], true)) {
                    $node->removeChild($child);

                    continue;
                }

                self::sanitizeNode($child);
                self::unwrapNode($child);

                continue;
            }

            self::sanitizeElement($child);
            self::sanitizeNode($child);
        }
    }

    private static function sanitizeElement(DOMElement $element): void
    {
        self::stripAttributes($element, []);
    }

    private static function findSanitizerRoot(DOMDocument $document): ?DOMElement
    {
        foreach ($document->getElementsByTagName('div') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if ($element->getAttribute('data-belluga-sanitizer-root') === '1') {
                return $element;
            }
        }

        return null;
    }

    private static function innerHtml(DOMElement $element, DOMDocument $document): ?string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $fragment = $document->saveHTML($child);
            if ($fragment === false) {
                return null;
            }

            $html .= $fragment;
        }

        return $html;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private static function stripAttributes(DOMElement $element, array $allowed): void
    {
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[] = $attribute->name;
        }

        foreach ($attributes as $attribute) {
            if (in_array($attribute, $allowed, true)) {
                continue;
            }

            $element->removeAttribute($attribute);
        }
    }

    private static function unwrapNode(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    private static function normalizeTextContent(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $value);
        if (! is_string($normalized)) {
            $normalized = $value;
        }

        return trim($normalized);
    }

    private static function wrapPlainText(string $value): string
    {
        $normalized = self::normalizePlainText($value);
        if ($normalized === '') {
            return '';
        }

        $escapedLines = array_map(
            static fn (string $line): string => htmlspecialchars(
                $line,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            explode("\n", $normalized)
        );

        return '<p>'.implode('<br />', $escapedLines).'</p>';
    }

    private static function normalizePlainText(string $value): string
    {
        $normalized = preg_replace('/\r\n|\r/u', "\n", $value);
        if (! is_string($normalized)) {
            $normalized = $value;
        }

        $normalized = preg_replace('/[ \t\f]+/u', ' ', $normalized);
        if (! is_string($normalized)) {
            $normalized = $value;
        }

        return trim($normalized);
    }

    private static function wrapInlineFragment(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        return '<p>'.$normalized.'</p>';
    }

    private static function decodeNumericEntities(string $value): string
    {
        return preg_replace_callback(
            '/&#(x?[0-9A-Fa-f]+);/',
            static function (array $matches): string {
                $entity = $matches[1];
                $codePoint = str_starts_with(strtolower($entity), 'x')
                    ? hexdec(substr($entity, 1))
                    : (int) $entity;

                if ($codePoint <= 0) {
                    return $matches[0];
                }

                return mb_convert_encoding('&#'.$codePoint.';', 'UTF-8', 'HTML-ENTITIES');
            },
            $value
        ) ?? $value;
    }

    private static function normalizeBreakTags(string $value): string
    {
        return preg_replace('/<br\s*\/?>/i', '<br />', $value) ?? $value;
    }

    private static function containsBlockTag(string $value): bool
    {
        return (bool) preg_match('/<(blockquote|h[1-6]|li|ol|p|ul)\b/i', $value);
    }
}
