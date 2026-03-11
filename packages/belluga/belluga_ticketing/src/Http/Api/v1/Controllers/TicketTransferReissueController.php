<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\TransferReissue\TicketTransferReissueService;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\TicketReissueRequest;
use Belluga\Ticketing\Http\Api\v1\Requests\TicketTransferRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketTransferReissueController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(
        private readonly TicketTransferReissueService $transferReissue,
    ) {}

    public function transfer(
        TicketTransferRequest $request,
        string $event_id,
        string $occurrence_id,
        string $ticket_unit_id,
    ): JsonResponse {
        return $this->runWithDomainGuard(function () use ($request, $event_id, $occurrence_id, $ticket_unit_id): array {
            $payload = $request->validated();

            return $this->transferReissue->transfer(
                eventId: (string) $event_id,
                occurrenceId: (string) $occurrence_id,
                ticketUnitId: (string) $ticket_unit_id,
                newPrincipalId: (string) $payload['new_principal_id'],
                idempotencyKey: (string) $payload['idempotency_key'],
                reasonCode: (string) $payload['reason_code'],
                reasonText: isset($payload['reason_text']) ? (string) $payload['reason_text'] : null,
                actorRef: $this->resolveActorRef($request),
            );
        });
    }

    public function reissue(
        TicketReissueRequest $request,
        string $event_id,
        string $occurrence_id,
        string $ticket_unit_id,
    ): JsonResponse {
        return $this->runWithDomainGuard(function () use ($request, $event_id, $occurrence_id, $ticket_unit_id): array {
            $payload = $request->validated();

            return $this->transferReissue->reissue(
                eventId: (string) $event_id,
                occurrenceId: (string) $occurrence_id,
                ticketUnitId: (string) $ticket_unit_id,
                newPrincipalId: isset($payload['new_principal_id']) ? (string) $payload['new_principal_id'] : null,
                idempotencyKey: (string) $payload['idempotency_key'],
                reasonCode: (string) $payload['reason_code'],
                reasonText: isset($payload['reason_text']) ? (string) $payload['reason_text'] : null,
                actorRef: $this->resolveActorRef($request),
            );
        });
    }

    /**
     * @return array<string, string>
     */
    private function resolveActorRef(TicketTransferRequest|TicketReissueRequest $request): array
    {
        $user = $request->user();
        $className = $user ? (string) $user::class : 'system';
        $actorType = str_contains($className, 'AccountUser') ? 'account_admin' : 'tenant_admin';

        return [
            'type' => $actorType,
            'id' => $user ? (string) $user->getAuthIdentifier() : 'system',
        ];
    }
}
