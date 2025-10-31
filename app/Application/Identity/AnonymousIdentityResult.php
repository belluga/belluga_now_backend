<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\Tenants\AccountUser;
use Illuminate\Support\Carbon;

class AnonymousIdentityResult
{
    /**
     * @param array<int, string> $abilities
     */
    public function __construct(
        public readonly AccountUser $user,
        public readonly string $plainTextToken,
        public readonly array $abilities,
        public readonly ?Carbon $expiresAt
    ) {
    }
}
