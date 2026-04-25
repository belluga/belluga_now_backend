<?php

declare(strict_types=1);

namespace Belluga\Events\Support;

use Belluga\RichText\SafeRichTextHtmlSanitizer;

final class EventContentHtmlSanitizer
{
    public static function sanitize(?string $value): string
    {
        return SafeRichTextHtmlSanitizer::sanitize($value);
    }
}
