<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class AuthenticationResult
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $plainTextToken
    ) {
    }
}

