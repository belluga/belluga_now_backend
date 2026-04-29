<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Tenants\AccountUser;

final class PhoneOtpVerificationResult
{
    /**
     * @param  array<int, string>  $mergedAnonymousUserIds
     */
    public function __construct(
        public readonly AccountUser $user,
        public readonly string $plainTextToken,
        public readonly array $mergedAnonymousUserIds,
    ) {}
}
