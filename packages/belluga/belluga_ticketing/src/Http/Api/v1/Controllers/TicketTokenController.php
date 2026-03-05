<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Admission\TicketAdmissionService;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\TokenRefreshRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketTokenController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(private readonly TicketAdmissionService $admission)
    {
    }

    public function refresh(TokenRefreshRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request): array {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : null;
            if (! $principalId) {
                return ['status' => 'rejected', 'code' => 'auth_required'];
            }

            $validated = $request->validated();

            return $this->admission->refreshTokens(
                principalId: $principalId,
                queueToken: isset($validated['queue_token']) ? (string) $validated['queue_token'] : null,
                holdToken: isset($validated['hold_token']) ? (string) $validated['hold_token'] : null,
            );
        });
    }
}
