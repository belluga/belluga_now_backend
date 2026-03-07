<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Guards;

use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;

class OccurrenceWriteGuardService
{
    public function __construct(
        private readonly TicketingPolicyContract $policy,
        private readonly OccurrenceReadContract $occurrenceRead,
        private readonly OccurrencePublicationContract $occurrencePublication,
    ) {
    }

    /**
     * @return array{allowed:bool,code:string,occurrence?:array<string,mixed>}
     */
    public function evaluate(?string $eventRef, string $occurrenceRef, bool $isAuthenticated): array
    {
        if (! $this->policy->isTicketingEnabled()) {
            return [
                'allowed' => false,
                'code' => 'ticketing_disabled',
            ];
        }

        if ($this->policy->identityMode() === 'auth_only' && ! $isAuthenticated) {
            return [
                'allowed' => false,
                'code' => 'auth_required',
            ];
        }

        $resolved = $this->occurrenceRead->resolveOccurrenceRefs($eventRef, $occurrenceRef);
        if (! is_array($resolved)) {
            return [
                'allowed' => false,
                'code' => 'occurrence_not_found',
            ];
        }

        $eventId = (string) ($resolved['event_id'] ?? '');
        $occurrenceId = (string) ($resolved['occurrence_id'] ?? '');
        if ($eventId === '' || $occurrenceId === '') {
            return [
                'allowed' => false,
                'code' => 'occurrence_not_found',
            ];
        }

        $occurrence = $this->occurrenceRead->findOccurrence($eventId, $occurrenceId);
        if (! is_array($occurrence)) {
            return [
                'allowed' => false,
                'code' => 'occurrence_not_found',
            ];
        }

        if (($occurrence['deleted_at'] ?? null) !== null) {
            return [
                'allowed' => false,
                'code' => 'occurrence_deleted',
            ];
        }

        if (! $this->occurrencePublication->isOccurrencePublished($eventId, $occurrenceId)) {
            return [
                'allowed' => false,
                'code' => 'occurrence_unpublished',
            ];
        }

        return [
            'allowed' => true,
            'code' => 'ok',
            'occurrence' => [
                ...$occurrence,
                'event_id' => $eventId,
                'id' => $occurrenceId,
            ],
        ];
    }
}
