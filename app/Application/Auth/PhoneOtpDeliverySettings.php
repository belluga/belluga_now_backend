<?php

declare(strict_types=1);

namespace App\Application\Auth;

final class PhoneOtpDeliverySettings
{
    public function __construct(
        public readonly string $webhookUrl,
        public readonly string $channel,
        public readonly int $ttlMinutes,
        public readonly int $resendCooldownSeconds,
        public readonly int $maxAttempts,
    ) {}
}
