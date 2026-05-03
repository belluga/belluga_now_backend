<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Illuminate\Validation\ValidationException;

class PhoneNumberNormalizer
{
    public function normalize(string $phone): string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            throw ValidationException::withMessages([
                'phone' => ['Enter a valid phone number.'],
            ]);
        }

        if (str_starts_with($trimmed, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '+55'.$digits;
        }

        return '+'.$digits;
    }

    public function hash(string $normalizedPhone): string
    {
        $digits = preg_replace('/\D+/', '', $normalizedPhone) ?? '';

        return hash('sha256', $digits);
    }
}
