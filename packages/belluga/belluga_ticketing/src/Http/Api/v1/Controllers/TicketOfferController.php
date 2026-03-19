<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Admission\TicketAdmissionService;
use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketOfferController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function __construct(private readonly TicketAdmissionService $admission) {}

    public function occurrence(string $event_ref, string $occurrence_ref): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($event_ref, $occurrence_ref): array {
            return [
                'status' => 'ok',
                'data' => $this->admission->offerForRefs((string) $event_ref, (string) $occurrence_ref),
            ];
        });
    }

    public function occurrenceOnly(string $occurrence_ref): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($occurrence_ref): array {
            return [
                'status' => 'ok',
                'data' => $this->admission->offerForRefs(null, (string) $occurrence_ref),
            ];
        });
    }
}
