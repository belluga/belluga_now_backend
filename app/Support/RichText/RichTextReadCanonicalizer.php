<?php

declare(strict_types=1);

namespace App\Support\RichText;

use Closure;

final class RichTextReadCanonicalizer
{
    public const RICH_TEXT_READ_MAX_BYTES = 102400;

    /** @var array<string, string> */
    private array $memoized = [];

    /** @var Closure(string, bool): string */
    private readonly Closure $sanitize;

    /** @param null|Closure(string, bool): string $sanitize */
    public function __construct(?Closure $sanitize = null)
    {
        $this->sanitize = $sanitize ?? static fn (string $value, bool $allowExplicitHttpsLinks): string => SafeRichTextHtmlSanitizer::sanitize(
            $value,
            $allowExplicitHttpsLinks
        );
    }

    public function canonicalize(
        mixed $value,
        bool $allowExplicitHttpsLinks,
        string $resource,
        string $resourceId,
        string $field,
    ): string {
        if (! is_string($value) || strlen($value) > self::RICH_TEXT_READ_MAX_BYTES) {
            return '';
        }

        $key = hash('sha256', implode("\0", [
            $resource,
            $resourceId,
            $field,
            $allowExplicitHttpsLinks ? '1' : '0',
            $value,
        ]));

        return $this->memoized[$key] ??= ($this->sanitize)($value, $allowExplicitHttpsLinks);
    }
}
