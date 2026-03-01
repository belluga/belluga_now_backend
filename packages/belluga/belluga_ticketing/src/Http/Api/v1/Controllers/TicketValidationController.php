<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Lifecycle\TicketUnitLifecycleService;
use Belluga\Ticketing\Application\Security\TicketingCommandRateLimiter;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\UnitValidateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketValidationController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(
        private readonly TicketUnitLifecycleService $lifecycle,
        private readonly TicketingCommandRateLimiter $rateLimiter,
    ) {}

    public function validateOccurrence(UnitValidateRequest $request, string $event_id, string $occurrence_id): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request, $event_id, $occurrence_id): array {
            $user = $request->user();
            $actorId = $user ? (string) $user->getAuthIdentifier() : 'system';

            $validated = $request->validated();
            $this->rateLimiter->enforce(
                action: 'validation',
                principalId: $user ? $actorId : null,
                scopeKey: sprintf('%s:%s', (string) $event_id, (string) $occurrence_id),
                ip: (string) $request->ip(),
            );

            return $this->lifecycle->validateAndConsume(
                eventId: (string) $event_id,
                occurrenceId: (string) $occurrence_id,
                ticketUnitId: isset($validated['ticket_unit_id']) ? (string) $validated['ticket_unit_id'] : null,
                admissionCode: isset($validated['admission_code']) ? (string) $validated['admission_code'] : null,
                checkpointRef: (string) $validated['checkpoint_ref'],
                idempotencyKey: (string) $validated['idempotency_key'],
                actorRef: [
                    'type' => $user ? 'user' : 'system',
                    'id' => $actorId,
                ],
            );
        });
    }
}
