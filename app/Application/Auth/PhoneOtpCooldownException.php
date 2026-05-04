<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

class PhoneOtpCooldownException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('OTP resend cooldown active.');
    }
}
