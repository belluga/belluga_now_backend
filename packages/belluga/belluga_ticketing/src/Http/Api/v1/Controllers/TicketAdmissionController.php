<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Admission\TicketAdmissionService;
use Belluga\Ticketing\Application\Security\TicketingCommandRateLimiter;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\AdmissionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketAdmissionController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(
        private readonly TicketAdmissionService $admission,
        private readonly TicketingCommandRateLimiter $rateLimiter,
    ) {}

    public function occurrence(AdmissionRequest $request, string $event_ref, string $occurrence_ref): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request, $event_ref, $occurrence_ref): array {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : null;
            $this->rateLimiter->enforce(
                action: 'admission',
                principalId: $principalId,
                scopeKey: sprintf('%s:%s', (string) $event_ref, (string) $occurrence_ref),
                ip: (string) $request->ip(),
            );

            return $this->admission->requestAdmissionForRefs(
                eventRef: (string) $event_ref,
                occurrenceRef: (string) $occurrence_ref,
                principalId: $principalId,
                isAuthenticated: $user !== null,
                payload: $request->validated(),
            );
        });
    }

    public function occurrenceOnly(AdmissionRequest $request, string $occurrence_ref): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request, $occurrence_ref): array {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : null;
            $this->rateLimiter->enforce(
                action: 'admission',
                principalId: $principalId,
                scopeKey: sprintf('occurrence:%s', (string) $occurrence_ref),
                ip: (string) $request->ip(),
            );

            return $this->admission->requestAdmissionForRefs(
                eventRef: null,
                occurrenceRef: (string) $occurrence_ref,
                principalId: $principalId,
                isAuthenticated: $user !== null,
                payload: $request->validated(),
            );
        });
    }
}
