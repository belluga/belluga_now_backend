<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CommonBreachedPasswordRule implements ValidationRule
{
    /**
     * Curated local common/breached-password floor for launch hardening.
     * Expanded manually as new blocked samples are approved; intentionally
     * avoids runtime network dependencies such as breach-API lookups.
     *
     * @var array<int, string>
     */
    private const DENYLIST = [
        '12345678',
        '123456789',
        '1234567890',
        '11111111',
        'abc12345',
        'admin123',
        'admin123!',
        'letmein123',
        'letmein123!',
        'passw0rd',
        'password',
        'password1',
        'password123',
        'password123!',
        'qwerty123',
        'qwerty123!',
        'secret123',
        'secret123!',
        'welcome123',
        'welcome123!',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return;
        }

        if (in_array($normalized, self::DENYLIST, true)) {
            $fail('The selected password is too common or is disallowed by the password security policy. Choose a different password.');
        }
    }

    private function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
