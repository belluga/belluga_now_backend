<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\Tenants\AccountUser;
use Illuminate\Support\Carbon;

class TenantPasswordRegistrationResult
{
    public function __construct(
        public readonly AccountUser $user,
        public readonly string $plainTextToken,
        public readonly ?Carbon $expiresAt = null,
    ) {
    }
}

