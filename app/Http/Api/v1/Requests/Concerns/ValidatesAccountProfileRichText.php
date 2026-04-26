<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests\Concerns;

use App\Support\Validation\InputConstraints;

trait ValidatesAccountProfileRichText
{
    /**
     * @return array<int, mixed>
     */
    private function optionalAccountProfileRichTextRule(): array
    {
        return [
            'sometimes',
            'nullable',
            'string',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                if (strlen($value) <= InputConstraints::ACCOUNT_PROFILE_RICH_TEXT_MAX_BYTES) {
                    return;
                }

                $fail(sprintf(
                    'The %s may not be greater than 100 KB before sanitization.',
                    str_replace('_', ' ', $attribute)
                ));
            },
        ];
    }
}
