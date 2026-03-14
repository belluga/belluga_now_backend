<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Events\AttendanceCommitmentService;
use App\Http\Api\v1\Requests\EventAttendanceConfirmRequest;
use App\Http\Api\v1\Requests\EventAttendanceUnconfirmRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

class EventAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceCommitmentService $attendanceCommitmentService,
        private readonly EventQueryService $eventQueryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        $confirmedEventIds = $this->attendanceCommitmentService->confirmedEventIds($userId);

        return response()->json([
            'tenant_id' => $this->currentTenantId(),
            'data' => [
                'confirmed_event_ids' => $confirmedEventIds,
            ],
        ]);
    }

    public function confirm(
        EventAttendanceConfirmRequest $request,
        string $tenant_domain,
        string $event_id
    ): JsonResponse {
        $event = $this->resolveEventOrFail($event_id);
        $this->assertPublicVisibleOrFail($event);

        $eventId = (string) $event->getAttribute('_id');
        $occurrenceId = $this->resolveOccurrenceIdOrFail(
            eventId: $eventId,
            occurrenceId: $request->validated('occurrence_id'),
        );
        $this->assertFreeConfirmationAllowed($event, $occurrenceId);

        $commitment = $this->attendanceCommitmentService->confirm(
            userId: $this->authenticatedUserId($request),
            eventId: $eventId,
            occurrenceId: $occurrenceId,
        );

        return response()->json([
            'tenant_id' => $this->currentTenantId(),
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'kind' => 'free_confirmation',
            'status' => (string) $commitment->status,
            'confirmed_at' => $commitment->confirmed_at?->toISOString(),
        ]);
    }

    public function unconfirm(
        EventAttendanceUnconfirmRequest $request,
        string $tenant_domain,
        string $event_id
    ): JsonResponse {
        $event = $this->resolveEventOrFail($event_id);
        $this->assertPublicVisibleOrFail($event);

        $eventId = (string) $event->getAttribute('_id');
        $occurrenceId = $this->resolveOccurrenceIdOrFail(
            eventId: $eventId,
            occurrenceId: $request->validated('occurrence_id'),
        );

        $commitment = $this->attendanceCommitmentService->unconfirm(
            userId: $this->authenticatedUserId($request),
            eventId: $eventId,
            occurrenceId: $occurrenceId,
        );

        if (! $commitment) {
            return response()->json([
                'tenant_id' => $this->currentTenantId(),
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'kind' => 'free_confirmation',
                'status' => 'canceled',
                'canceled_at' => null,
            ]);
        }

        return response()->json([
            'tenant_id' => $this->currentTenantId(),
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'kind' => 'free_confirmation',
            'status' => (string) $commitment->status,
            'canceled_at' => $commitment->canceled_at?->toISOString(),
        ]);
    }

    private function resolveEventOrFail(string $eventIdOrSlug): Event
    {
        /** @var Event|null $event */
        $event = $this->eventQueryService->findByIdOrSlug($eventIdOrSlug);
        if (! $event) {
            abort(404, 'Event not found.');
        }

        return $event;
    }

    private function resolveOccurrenceIdOrFail(string $eventId, ?string $occurrenceId): ?string
    {
        $normalizedOccurrenceId = is_string($occurrenceId) ? trim($occurrenceId) : null;
        if ($normalizedOccurrenceId === null || $normalizedOccurrenceId === '') {
            return null;
        }

        $query = EventOccurrence::query()->where('event_id', $eventId);
        if ($this->looksLikeObjectId($normalizedOccurrenceId)) {
            $query->where('_id', new ObjectId($normalizedOccurrenceId));
        } else {
            $query->where('_id', $normalizedOccurrenceId);
        }

        /** @var EventOccurrence|null $occurrence */
        $occurrence = $query->first();
        if (! $occurrence) {
            abort(404, 'Occurrence not found.');
        }
        if ((bool) ($occurrence->is_event_published ?? false) !== true || $occurrence->deleted_at !== null) {
            abort(404, 'Occurrence not found.');
        }

        return (string) $occurrence->getAttribute('_id');
    }

    private function assertPublicVisibleOrFail(Event $event): void
    {
        try {
            $this->eventQueryService->assertPublicVisible($event);
        } catch (EventNotPubliclyVisibleException) {
            abort(404, 'Event not found.');
        }
    }

    private function assertFreeConfirmationAllowed(Event $event, ?string $occurrenceId): void
    {
        $eventPolicy = trim((string) ($event->attendance_policy ?? ''));
        $resolvedPolicy = $eventPolicy !== '' ? $eventPolicy : 'free_confirmation_only';

        if (
            $occurrenceId !== null &&
            (bool) ($event->allow_occurrence_policy_override ?? false) === true
        ) {
            $query = EventOccurrence::query()->where('event_id', (string) $event->getAttribute('_id'));
            if ($this->looksLikeObjectId($occurrenceId)) {
                $query->where('_id', new ObjectId($occurrenceId));
            } else {
                $query->where('_id', $occurrenceId);
            }

            /** @var EventOccurrence|null $occurrence */
            $occurrence = $query->first();
            $occurrencePolicy = trim((string) ($occurrence?->attendance_policy ?? ''));
            if ($occurrencePolicy !== '') {
                $resolvedPolicy = $occurrencePolicy;
            }
        }

        if ($resolvedPolicy === 'paid_reservation_only') {
            throw ValidationException::withMessages([
                'event_id' => ['Event requires paid reservation before confirmation.'],
            ]);
        }
    }

    private function authenticatedUserId(Request $request): string
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthorized');
        }

        return (string) $user->getAuthIdentifier();
    }

    private function currentTenantId(): ?string
    {
        $tenant = Tenant::current();

        return $tenant ? (string) $tenant->getAttribute('_id') : null;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
