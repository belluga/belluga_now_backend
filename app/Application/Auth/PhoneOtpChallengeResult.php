<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Illuminate\Support\Carbon;

final class PhoneOtpChallengeResult
{
    public function __construct(
        public readonly string $challengeId,
        public readonly string $phone,
        public readonly string $channel,
        public readonly Carbon $expiresAt,
        public readonly Carbon $resendAvailableAt,
    ) {}
}
