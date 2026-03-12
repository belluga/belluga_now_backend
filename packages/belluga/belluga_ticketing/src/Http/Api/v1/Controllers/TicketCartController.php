<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Checkout\TicketCheckoutService;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\CartShowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketCartController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(private readonly TicketCheckoutService $checkout) {}

    public function show(CartShowRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request): array {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : null;
            if (! $principalId) {
                return ['status' => 'rejected', 'code' => 'auth_required'];
            }

            return [
                'status' => 'ok',
                'data' => $this->checkout->showCart(
                    holdToken: (string) $request->validated('hold_token'),
                    principalId: $principalId,
                ),
            ];
        });
    }
}
