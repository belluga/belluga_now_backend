<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Support\Validation\InputConstraints;
use Belluga\Events\Support\EventContentHtmlSanitizer;
use Illuminate\Validation\ValidationException;

final class AccountProfileRichTextSanitizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizePayload(array $payload): array
    {
        $errors = [];

        foreach (['bio', 'content'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $payload[$field] = self::sanitize($payload[$field]);

            if (strlen($payload[$field]) <= InputConstraints::ACCOUNT_PROFILE_RICH_TEXT_MAX_BYTES) {
                continue;
            }

            $errors[$field] = [sprintf(
                'The %s may not be greater than 100 KB after sanitization.',
                str_replace('_', ' ', $field)
            )];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $payload;
    }

    public static function sanitize(mixed $value): string
    {
        return EventContentHtmlSanitizer::sanitize(is_string($value) ? $value : null);
    }
}
