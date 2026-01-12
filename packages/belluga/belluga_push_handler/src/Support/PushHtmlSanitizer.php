<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

final class PushHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p',
        'br',
        'strong',
        'em',
        'u',
        'span',
        'ul',
        'ol',
        'li',
        'img',
    ];

    private const ALLOWED_SPAN_STYLES = [
        'color',
        'font-size',
        'font-weight',
    ];

    private const ALLOWED_IMG_ATTRS = [
        'src',
        'width',
        'height',
        'alt',
    ];

    public static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! self::looksLikeHtml($value)) {
            return $value;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML(
            mb_convert_encoding($value, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        self::sanitizeNode($document, $document);

        $sanitized = $document->saveHTML();
        if ($sanitized === false) {
            return $value;
        }

        return trim($sanitized);
    }

    private static function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<[^>]+>/', $value);
    }

    private static function sanitizeNode(DOMNode $node, DOMDocument $document): void
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
                self::unwrapNode($child);
                continue;
            }

            if (! self::sanitizeElement($child)) {
                $node->removeChild($child);
                continue;
            }

            self::sanitizeNode($child, $document);
        }
    }

    private static function sanitizeElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);

        if ($tagName === 'img') {
            return self::sanitizeImage($element);
        }

        if ($tagName === 'span') {
            self::sanitizeSpanStyle($element);
            self::stripAttributes($element, ['style']);
            return true;
        }

        self::stripAttributes($element, []);
        return true;
    }

    private static function sanitizeImage(DOMElement $element): bool
    {
        self::stripAttributes($element, self::ALLOWED_IMG_ATTRS);

        $src = trim($element->getAttribute('src'));
        if ($src === '' || ! self::isAllowedUrl($src)) {
            return false;
        }

        foreach (['width', 'height'] as $dimension) {
            $value = trim($element->getAttribute($dimension));
            if ($value === '') {
                continue;
            }
            if (! preg_match('/^\d+(\.\d+)?(px|%)?$/', $value)) {
                $element->removeAttribute($dimension);
            }
        }

        return true;
    }

    private static function sanitizeSpanStyle(DOMElement $element): void
    {
        $style = $element->getAttribute('style');
        if ($style === '') {
            return;
        }

        $sanitized = [];
        $segments = array_filter(array_map('trim', explode(';', $style)));
        foreach ($segments as $segment) {
            $parts = array_map('trim', explode(':', $segment, 2));
            if (count($parts) !== 2) {
                continue;
            }
            [$property, $value] = $parts;
            $property = strtolower($property);
            if (! in_array($property, self::ALLOWED_SPAN_STYLES, true)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || ! self::isAllowedStyleValue($property, $value)) {
                continue;
            }
            $sanitized[] = $property . ': ' . $value;
        }

        if ($sanitized === []) {
            $element->removeAttribute('style');
            return;
        }

        $element->setAttribute('style', implode('; ', $sanitized));
    }

    private static function isAllowedStyleValue(string $property, string $value): bool
    {
        if ($property === 'color') {
            return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)
                || (bool) preg_match('/^rgb\\(\\s*\\d{1,3}\\s*,\\s*\\d{1,3}\\s*,\\s*\\d{1,3}\\s*\\)$/', $value)
                || (bool) preg_match('/^rgba\\(\\s*\\d{1,3}\\s*,\\s*\\d{1,3}\\s*,\\s*\\d{1,3}\\s*,\\s*(0|1|0?\\.\\d+)\\s*\\)$/', $value);
        }

        if ($property === 'font-size') {
            return (bool) preg_match('/^\\d+(\\.\\d+)?(px|em|rem|%)$/', $value);
        }

        if ($property === 'font-weight') {
            return $value === 'normal' || $value === 'bold' || (bool) preg_match('/^[1-9]00$/', $value);
        }

        return false;
    }

    private static function isAllowedUrl(string $value): bool
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        return $scheme === 'http' || $scheme === 'https';
    }

    private static function stripAttributes(DOMElement $element, array $allowed): void
    {
        $allowedMap = array_fill_keys($allowed, true);
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }
        foreach ($attributes as $name) {
            if (! isset($allowedMap[$name])) {
                $element->removeAttribute($name);
            }
        }
    }

    private static function unwrapNode(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}
