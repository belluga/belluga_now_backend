<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Illuminate\Support\Facades\Hash;

class PhoneOtpReviewAccessCodeHasher
{
    public function make(string $code): string
    {
        return Hash::make($code);
    }

    public function check(string $code, ?string $codeHash): bool
    {
        $trimmedHash = is_string($codeHash) ? trim($codeHash) : '';

        return $trimmedHash !== '' && Hash::check($code, $trimmedHash);
    }
}
