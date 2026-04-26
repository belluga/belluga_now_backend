<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Support\RichText\SafeRichTextHtmlSanitizer;
use Belluga\Events\Contracts\EventContentSanitizerContract;

class EventContentSanitizerAdapter implements EventContentSanitizerContract
{
    public function sanitize(?string $value): string
    {
        return SafeRichTextHtmlSanitizer::sanitize($value);
    }
}
