<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\TransferReissue;

use Belluga\Ticketing\Application\Async\TicketOutboxEmitter;
use Belluga\Ticketing\Application\Settings\TicketingRuntimeSettingsService;
use Belluga\Ticketing\Application\Transactions\TenantTransactionRunner;
use Belluga\Ticketing\Models\Tenants\TicketUnit;
use Belluga\Ticketing\Models\Tenants\TicketUnitAuditEvent;
use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TicketTransferReissueService
{
    public function __construct(
        private readonly TicketingRuntimeSettingsService $settings,
        private readonly TenantTransactionRunner $transactions,
        private readonly TicketOutboxEmitter $outbox,
    ) {
    }

    /**
     * @param array<string, mixed> $actorRef
     * @return array<string, mixed>
     */
    public function transfer(
        string $eventId,
        string $occurrenceId,
        string $ticketUnitId,
        string $newPrincipalId,
        string $idempotencyKey,
        string $reasonCode,
        ?string $reasonText,
        array $actorRef,
    ): array {
        return $this->execute(
            operation: 'transfer',
            eventId: $eventId,
            occurrenceId: $occurrenceId,
            ticketUnitId: $ticketUnitId,
            targetPrincipalId: $newPrincipalId,
            idempotencyKey: $idempotencyKey,
            reasonCode: $reasonCode,
            reasonText: $reasonText,
            actorRef: $actorRef,
        );
    }

    /**
     * @param array<string, mixed> $actorRef
     * @return array<string, mixed>
     */
    public function reissue(
        string $eventId,
        string $occurrenceId,
        string $ticketUnitId,
        ?string $newPrincipalId,
        string $idempotencyKey,
        string $reasonCode,
        ?string $reasonText,
        array $actorRef,
    ): array {
        /** @var TicketUnit|null $unit */
        $unit = TicketUnit::query()
            ->where('_id', $ticketUnitId)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->first();

        if (! $unit) {
            throw new TicketingDomainException('ticket_unit_not_found', 404);
        }

        $targetPrincipalId = $newPrincipalId !== null && trim($newPrincipalId) !== ''
            ? trim($newPrincipalId)
            : (string) ($unit->principal_id ?? '');

        return $this->execute(
            operation: 'reissue',
            eventId: $eventId,
            occurrenceId: $occurrenceId,
            ticketUnitId: $ticketUnitId,
            targetPrincipalId: $targetPrincipalId,
            idempotencyKey: $idempotencyKey,
            reasonCode: $reasonCode,
            reasonText: $reasonText,
            actorRef: $actorRef,
        );
    }

    /**
     * @param array<string, mixed> $actorRef
     * @return array<string, mixed>
     */
    private function execute(
        string $operation,
        string $eventId,
        string $occurrenceId,
        string $ticketUnitId,
        string $targetPrincipalId,
        string $idempotencyKey,
        string $reasonCode,
        ?string $reasonText,
        array $actorRef,
    ): array {
        if (! $this->settings->allowTransferReissue()) {
            throw new TicketingDomainException('transfer_reissue_disabled', 409);
        }

        /** @var TicketUnitAuditEvent|null $existing */
        $existing = TicketUnitAuditEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'status' => 'ok',
                'operation' => (string) ($existing->operation ?? $operation),
                'scope_binding' => (string) ($existing->scope_binding ?? 'ticket_unit'),
                'source_ticket_unit_id' => (string) ($existing->source_ticket_unit_id ?? ''),
                'target_ticket_unit_ids' => is_array($existing->target_ticket_unit_ids ?? null) ? $existing->target_ticket_unit_ids : [],
            ];
        }

        $targetPrincipalId = trim($targetPrincipalId);
        if ($targetPrincipalId === '') {
            throw new TicketingDomainException('target_principal_required', 422);
        }

        return $this->transactions->run(function () use (
            $operation,
            $eventId,
            $occurrenceId,
            $ticketUnitId,
            $targetPrincipalId,
            $idempotencyKey,
            $reasonCode,
            $reasonText,
            $actorRef
        ): array {
            /** @var TicketUnit|null $source */
            $source = TicketUnit::query()
                ->where('_id', $ticketUnitId)
                ->where('event_id', $eventId)
                ->where('occurrence_id', $occurrenceId)
                ->first();

            if (! $source) {
                throw new TicketingDomainException('ticket_unit_not_found', 404);
            }

            if ((string) ($source->lifecycle_state ?? '') !== 'issued') {
                throw new TicketingDomainException('ticket_not_issued', 409);
            }

            $scopeBinding = (string) ($source->participant_binding_scope ?? 'ticket_unit');
            $affected = $this->resolveAffectedUnits($source, $scopeBinding);
            $now = Carbon::now();
            $targets = [];

            foreach ($affected as $unit) {
                $previousVersion = (int) ($unit->version ?? 1);
                $plainCode = sprintf('tkt_%s', Str::replace('-', '', (string) Str::uuid()));
                $hash = hash('sha256', $plainCode);

                /** @var TicketUnit $newUnit */
                $newUnit = TicketUnit::query()->create([
                    'event_id' => (string) ($unit->event_id ?? ''),
                    'occurrence_id' => (string) ($unit->occurrence_id ?? ''),
                    'ticket_product_id' => (string) ($unit->ticket_product_id ?? ''),
                    'order_id' => (string) ($unit->order_id ?? ''),
                    'order_item_id' => (string) ($unit->order_item_id ?? ''),
                    'lifecycle_state' => 'issued',
                    'principal_id' => $targetPrincipalId,
                    'principal_type' => 'user',
                    'participant_binding_scope' => (string) ($unit->participant_binding_scope ?? 'ticket_unit'),
                    'admission_code_hash' => $hash,
                    'issued_at' => $now,
                    'version' => 1,
                    'audit' => [
                        'derived_from_ticket_unit_id' => (string) $unit->getAttribute('_id'),
                        'operation' => $operation,
                        'reason_code' => $reasonCode,
                    ],
                ]);

                $targetState = $operation === 'transfer' ? 'transferred' : 'reissued';
                $timestampField = $operation === 'transfer' ? 'transferred_at' : 'reissued_at';

                $updated = TicketUnit::query()
                    ->where('_id', (string) $unit->getAttribute('_id'))
                    ->where('version', $previousVersion)
                    ->where('lifecycle_state', 'issued')
                    ->update([
                        'lifecycle_state' => $targetState,
                        $timestampField => $now,
                        'superseded_by_ticket_unit_id' => (string) $newUnit->getAttribute('_id'),
                        'version' => $previousVersion + 1,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new TicketingDomainException('state_conflict', 409);
                }

                $targets[] = [
                    'ticket_unit_id' => (string) $newUnit->getAttribute('_id'),
                    'previous_ticket_unit_id' => (string) $unit->getAttribute('_id'),
                    'admission_code' => $plainCode,
                ];

                $this->outbox->emit(
                    topic: sprintf('ticketing.unit.%s', $targetState),
                    payload: [
                        'event_id' => (string) ($unit->event_id ?? ''),
                        'occurrence_id' => (string) ($unit->occurrence_id ?? ''),
                        'ticket_unit_id' => (string) $unit->getAttribute('_id'),
                        'order_item_id' => (string) ($unit->order_item_id ?? ''),
                        'correlation_id' => $idempotencyKey,
                        'causation_id' => (string) $unit->getAttribute('_id'),
                        'occurred_at' => $now->toISOString(),
                    ],
                    dedupeKey: sprintf('unit.%s:%s', $targetState, (string) $unit->getAttribute('_id')),
                );

                $this->outbox->emit(
                    topic: 'ticketing.unit.issued',
                    payload: [
                        'event_id' => (string) ($newUnit->event_id ?? ''),
                        'occurrence_id' => (string) ($newUnit->occurrence_id ?? ''),
                        'ticket_unit_id' => (string) $newUnit->getAttribute('_id'),
                        'order_item_id' => (string) ($newUnit->order_item_id ?? ''),
                        'correlation_id' => $idempotencyKey,
                        'causation_id' => (string) $unit->getAttribute('_id'),
                        'occurred_at' => $now->toISOString(),
                    ],
                    dedupeKey: sprintf('unit.issued:%s', (string) $newUnit->getAttribute('_id')),
                );
            }

            TicketUnitAuditEvent::query()->create([
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'operation' => $operation,
                'scope_binding' => $scopeBinding,
                'source_ticket_unit_id' => (string) $source->getAttribute('_id'),
                'target_ticket_unit_ids' => array_values(array_map(
                    static fn (array $item): string => (string) ($item['ticket_unit_id'] ?? ''),
                    $targets
                )),
                'actor_ref' => $actorRef,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'target_principal_id' => $targetPrincipalId,
                ],
            ]);

            return [
                'status' => 'ok',
                'operation' => $operation,
                'scope_binding' => $scopeBinding,
                'source_ticket_unit_id' => (string) $source->getAttribute('_id'),
                'targets' => $targets,
            ];
        });
    }

    /**
     * @return array<int, TicketUnit>
     */
    private function resolveAffectedUnits(TicketUnit $source, string $scopeBinding): array
    {
        if ($scopeBinding === 'ticket_unit') {
            /** @var array<int, TicketUnit> $ticketScopeUnits */
            $ticketScopeUnits = TicketUnit::query()
                ->where('_id', (string) $source->getAttribute('_id'))
                ->where('lifecycle_state', 'issued')
                ->get()
                ->all();

            return $ticketScopeUnits;
        }

        if (! in_array($scopeBinding, ['combo_unit', 'passport_unit'], true)) {
            throw new TicketingDomainException('participant_scope_locked', 409);
        }

        $orderItemId = (string) ($source->order_item_id ?? '');
        if ($orderItemId === '') {
            throw new TicketingDomainException('participant_scope_locked', 409);
        }

        /** @var array<int, TicketUnit> $groupUnits */
        $groupUnits = TicketUnit::query()
            ->where('order_item_id', $orderItemId)
            ->where('participant_binding_scope', $scopeBinding)
            ->where('lifecycle_state', 'issued')
            ->get()
            ->all();

        if ($groupUnits === []) {
            throw new TicketingDomainException('participant_scope_locked', 409);
        }

        return $groupUnits;
    }
}

