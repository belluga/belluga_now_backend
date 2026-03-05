<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Lifecycle;

use Belluga\Ticketing\Application\Async\TicketOutboxEmitter;
use Belluga\Ticketing\Application\Transactions\TenantTransactionRunner;
use Belluga\Ticketing\Models\Tenants\TicketCheckinLog;
use Belluga\Ticketing\Models\Tenants\TicketUnit;
use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Support\Carbon;

class TicketUnitLifecycleService
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $legalTransitions = [
        'reserved' => ['issued', 'canceled', 'voided'],
        'issued' => ['consumed', 'expired', 'canceled', 'refunded', 'reissued', 'transferred'],
        'consumed' => ['refunded'],
        'expired' => ['refunded'],
        'canceled' => [],
        'refunded' => [],
        'voided' => [],
        'reissued' => [],
        'transferred' => [],
    ];

    public function __construct(
        private readonly TenantTransactionRunner $transactions,
        private readonly TicketOutboxEmitter $outbox,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function transition(TicketUnit $unit, string $targetState, array $context = []): TicketUnit
    {
        $currentState = (string) ($unit->lifecycle_state ?? 'issued');
        $allowed = $this->legalTransitions[$currentState] ?? [];

        if (! in_array($targetState, $allowed, true)) {
            throw new TicketingDomainException('illegal_lifecycle_transition', 409);
        }

        $unit->lifecycle_state = $targetState;
        $unit->version = (int) ($unit->version ?? 1) + 1;

        $timestamp = Carbon::now();
        if ($targetState === 'consumed') {
            $unit->consumed_at = $timestamp;
        }

        if ($targetState === 'expired') {
            $unit->expired_at = $timestamp;
        }

        if ($targetState === 'canceled') {
            $unit->canceled_at = $timestamp;
        }

        if ($targetState === 'refunded') {
            $unit->refunded_at = $timestamp;
        }

        $unit->save();

        $this->outbox->emit(
            topic: sprintf('ticketing.unit.%s', $targetState),
            payload: [
                'event_id' => (string) ($unit->event_id ?? ''),
                'occurrence_id' => (string) ($unit->occurrence_id ?? ''),
                'ticket_unit_id' => (string) $unit->getAttribute('_id'),
                'order_item_id' => (string) ($unit->order_item_id ?? ''),
                'correlation_id' => (string) ($context['correlation_id'] ?? ''),
                'causation_id' => (string) ($context['causation_id'] ?? ''),
                'occurred_at' => Carbon::now()->toISOString(),
            ],
            dedupeKey: sprintf('unit.%s:%s', $targetState, (string) $unit->getAttribute('_id')),
        );

        return $unit->fresh();
    }

    /**
     * @param array<string, mixed> $actorRef
     * @return array<string, mixed>
     */
    public function validateAndConsume(
        string $eventId,
        string $occurrenceId,
        ?string $ticketUnitId,
        ?string $admissionCode,
        string $checkpointRef,
        string $idempotencyKey,
        array $actorRef,
    ): array {
        /** @var TicketCheckinLog|null $log */
        $log = TicketCheckinLog::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($log) {
            return [
                'status' => (string) ($log->status ?? 'denied'),
                'code' => (string) ($log->reason_code ?? 'idempotent_replay'),
                'ticket_unit_id' => (string) ($log->ticket_unit_id ?? ''),
            ];
        }

        $unit = $this->resolveUnit($eventId, $occurrenceId, $ticketUnitId, $admissionCode);
        if (! $unit) {
            TicketCheckinLog::query()->create([
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'checkpoint_ref' => $checkpointRef,
                'actor_ref' => $actorRef,
                'proof_ref' => ['admission_code' => $admissionCode, 'ticket_unit_id' => $ticketUnitId],
                'status' => 'denied',
                'reason_code' => 'ticket_unit_not_found',
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'denied',
                'code' => 'ticket_unit_not_found',
            ];
        }

        if ((string) ($unit->lifecycle_state ?? '') !== 'issued') {
            TicketCheckinLog::query()->create([
                'ticket_unit_id' => (string) $unit->getAttribute('_id'),
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'checkpoint_ref' => $checkpointRef,
                'actor_ref' => $actorRef,
                'proof_ref' => ['admission_code' => $admissionCode, 'ticket_unit_id' => $ticketUnitId],
                'status' => 'denied',
                'reason_code' => 'ticket_not_issued',
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'denied',
                'code' => 'ticket_not_issued',
                'ticket_unit_id' => (string) $unit->getAttribute('_id'),
            ];
        }

        return $this->transactions->run(function () use ($unit, $eventId, $occurrenceId, $checkpointRef, $idempotencyKey, $actorRef, $admissionCode, $ticketUnitId): array {
            $fresh = TicketUnit::query()->find((string) $unit->getAttribute('_id'));
            if (! $fresh) {
                throw new TicketingDomainException('ticket_unit_not_found', 404);
            }

            if ((string) ($fresh->lifecycle_state ?? '') !== 'issued') {
                throw new TicketingDomainException('state_conflict', 409);
            }

            $consumed = $this->transition($fresh, 'consumed', [
                'correlation_id' => $idempotencyKey,
                'causation_id' => (string) $fresh->getAttribute('_id'),
            ]);

            TicketCheckinLog::query()->create([
                'ticket_unit_id' => (string) $consumed->getAttribute('_id'),
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'checkpoint_ref' => $checkpointRef,
                'actor_ref' => $actorRef,
                'proof_ref' => ['admission_code' => $admissionCode, 'ticket_unit_id' => $ticketUnitId],
                'status' => 'consumed',
                'reason_code' => 'ok',
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->outbox->emit(
                topic: 'participation.presence.recorded',
                payload: [
                    'event_id' => (string) $consumed->event_id,
                    'occurrence_id' => (string) $consumed->occurrence_id,
                    'ticket_unit_id' => (string) $consumed->getAttribute('_id'),
                    'actor_ref' => $actorRef,
                    'occurred_at' => Carbon::now()->toISOString(),
                ],
                dedupeKey: sprintf('presence.recorded:%s', (string) $consumed->getAttribute('_id')),
            );

            return [
                'status' => 'consumed',
                'code' => 'ok',
                'ticket_unit_id' => (string) $consumed->getAttribute('_id'),
            ];
        });
    }

    public function expireIssuedByOccurrence(string $occurrenceId, Carbon $occurrenceEndAt, int $graceMinutes): int
    {
        $threshold = $occurrenceEndAt->copy()->addMinutes($graceMinutes);
        if ($threshold->isFuture()) {
            return 0;
        }

        /** @var array<int, TicketUnit> $units */
        $units = TicketUnit::query()
            ->where('occurrence_id', $occurrenceId)
            ->where('lifecycle_state', 'issued')
            ->limit(1000)
            ->get()
            ->all();

        $count = 0;
        foreach ($units as $unit) {
            $this->transition($unit, 'expired', [
                'correlation_id' => sprintf('lapse:%s', $occurrenceId),
                'causation_id' => (string) $unit->getAttribute('_id'),
            ]);
            $count++;
        }

        return $count;
    }

    private function resolveUnit(string $eventId, string $occurrenceId, ?string $ticketUnitId, ?string $admissionCode): ?TicketUnit
    {
        if ($ticketUnitId) {
            /** @var TicketUnit|null $unit */
            $unit = TicketUnit::query()
                ->where('_id', $ticketUnitId)
                ->where('event_id', $eventId)
                ->where('occurrence_id', $occurrenceId)
                ->first();

            return $unit;
        }

        if (! $admissionCode) {
            return null;
        }

        $hash = hash('sha256', $admissionCode);

        /** @var TicketUnit|null $unit */
        $unit = TicketUnit::query()
            ->where('admission_code_hash', $hash)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->first();

        return $unit;
    }
}
