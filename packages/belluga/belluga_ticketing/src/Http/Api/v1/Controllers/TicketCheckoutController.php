<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Checkout\TicketCheckoutService;
use Belluga\Ticketing\Application\Security\TicketingCommandRateLimiter;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\CheckoutConfirmRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketCheckoutController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(
        private readonly TicketCheckoutService $checkout,
        private readonly TicketingCommandRateLimiter $rateLimiter,
    ) {}

    public function confirm(CheckoutConfirmRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request): array {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : null;
            if (! $principalId) {
                return ['status' => 'rejected', 'code' => 'auth_required'];
            }

            $validated = $request->validated();
            $this->rateLimiter->enforce(
                action: 'checkout_confirm',
                principalId: $principalId,
                scopeKey: (string) ($validated['hold_token'] ?? ''),
                ip: (string) $request->ip(),
            );

            return $this->checkout->confirm(
                holdToken: (string) $validated['hold_token'],
                principalId: $principalId,
                idempotencyKey: (string) $validated['idempotency_key'],
                checkoutMode: (string) ($validated['checkout_mode'] ?? 'free'),
                accountId: isset($validated['account_id']) ? (string) $validated['account_id'] : null,
            );
        });
    }
}
