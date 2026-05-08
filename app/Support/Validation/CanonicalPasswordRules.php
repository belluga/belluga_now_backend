<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Rules\CommonBreachedPasswordRule;

final class CanonicalPasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function required(bool $confirmed = false): array
    {
        return self::build('required', $confirmed);
    }

    /**
     * @return array<int, mixed>
     */
    public static function nullable(bool $confirmed = false): array
    {
        return self::build('nullable', $confirmed);
    }

    /**
     * @return array<int, mixed>
     */
    private static function build(string $presenceRule, bool $confirmed): array
    {
        $rules = [
            $presenceRule,
            'string',
            'min:'.InputConstraints::PASSWORD_MIN,
            'max:'.InputConstraints::PASSWORD_MAX,
            new CommonBreachedPasswordRule,
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}
