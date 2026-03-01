<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Checkout;

use Belluga\Ticketing\Application\Async\TicketOutboxEmitter;
use Belluga\Ticketing\Application\Holds\TicketHoldService;
use Belluga\Ticketing\Application\Inventory\InventoryMutationService;
use Belluga\Ticketing\Application\Transactions\TenantTransactionRunner;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;
use Belluga\Ticketing\Models\Tenants\TicketHold;
use Belluga\Ticketing\Models\Tenants\TicketOrder;
use Belluga\Ticketing\Models\Tenants\TicketOrderItem;
use Belluga\Ticketing\Models\Tenants\TicketProduct;
use Belluga\Ticketing\Models\Tenants\TicketUnit;
use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TicketCheckoutService
{
    public function __construct(
        private readonly TicketHoldService $holds,
        private readonly CheckoutPayloadAssembler $payloadAssembler,
        private readonly CheckoutOrchestratorContract $checkoutOrchestrator,
        private readonly InventoryMutationService $inventory,
        private readonly TenantTransactionRunner $transactions,
        private readonly TicketOutboxEmitter $outbox,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function showCart(string $holdToken, string $principalId): array
    {
        $hold = $this->holds->findByToken($holdToken);
        if (! $hold) {
            throw new TicketingDomainException('admission_required', 409);
        }

        $this->holds->assertHoldActive($hold, $principalId);

        return [
            'hold_id' => (string) $hold->getAttribute('_id'),
            'hold_token' => (string) $hold->hold_token,
            'expires_at' => optional($hold->expires_at)->toISOString(),
            'lines' => is_array($hold->lines ?? null) ? $hold->lines : [],
            'snapshot' => is_array($hold->snapshot ?? null) ? $hold->snapshot : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(
        string $holdToken,
        string $principalId,
        string $idempotencyKey,
        string $checkoutMode,
        ?string $accountId,
    ): array {
        /** @var TicketOrder|null $existing */
        $existing = TicketOrder::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->formatOrderResponse($existing, []);
        }

        $hold = $this->holds->findByToken($holdToken);
        if (! $hold) {
            throw new TicketingDomainException('admission_required', 409);
        }

        $this->holds->assertHoldActive($hold, $principalId);

        $checkoutPayload = $this->payloadAssembler->buildFromHold($hold, $accountId, $checkoutMode);
        $checkoutResult = $this->checkoutOrchestrator->beginCheckout($checkoutPayload, $idempotencyKey);

        $status = (string) ($checkoutResult['status'] ?? 'rejected');
        if ($status !== 'accepted') {
            return [
                'status' => 'rejected',
                'code' => (string) ($checkoutResult['code'] ?? 'checkout_rejected'),
            ];
        }

        if ($checkoutMode === 'paid') {
            /** @var TicketOrder $order */
            $order = TicketOrder::query()->create([
                'event_id' => (string) ($hold->event_id ?? ''),
                'occurrence_id' => (string) ($hold->occurrence_id ?? ''),
                'account_id' => $accountId,
                'principal_id' => $principalId,
                'principal_type' => 'user',
                'status' => 'pending_payment',
                'hold_id' => (string) $hold->getAttribute('_id'),
                'checkout_mode' => 'paid',
                'checkout_payload_snapshot' => $checkoutPayload,
                'checkout_snapshot_hash' => (string) ($checkoutPayload['snapshot_hash'] ?? ''),
                'financial_snapshot' => $this->payloadAssembler->buildFinancialSnapshot($checkoutPayload),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->holds->markAwaitingPayment($hold);

            return $this->formatOrderResponse($order, []);
        }

        $result = $this->transactions->run(function () use ($hold, $principalId, $accountId, $idempotencyKey, $checkoutPayload): array {
            $freshHold = TicketHold::query()->find((string) $hold->getAttribute('_id'));
            if (! $freshHold) {
                throw new TicketingDomainException('hold_not_found', 404);
            }

            $this->holds->assertHoldActive($freshHold, $principalId);

            $financialSnapshot = $this->payloadAssembler->buildFinancialSnapshot($checkoutPayload);

            /** @var TicketOrder $order */
            $order = TicketOrder::query()->create([
                'event_id' => (string) ($freshHold->event_id ?? ''),
                'occurrence_id' => (string) ($freshHold->occurrence_id ?? ''),
                'account_id' => $accountId,
                'principal_id' => $principalId,
                'principal_type' => 'user',
                'status' => 'confirmed',
                'hold_id' => (string) $freshHold->getAttribute('_id'),
                'checkout_mode' => 'free',
                'checkout_payload_snapshot' => $checkoutPayload,
                'checkout_snapshot_hash' => (string) ($checkoutPayload['snapshot_hash'] ?? ''),
                'financial_snapshot' => $financialSnapshot,
                'idempotency_key' => $idempotencyKey,
                'confirmed_at' => Carbon::now(),
            ]);

            $lines = is_array($freshHold->lines ?? null) ? $freshHold->lines : [];
            $purchaseOnlyReservationLines = $this->buildPurchaseOnlyReservationLines(
                lines: $lines,
                eventId: (string) ($freshHold->event_id ?? ''),
                defaultOccurrenceId: (string) ($freshHold->occurrence_id ?? ''),
            );

            if ($purchaseOnlyReservationLines !== []) {
                $this->inventory->reserveLines($purchaseOnlyReservationLines);
            }

            $units = [];

            foreach ($lines as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);
                $lineTotal = (int) ($line['unit_price'] ?? 0) * $quantity;

                /** @var TicketOrderItem $item */
                $item = TicketOrderItem::query()->create([
                    'order_id' => (string) $order->getAttribute('_id'),
                    'event_id' => (string) ($line['event_id'] ?? $freshHold->event_id ?? ''),
                    'occurrence_id' => (string) ($line['occurrence_id'] ?? $freshHold->occurrence_id ?? ''),
                    'ticket_product_id' => (string) ($line['ticket_product_id'] ?? ''),
                    'status' => 'confirmed',
                    'quantity' => $quantity,
                    'unit_price' => (int) ($line['unit_price'] ?? 0),
                    'currency' => (string) ($line['currency'] ?? 'BRL'),
                    'discount_amount' => 0,
                    'fee_amount' => 0,
                    'line_total' => $lineTotal,
                    'snapshot' => $line,
                ]);

                for ($index = 0; $index < $quantity; $index++) {
                    $plainCode = sprintf('tkt_%s', Str::replace('-', '', (string) Str::uuid()));
                    $hash = hash('sha256', $plainCode);

                    /** @var TicketUnit $unit */
                    $unit = TicketUnit::query()->create([
                        'event_id' => (string) ($line['event_id'] ?? $freshHold->event_id ?? ''),
                        'occurrence_id' => (string) ($line['occurrence_id'] ?? $freshHold->occurrence_id ?? ''),
                        'ticket_product_id' => (string) ($line['ticket_product_id'] ?? ''),
                        'order_id' => (string) $order->getAttribute('_id'),
                        'order_item_id' => (string) $item->getAttribute('_id'),
                        'lifecycle_state' => 'issued',
                        'principal_id' => $principalId,
                        'principal_type' => 'user',
                        'participant_binding_scope' => 'ticket_unit',
                        'admission_code_hash' => $hash,
                        'issued_at' => Carbon::now(),
                        'version' => 1,
                    ]);

                    $units[] = [
                        'ticket_unit_id' => (string) $unit->getAttribute('_id'),
                        'order_item_id' => (string) $item->getAttribute('_id'),
                        'occurrence_id' => (string) ($line['occurrence_id'] ?? $freshHold->occurrence_id ?? ''),
                        'admission_code' => $plainCode,
                    ];

                    $this->outbox->emit(
                        topic: 'ticketing.unit.issued',
                        payload: [
                            'event_id' => (string) $unit->event_id,
                            'occurrence_id' => (string) $unit->occurrence_id,
                            'ticket_unit_id' => (string) $unit->getAttribute('_id'),
                            'order_item_id' => (string) $item->getAttribute('_id'),
                            'correlation_id' => $idempotencyKey,
                            'causation_id' => (string) $freshHold->getAttribute('_id'),
                            'occurred_at' => Carbon::now()->toISOString(),
                        ],
                        dedupeKey: sprintf('unit.issued:%s', (string) $unit->getAttribute('_id')),
                    );
                }
            }

            $this->inventory->confirmSaleFromHoldLines($lines);
            if ($purchaseOnlyReservationLines !== []) {
                $this->inventory->confirmSaleFromHoldLines($purchaseOnlyReservationLines);
            }
            $this->holds->markConfirmed($freshHold);

            return [$order->fresh(), $units];
        });

        /** @var TicketOrder $order */
        $order = $result[0];
        /** @var array<int, array<string, mixed>> $units */
        $units = $result[1];

        return $this->formatOrderResponse($order, $units);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelConfirmedOrder(string $orderId): array
    {
        /** @var TicketOrder|null $order */
        $order = TicketOrder::query()->find($orderId);
        if (! $order) {
            throw new TicketingDomainException('order_not_found', 404);
        }

        if (! in_array((string) $order->status, ['confirmed', 'pending_payment'], true)) {
            throw new TicketingDomainException('order_not_cancelable', 409);
        }

        $result = $this->transactions->run(function () use ($order): array {
            /** @var array<int, TicketOrderItem> $items */
            $items = TicketOrderItem::query()
                ->where('order_id', (string) $order->getAttribute('_id'))
                ->get()
                ->all();

            $lines = [];
            foreach ($items as $item) {
                $lines[] = $this->inventory->hydrateSingleLine(
                    ticketProductId: (string) ($item->ticket_product_id ?? ''),
                    quantity: (int) ($item->quantity ?? 0),
                    eventId: (string) ($item->event_id ?? ''),
                    occurrenceId: (string) ($item->occurrence_id ?? ''),
                );
            }

            if ((string) $order->status === 'confirmed') {
                $this->inventory->returnSoldLines($lines);
            }

            $order->status = 'canceled';
            $order->canceled_at = Carbon::now();
            $order->save();

            TicketOrderItem::query()
                ->where('order_id', (string) $order->getAttribute('_id'))
                ->update(['status' => 'canceled', 'updated_at' => Carbon::now()]);

            TicketUnit::query()
                ->where('order_id', (string) $order->getAttribute('_id'))
                ->whereIn('lifecycle_state', ['issued', 'reserved'])
                ->update(['lifecycle_state' => 'canceled', 'canceled_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            $this->outbox->emit(
                topic: 'ticketing.order.canceled',
                payload: [
                    'order_id' => (string) $order->getAttribute('_id'),
                    'event_id' => (string) ($order->event_id ?? ''),
                    'occurrence_id' => (string) ($order->occurrence_id ?? ''),
                    'occurred_at' => Carbon::now()->toISOString(),
                ],
                dedupeKey: sprintf('order.canceled:%s', (string) $order->getAttribute('_id')),
            );

            return $this->formatOrderResponse($order->fresh(), []);
        });

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function buildPurchaseOnlyReservationLines(array $lines, string $eventId, string $defaultOccurrenceId): array
    {
        $purchaseOnly = [];

        foreach ($lines as $line) {
            if ((string) ($line['product_type'] ?? 'ticket') !== 'passport') {
                continue;
            }

            /** @var TicketProduct|null $passport */
            $passport = TicketProduct::query()->find((string) ($line['ticket_product_id'] ?? ''));
            if (! $passport) {
                throw new TicketingDomainException('passport_product_not_found', 404);
            }

            $bundleItems = is_array($passport->bundle_items ?? null) ? $passport->bundle_items : [];
            if ($bundleItems === []) {
                throw new TicketingDomainException('passport_bundle_missing', 409);
            }

            $multiplier = (int) ($line['quantity'] ?? 1);
            foreach ($bundleItems as $bundleItem) {
                if (! is_array($bundleItem)) {
                    continue;
                }

                $componentProductId = (string) ($bundleItem['ticket_product_id'] ?? '');
                $componentOccurrenceId = (string) ($bundleItem['occurrence_id'] ?? $defaultOccurrenceId);
                $componentQuantity = (int) ($bundleItem['quantity'] ?? 1) * $multiplier;

                if ($componentProductId === '' || $componentQuantity <= 0) {
                    throw new TicketingDomainException('invalid_passport_bundle_item', 422);
                }

                $purchaseOnly[] = $this->inventory->hydrateSingleLine(
                    ticketProductId: $componentProductId,
                    quantity: $componentQuantity,
                    eventId: $eventId,
                    occurrenceId: $componentOccurrenceId,
                );
            }
        }

        return $purchaseOnly;
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @return array<string, mixed>
     */
    private function formatOrderResponse(TicketOrder $order, array $units): array
    {
        return [
            'status' => (string) ($order->status ?? 'unknown'),
            'order_id' => (string) $order->getAttribute('_id'),
            'checkout_mode' => (string) ($order->checkout_mode ?? 'free'),
            'confirmed_at' => optional($order->confirmed_at)->toISOString(),
            'checkout_snapshot_hash' => (string) ($order->checkout_snapshot_hash ?? ''),
            'financial_snapshot' => is_array($order->financial_snapshot ?? null) ? $order->financial_snapshot : [],
            'units' => $units,
        ];
    }
}
