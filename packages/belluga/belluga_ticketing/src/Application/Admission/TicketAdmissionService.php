<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Admission;

use Belluga\Ticketing\Application\Guards\OccurrenceWriteGuardService;
use Belluga\Ticketing\Application\Holds\TicketHoldService;
use Belluga\Ticketing\Application\Inventory\InventoryMutationService;
use Belluga\Ticketing\Application\Promotions\TicketPromotionResolverService;
use Belluga\Ticketing\Application\Queue\TicketQueueService;
use Belluga\Ticketing\Application\Settings\TicketingRuntimeSettingsService;
use Belluga\Ticketing\Application\Transactions\TenantTransactionRunner;
use Belluga\Ticketing\Models\Tenants\TicketHold;
use Belluga\Ticketing\Models\Tenants\TicketQueueEntry;
use Belluga\Ticketing\Support\TicketingDomainException;

class TicketAdmissionService
{
    public function __construct(
        private readonly OccurrenceWriteGuardService $occurrenceWriteGuard,
        private readonly TicketingRuntimeSettingsService $settings,
        private readonly InventoryMutationService $inventory,
        private readonly TicketHoldService $holds,
        private readonly TicketQueueService $queue,
        private readonly TicketPromotionResolverService $promotions,
        private readonly TenantTransactionRunner $transactions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function offerForRefs(?string $eventRef, string $occurrenceRef): array
    {
        $guard = $this->occurrenceWriteGuard->evaluate($eventRef, $occurrenceRef, true);
        if (($guard['allowed'] ?? false) !== true) {
            $code = (string) ($guard['code'] ?? 'admission_rejected');
            throw new TicketingDomainException($code, $this->guardHttpStatus($code));
        }

        $eventId = (string) data_get($guard, 'occurrence.event_id', '');
        $occurrenceId = (string) data_get($guard, 'occurrence.id', '');
        if ($eventId === '' || $occurrenceId === '') {
            throw new TicketingDomainException('occurrence_not_found', 404);
        }

        $products = $this->inventory->listSellableProducts($eventId, $occurrenceId);

        $items = [];
        foreach ($products as $product) {
            $availability = $this->inventory->availabilityForProduct($product, $eventId, $occurrenceId);
            $items[] = [
                'ticket_product_id' => (string) $product->getAttribute('_id'),
                'scope_type' => (string) ($product->scope_type ?? 'occurrence'),
                'product_type' => (string) ($product->product_type ?? 'ticket'),
                'name' => (string) ($product->name ?? ''),
                'inventory_mode' => (string) ($product->inventory_mode ?? 'limited'),
                'price' => is_array($product->price ?? null) ? $product->price : ['amount' => 0, 'currency' => 'BRL'],
                'available' => $availability['available'],
                'is_unlimited' => $availability['is_unlimited'],
            ];
        }

        return [
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'queue_mode' => $this->settings->queueMode(),
            'identity_mode' => $this->settings->identityMode(),
            'default_hold_minutes' => $this->settings->defaultHoldMinutes(),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function requestAdmissionForRefs(
        ?string $eventRef,
        string $occurrenceRef,
        ?string $principalId,
        bool $isAuthenticated,
        array $payload,
    ): array {
        $guard = $this->occurrenceWriteGuard->evaluate($eventRef, $occurrenceRef, $isAuthenticated);
        if (($guard['allowed'] ?? false) !== true) {
            return [
                'status' => 'rejected',
                'code' => (string) ($guard['code'] ?? 'admission_rejected'),
            ];
        }

        $eventId = (string) data_get($guard, 'occurrence.event_id', '');
        $occurrenceId = (string) data_get($guard, 'occurrence.id', '');
        if ($eventId === '' || $occurrenceId === '') {
            return [
                'status' => 'rejected',
                'code' => 'occurrence_not_found',
            ];
        }

        if (! $principalId) {
            throw new TicketingDomainException('auth_required', 401);
        }

        $queueToken = (string) ($payload['queue_token'] ?? '');
        if ($queueToken !== '') {
            $queuedResult = $this->resolveQueueToken($queueToken, $principalId);
            if ($queuedResult !== null) {
                return $queuedResult;
            }
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');
        if ($idempotencyKey === '') {
            throw new TicketingDomainException('idempotency_key_required', 422);
        }

        $lines = $this->inventory->hydrateLines($items, $eventId, $occurrenceId);
        $promotionCodes = $this->settings->promotionsEnabled()
            ? $this->normalizePromotionCodes($payload['promotion_codes'] ?? [])
            : [];
        $promotionResolution = $this->promotions->resolve(
            eventId: $eventId,
            occurrenceId: $occurrenceId,
            lines: $lines,
            promotionCodes: $promotionCodes,
        );
        $lines = $promotionResolution['lines'];
        $requested = array_sum(array_map(static fn (array $line): int => (int) ($line['quantity'] ?? 0), $lines));

        if ($requested > $this->settings->maxPerPrincipal()) {
            throw new TicketingDomainException('max_per_principal_exceeded', 422);
        }

        $scopeType = $this->resolveScopeType($lines);
        $scopeId = $scopeType === 'event' ? $eventId : $occurrenceId;

        $this->holds->releaseExpiredForScope($scopeType, $scopeId);
        $this->queue->markExpiredStaleEntries($scopeType, $scopeId);

        $preview = $this->inventory->previewAvailability($lines);
        if ($preview['limited'] === false) {
            return [
                'status' => 'not_applicable',
                'code' => 'unlimited_capacity',
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ];
        }

        if ($preview['insufficient'] === true) {
            return $this->queueOrReject($scopeType, $scopeId, $eventId, $occurrenceId, $principalId, $lines);
        }

        $checkoutMode = $this->resolveCheckoutMode($payload);
        $paymentProfile = $this->resolvePaymentProfile($checkoutMode);
        $eventHoldOverride = isset($guard['occurrence']['event_hold_minutes']) && is_numeric($guard['occurrence']['event_hold_minutes'])
            ? (int) $guard['occurrence']['event_hold_minutes']
            : null;
        $holdMinutes = $this->settings->resolveHoldMinutes($eventHoldOverride);

        try {
            /** @var TicketHold $hold */
            $hold = $this->transactions->run(function () use (
                $eventId,
                $occurrenceId,
                $scopeType,
                $scopeId,
                $principalId,
                $lines,
                $idempotencyKey,
                $holdMinutes,
                $paymentProfile,
                $checkoutMode,
                $promotionResolution,
            ): TicketHold {
                $this->inventory->reserveLines($lines);

                return $this->holds->createOrReuseActiveHold(
                    eventId: $eventId,
                    occurrenceId: $occurrenceId,
                    scopeType: $scopeType,
                    scopeId: $scopeId,
                    principalId: $principalId,
                    principalType: 'user',
                    lines: $lines,
                    idempotencyKey: $idempotencyKey,
                    holdMinutes: $holdMinutes,
                    paymentProfile: $paymentProfile,
                    checkoutMode: $checkoutMode,
                    promotionSnapshot: $promotionResolution['snapshot'],
                );
            });
        } catch (TicketingDomainException $exception) {
            if (in_array($exception->errorCode, ['sold_out', 'inventory_conflict'], true)) {
                return $this->queueOrReject($scopeType, $scopeId, $eventId, $occurrenceId, $principalId, $lines);
            }

            throw $exception;
        }

        return $this->holdGrantedResponse($hold);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshTokens(string $principalId, ?string $queueToken, ?string $holdToken): array
    {
        if ($queueToken) {
            $entry = $this->queue->findByTokenForPrincipal($queueToken, $principalId);
            if (! $entry || (string) $entry->status !== 'active') {
                throw new TicketingDomainException('queue_token_not_active', 409);
            }

            $refreshed = $this->queue->refreshToken($entry);

            return [
                'status' => 'queued',
                'code' => 'queue_token_refreshed',
                'queue_entry_id' => (string) $refreshed->getAttribute('_id'),
                'queue_token' => (string) $refreshed->queue_token,
                'expires_at' => optional($refreshed->expires_at)->toISOString(),
                'position' => $this->queue->visiblePosition($refreshed),
            ];
        }

        if ($holdToken) {
            $hold = $this->holds->findByToken($holdToken);
            if (! $hold) {
                throw new TicketingDomainException('hold_not_found', 404);
            }

            $this->holds->assertHoldActive($hold, $principalId);

            return [
                'status' => 'hold_granted',
                'code' => 'hold_token_non_renewable',
                'hold_id' => (string) $hold->getAttribute('_id'),
                'hold_token' => (string) $hold->hold_token,
                'expires_at' => optional($hold->expires_at)->toISOString(),
            ];
        }

        throw new TicketingDomainException('refresh_token_required', 422);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function queueOrReject(
        string $scopeType,
        string $scopeId,
        string $eventId,
        string $occurrenceId,
        string $principalId,
        array $lines,
    ): array {
        if ($this->settings->queueMode() === 'off') {
            return [
                'status' => 'sold_out',
                'code' => 'admission_required',
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ];
        }

        $entry = $this->queue->enqueue(
            scopeType: $scopeType,
            scopeId: $scopeId,
            eventId: $eventId,
            occurrenceId: $occurrenceId,
            principalId: $principalId,
            principalType: 'user',
            lines: $lines,
        );

        return [
            'status' => 'queued',
            'code' => 'queue_active',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'queue_entry_id' => (string) $entry->getAttribute('_id'),
            'queue_token' => (string) $entry->queue_token,
            'position' => $this->queue->visiblePosition($entry),
            'expires_at' => optional($entry->expires_at)->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveQueueToken(string $queueToken, string $principalId): ?array
    {
        $entry = $this->queue->findByTokenForPrincipal($queueToken, $principalId);
        if (! $entry) {
            return null;
        }

        if ((string) $entry->status === 'admitted' && is_string($entry->admitted_hold_id) && $entry->admitted_hold_id !== '') {
            $hold = TicketHold::query()->find((string) $entry->admitted_hold_id);
            if ($hold && in_array((string) $hold->status, ['active', 'awaiting_payment'], true)) {
                return $this->holdGrantedResponse($hold);
            }
        }

        if ((string) $entry->status === 'active') {
            $lines = is_array($entry->lines ?? null) ? $entry->lines : [];
            $preview = $this->inventory->previewAvailability($lines);
            if ($preview['insufficient'] === false) {
                /** @var TicketHold $hold */
                $hold = $this->transactions->run(function () use ($entry, $lines): TicketHold {
                    $this->inventory->reserveLines($lines);

                    $hold = $this->holds->createOrReuseActiveHold(
                        eventId: (string) ($entry->event_id ?? ''),
                        occurrenceId: (string) ($entry->occurrence_id ?? ''),
                        scopeType: (string) ($entry->scope_type ?? 'occurrence'),
                        scopeId: (string) ($entry->scope_id ?? ''),
                        principalId: (string) ($entry->principal_id ?? ''),
                        principalType: (string) ($entry->principal_type ?? 'user'),
                        lines: $lines,
                        idempotencyKey: sprintf('queue-admit:%s', (string) $entry->getAttribute('_id')),
                        holdMinutes: $this->settings->defaultHoldMinutes(),
                        paymentProfile: 'free',
                        checkoutMode: 'free',
                        queueEntryId: (string) $entry->getAttribute('_id'),
                        promotionSnapshot: $this->promotionSnapshotFromLines($lines),
                    );

                    $this->queue->markAdmitted($entry, (string) $hold->getAttribute('_id'));

                    return $hold;
                });

                return $this->holdGrantedResponse($hold);
            }

            return [
                'status' => 'queued',
                'code' => 'queue_active',
                'scope_type' => (string) $entry->scope_type,
                'scope_id' => (string) $entry->scope_id,
                'queue_entry_id' => (string) $entry->getAttribute('_id'),
                'queue_token' => (string) $entry->queue_token,
                'position' => $this->queue->visiblePosition($entry),
                'expires_at' => optional($entry->expires_at)->toISOString(),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function holdGrantedResponse(TicketHold $hold): array
    {
        return [
            'status' => 'hold_granted',
            'code' => 'ok',
            'hold_id' => (string) $hold->getAttribute('_id'),
            'hold_token' => (string) $hold->hold_token,
            'expires_at' => optional($hold->expires_at)->toISOString(),
            'scope_type' => (string) $hold->scope_type,
            'scope_id' => (string) $hold->scope_id,
            'checkout_mode' => (string) $hold->checkout_mode,
            'lines' => is_array($hold->lines ?? null) ? $hold->lines : [],
            'snapshot' => is_array($hold->snapshot ?? null) ? $hold->snapshot : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function resolveScopeType(array $lines): string
    {
        foreach ($lines as $line) {
            if ((string) ($line['scope_type'] ?? 'occurrence') === 'event') {
                return 'event';
            }

            if ((string) ($line['product_type'] ?? 'ticket') === 'passport') {
                return 'event';
            }
        }

        return 'occurrence';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCheckoutMode(array $payload): string
    {
        $mode = strtolower((string) ($payload['checkout_mode'] ?? $this->settings->defaultCheckoutMode()));

        return in_array($mode, ['free', 'paid'], true) ? $mode : 'free';
    }

    private function resolvePaymentProfile(string $checkoutMode): string
    {
        return $checkoutMode === 'free' ? 'free' : 'instant';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function promotionSnapshotFromLines(array $lines): array
    {
        $applied = [];
        $totals = [
            'discount_amount' => 0,
            'fee_amount' => 0,
        ];

        foreach ($lines as $line) {
            $totals['discount_amount'] += (int) ($line['discount_amount'] ?? 0);
            $totals['fee_amount'] += (int) ($line['fee_amount'] ?? 0);

            $lineApplied = is_array($line['applied_promotions'] ?? null) ? $line['applied_promotions'] : [];
            foreach ($lineApplied as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $promotionId = (string) ($item['promotion_id'] ?? '');
                if ($promotionId === '') {
                    continue;
                }
                $applied[$promotionId] = $item;
            }
        }

        return [
            'version' => 1,
            'requested_codes' => [],
            'applied' => array_values($applied),
            'rejected' => [],
            'totals' => $totals,
        ];
    }

    /**
     * @param mixed $rawCodes
     * @return array<int, string>
     */
    private function normalizePromotionCodes(mixed $rawCodes): array
    {
        if (! is_array($rawCodes)) {
            return [];
        }

        $codes = [];
        foreach ($rawCodes as $rawCode) {
            $code = trim((string) $rawCode);
            if ($code === '') {
                continue;
            }

            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    private function guardHttpStatus(string $code): int
    {
        return match ($code) {
            'occurrence_not_found', 'occurrence_deleted' => 404,
            'ticketing_disabled', 'occurrence_unpublished' => 409,
            'auth_required' => 401,
            default => 422,
        };
    }
}
