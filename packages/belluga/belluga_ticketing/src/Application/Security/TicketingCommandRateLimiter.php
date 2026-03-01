<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Security;

use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Support\Facades\RateLimiter;

class TicketingCommandRateLimiter
{
    public function enforce(
        string $action,
        ?string $principalId,
        ?string $scopeKey = null,
        ?string $ip = null,
    ): void {
        $limit = $this->limitFor($action);
        $decay = 60;

        $identity = $principalId && $principalId !== '' ? $principalId : ($ip ?? 'anon');
        $scopePart = $scopeKey && $scopeKey !== '' ? ':' . $scopeKey : '';
        $key = sprintf('ticketing:%s:%s%s', $action, $identity, $scopePart);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $availableIn = RateLimiter::availableIn($key);
            throw new TicketingDomainException(
                errorCode: 'rate_limited',
                httpStatus: 429,
                message: sprintf('Too many requests. Retry in %d seconds.', max(1, $availableIn))
            );
        }

        RateLimiter::hit($key, $decay);
    }

    private function limitFor(string $action): int
    {
        $default = match ($action) {
            'admission' => 20,
            'checkout_confirm' => 12,
            'validation' => 60,
            default => 30,
        };

        $configured = config(sprintf('belluga_ticketing.rate_limits.%s_per_minute', $action));
        if (! is_numeric($configured)) {
            return $default;
        }

        return max(1, (int) $configured);
    }
}

