<?php

declare(strict_types=1);

namespace App\Support\RichText;

use Belluga\RichText\SafeRichTextHtmlSanitizer as SharedSafeRichTextHtmlSanitizer;

final class SafeRichTextHtmlSanitizer
{
    public static function sanitize(?string $value): string
    {
        return SharedSafeRichTextHtmlSanitizer::sanitize($value);
    }
}
