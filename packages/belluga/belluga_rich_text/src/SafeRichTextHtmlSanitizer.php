<?php

declare(strict_types=1);

namespace Belluga\RichText;

use DOMDocument;
use DOMElement;
use DOMNode;

final class SafeRichTextHtmlSanitizer
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

    public static function sanitize(?string $value, bool $allowExplicitHttpsLinks = false): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }

        if (! self::looksLikeHtml($trimmed)) {
            return self::wrapPlainText($trimmed);
        }

        [$trimmed, $explicitHttpsHrefs] = self::preflightAnchors($trimmed, $allowExplicitHttpsLinks);

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

        self::sanitizeNode($root, $allowExplicitHttpsLinks);

        $textContent = self::normalizeTextContent($root->textContent ?? '');
        if ($textContent === '') {
            return '';
        }

        $sanitized = self::innerHtml($root, $document);
        if ($sanitized === null) {
            return self::wrapPlainText($textContent);
        }

        $sanitized = self::normalizeBreakTags(self::decodeNumericEntities(trim($sanitized)));
        $sanitized = self::restoreExplicitHttpsHrefs($sanitized, $explicitHttpsHrefs);
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

    private static function sanitizeNode(DOMNode $node, bool $allowExplicitHttpsLinks): void
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
            if (! in_array($tagName, self::ALLOWED_TAGS, true) && ! ($allowExplicitHttpsLinks && $tagName === 'a')) {
                if (in_array($tagName, ['script', 'style'], true)) {
                    $node->removeChild($child);

                    continue;
                }

                self::sanitizeNode($child, $allowExplicitHttpsLinks);
                self::unwrapNode($child);

                continue;
            }

            if ($tagName === 'a' && $allowExplicitHttpsLinks && (self::canonicalHttpsHref($child->getAttribute('href')) === null || $child->attributes->length !== 1)) {
                self::sanitizeNode($child, $allowExplicitHttpsLinks);
                self::unwrapNode($child);

                continue;
            }

            self::sanitizeElement($child, $allowExplicitHttpsLinks);
            self::sanitizeNode($child, $allowExplicitHttpsLinks);
        }
    }

    private static function sanitizeElement(DOMElement $element, bool $allowExplicitHttpsLinks): void
    {
        if ($allowExplicitHttpsLinks && strtolower($element->tagName) === 'a') {
            $href = self::canonicalHttpsHref($element->getAttribute('href'));
            if ($href !== null && $element->attributes->length === 1) {
                $element->setAttribute('href', $href);

                return;
            }
        }
        self::stripAttributes($element, []);
    }

    private static function canonicalHttpsHref(string $href): ?string
    {
        if ($href === '' || $href !== trim($href)) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\"<>{}\']/', $href)
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $href)
            || preg_match('/%(?:0[0-9A-Fa-f]|1[0-9A-Fa-f]|20|7[fF])/', $href)) {
            return null;
        }
        if (! preg_match('/^https:\/\/([^\/?#]+)(?:[\/?#].*)?$/i', $href, $absolute)) {
            return null;
        }

        $authority = $absolute[1];
        if (str_contains($authority, '@') || str_contains($authority, '%')) {
            return null;
        }
        $bracketedIpv6 = str_starts_with($authority, '[');
        $authorityPattern = $bracketedIpv6
            ? '/^\[([0-9A-Fa-f:.]+)\](?::([0-9]+))?$/'
            : '/^[^:]+(?::([0-9]+))?$/';
        if (! preg_match($authorityPattern, $authority, $authorityMatch)) {
            return null;
        }
        if ($bracketedIpv6 && filter_var($authorityMatch[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $portValue = $bracketedIpv6 ? ($authorityMatch[2] ?? null) : ($authorityMatch[1] ?? null);
        if ($portValue !== null && $portValue !== '') {
            if (strlen($portValue) > 1 && str_starts_with($portValue, '0')) {
                return null;
            }
            $port = filter_var($portValue, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                return null;
            }
        }

        $parts = parse_url($href);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $parsedAuthority = (string) $parts['host'];
        if (isset($parts['port'])) {
            $parsedAuthority .= ':'.(string) $parts['port'];
        }
        if ($parsedAuthority !== $authority) {
            return null;
        }

        return 'https'.substr($href, strpos($href, ':'));
    }

    /** @return array{string, list<string>} */
    private static function preflightAnchors(string $html, bool $allowExplicitHttpsLinks): array
    {
        $tags = self::scanMarkupTags($html);
        [$closingByOpening, $anchorOpeningPrefix] = self::pairAnchorTags($tags);
        $output = '';
        $cursor = 0;
        $index = 0;
        $tagCount = count($tags);
        $explicitHttpsHrefs = [];
        while ($index < $tagCount) {
            $tag = $tags[$index];
            if ($tag['name'] !== 'a') {
                $index++;

                continue;
            }
            $output .= substr($html, $cursor, $tag['start'] - $cursor);
            if ($tag['closing']) {
                $cursor = $tag['end'];
                $index++;

                continue;
            }

            $closingIndex = $closingByOpening[$index] ?? null;
            if ($closingIndex === null) {
                $cursor = $tag['end'];
                $index++;

                continue;
            }

            $closing = $tags[$closingIndex];
            $ambiguous = $anchorOpeningPrefix[$closingIndex] > $anchorOpeningPrefix[$index];
            $contents = substr($html, $tag['end'], $closing['start'] - $tag['end']);
            if ($ambiguous) {
                $contents = self::removeScannedAnchorTags($contents);
            }
            $grammarMatched = preg_match('/^<a[ \t]+href[ \t]*=[ \t]*(?:"([^"]*)"|\'([^\']*)\')[ \t]*>$/i', $tag['raw'], $grammar) === 1;
            $encodedHref = $grammarMatched ? (($grammar[1] ?? '') !== '' ? $grammar[1] : ($grammar[2] ?? '')) : null;
            $href = $encodedHref === null ? null : self::decodeCanonicalAmpEntities($encodedHref);
            $canonical = $href === null ? null : self::canonicalHttpsHref($href);
            $accepted = $allowExplicitHttpsLinks
                && ! $ambiguous
                && ! self::hasRejectedNestedAnchorMarkup($contents)
                && $canonical !== null;
            if ($accepted) {
                $anchorIndex = count($explicitHttpsHrefs);
                $explicitHttpsHrefs[] = $canonical;
                $output .= '<a href="'.self::explicitHttpsPlaceholder($anchorIndex).'">'.$contents.'</a>';
            } else {
                $output .= $contents;
            }
            $cursor = $closing['end'];
            $index = $closingIndex + 1;
        }
        $output .= substr($html, $cursor);

        return [$output, $explicitHttpsHrefs];
    }

    private static function explicitHttpsPlaceholder(int $index): string
    {
        return 'https://belluga-rich-text.invalid/__explicit_href_'.$index.'__';
    }

    /** @param list<string> $explicitHttpsHrefs */
    private static function restoreExplicitHttpsHrefs(string $html, array $explicitHttpsHrefs): string
    {
        if ($explicitHttpsHrefs === []) {
            return $html;
        }

        $openingCount = 0;
        $closingCount = 0;
        $valid = true;
        $restored = preg_replace_callback(
            '/<a\b[^>]*>|<\/a>/',
            static function (array $match) use ($explicitHttpsHrefs, &$openingCount, &$closingCount, &$valid): string {
                if ($match[0] === '</a>') {
                    if ($closingCount >= $openingCount) {
                        $valid = false;
                    }
                    $closingCount++;

                    return $match[0];
                }

                if (preg_match(
                    '/^<a href="https:\/\/belluga-rich-text\.invalid\/__explicit_href_([0-9]+)__">$/',
                    $match[0],
                    $placeholder
                ) !== 1) {
                    $valid = false;

                    return $match[0];
                }

                $index = filter_var($placeholder[1], FILTER_VALIDATE_INT);
                if ($index === false
                    || $index !== $openingCount
                    || ! array_key_exists($index, $explicitHttpsHrefs)) {
                    $valid = false;

                    return $match[0];
                }

                $openingCount++;

                return '<a href="'.self::encodeHrefAttribute($explicitHttpsHrefs[$index]).'">';
            },
            $html
        );

        if ($restored === null
            || ! $valid
            || $openingCount !== count($explicitHttpsHrefs)
            || $closingCount !== $openingCount) {
            return self::unwrapSerializedAnchors($html);
        }

        return $restored;
    }

    private static function unwrapSerializedAnchors(string $html): string
    {
        return (string) preg_replace('/<\/?a\b[^>]*>/', '', $html);
    }

    /**
     * @param  list<array{start:int,end:int,raw:string,name:string,closing:bool}>  $tags
     * @return array{array<int, int>, array<int, int>}
     */
    private static function pairAnchorTags(array $tags): array
    {
        $openingStack = [];
        $closingByOpening = [];
        $anchorOpeningPrefix = [];
        $openingCount = 0;

        foreach ($tags as $index => $tag) {
            if ($tag['name'] === 'a' && ! $tag['closing']) {
                $openingStack[] = $index;
                $openingCount++;
            } elseif ($tag['name'] === 'a' && strtolower($tag['raw']) === '</a>' && $openingStack !== []) {
                $openingIndex = array_pop($openingStack);
                $closingByOpening[$openingIndex] = $index;
            }
            $anchorOpeningPrefix[$index] = $openingCount;
        }

        return [$closingByOpening, $anchorOpeningPrefix];
    }

    /** @return list<array{start:int,end:int,raw:string,name:string,closing:bool}> */
    private static function scanMarkupTags(string $html): array
    {
        $tags = [];
        $length = strlen($html);
        $cursor = 0;
        while ($cursor < $length) {
            $start = strpos($html, '<', $cursor);
            if ($start === false) {
                break;
            }
            $position = $start + 1;
            while ($position < $length && preg_match('/\s/', $html[$position])) {
                $position++;
            }
            $closing = false;
            if ($position < $length && $html[$position] === '/') {
                $closing = true;
                $position++;
                while ($position < $length && preg_match('/\s/', $html[$position])) {
                    $position++;
                }
            }
            $nameStart = $position;
            while ($position < $length && preg_match('/[A-Za-z0-9:-]/', $html[$position])) {
                $position++;
            }
            if ($position === $nameStart) {
                $cursor = $start + 1;

                continue;
            }
            $name = strtolower(substr($html, $nameStart, $position - $nameStart));
            $quote = null;
            $end = -1;
            $malformedAnchorEnd = -1;
            for ($probe = $position; $probe < $length; $probe++) {
                $character = $html[$probe];
                if ($quote === null && ($character === '"' || $character === "'")) {
                    $quote = $character;
                } elseif ($quote === $character) {
                    $quote = null;
                } elseif ($quote === null && $character === '>') {
                    $end = $probe + 1;
                    break;
                } elseif ($character === '>' && $malformedAnchorEnd < 0) {
                    $malformedAnchorEnd = $probe + 1;
                }
            }
            if ($end < 0 && $name === 'a') {
                $end = $malformedAnchorEnd;
            }
            if ($end < 0) {
                // An unterminated quoted tag owns the remaining source under
                // the quote-aware grammar. Stop instead of rescanning that
                // same suffix for every nested `<a` candidate.
                if ($name === 'a') {
                    $tags[] = [
                        'start' => $start,
                        'end' => $length,
                        'raw' => substr($html, $start),
                        'name' => $name,
                        'closing' => $closing,
                    ];
                }
                break;
            }
            $tags[] = ['start' => $start, 'end' => $end, 'raw' => substr($html, $start, $end - $start), 'name' => $name, 'closing' => $closing];
            $cursor = $end;
        }

        return $tags;
    }

    private static function removeScannedAnchorTags(string $value): string
    {
        $output = '';
        $cursor = 0;
        foreach (self::scanMarkupTags($value) as $tag) {
            if ($tag['name'] !== 'a') {
                continue;
            }
            $output .= substr($value, $cursor, $tag['start'] - $cursor);
            $cursor = $tag['end'];
        }

        return $output.substr($value, $cursor);
    }

    private static function hasRejectedNestedAnchorMarkup(string $contents): bool
    {
        if (! preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9:-]*)\b[^>]*>/', $contents, $matches)) {
            return false;
        }

        foreach ($matches[1] as $name) {
            $tagName = strtolower($name);
            if ($tagName === 'a' || ($tagName !== 'br' && ! in_array($tagName, self::ALLOWED_TAGS, true))) {
                return true;
            }
        }

        return false;
    }

    private static function decodeCanonicalAmpEntities(string $value): ?string
    {
        if (preg_match_all('/&(?:#[^;\s<>&]*|[A-Za-z][A-Za-z0-9]*);/', $value, $matches)) {
            foreach ($matches[0] as $entity) {
                if ($entity !== '&amp;') {
                    return null;
                }
            }
        }
        $decoded = str_replace('&amp;', '&', $value);
        if (preg_match('/&(?:#[^;\s<>&]*|[A-Za-z][A-Za-z0-9]*);/', $decoded)) {
            return null;
        }

        return $decoded;
    }

    private static function encodeHrefAttribute(string $href): string
    {
        return str_replace(
            ['&', '"', "'", '<', '>'],
            ['&amp;', '&quot;', '&#39;', '&lt;', '&gt;'],
            $href
        );
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

        $paragraphs = preg_split('/\n\s*\n+/u', $normalized) ?: [$normalized];
        $blocks = [];
        foreach ($paragraphs as $paragraph) {
            $escapedLines = array_map(
                static fn (string $line): string => htmlspecialchars(
                    rtrim($line),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ),
                explode("\n", $paragraph)
            );
            $block = '<p>'.implode('<br />', $escapedLines).'</p>';
            if (self::normalizeTextContent($block) !== '') {
                $blocks[] = $block;
            }
        }

        return implode('', $blocks);
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
