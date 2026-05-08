<?php

declare(strict_types=1);

namespace App\Events\Auth;

class PasswordResetTokenIssued
{
    public function __construct(
        public readonly string $broker,
        public readonly string $email,
        public readonly string $userId,
        public readonly string $token,
    ) {}
}
