<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Promotions;

use Belluga\Ticketing\Models\Tenants\TicketPromotion;

class TicketPromotionResolverService
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, string>  $promotionCodes
     * @return array{
     *   lines: array<int, array<string, mixed>>,
     *   snapshot: array<string, mixed>
     * }
     */
    public function resolve(
        string $eventId,
        string $occurrenceId,
        array $lines,
        array $promotionCodes,
    ): array {
        $normalizedCodes = $this->normalizeCodes($promotionCodes);
        if ($normalizedCodes === []) {
            return [
                'lines' => $this->ensurePricingFields($lines),
                'snapshot' => $this->emptySnapshot(),
            ];
        }

        /** @var array<int, TicketPromotion> $promotions */
        $promotions = TicketPromotion::query()
            ->where('event_id', $eventId)
            ->where('status', 'active')
            ->whereIn('code', $normalizedCodes)
            ->get()
            ->all();

        $resolvedLines = [];
        $appliedCatalog = [];
        $rejectedCatalog = [];
        $totals = [
            'discount_amount' => 0,
            'fee_amount' => 0,
        ];

        foreach ($this->ensurePricingFields($lines) as $line) {
            $lineResolution = $this->resolveLinePromotions($line, $promotions, $eventId, $occurrenceId);

            $line['applied_promotions'] = $lineResolution['applied'];
            $line['rejected_promotions'] = $lineResolution['rejected'];
            $line['discount_amount'] = (int) $lineResolution['discount_amount'];
            $line['fee_amount'] = (int) $lineResolution['fee_amount'];
            $line['final_line_amount'] = (int) $lineResolution['final_line_amount'];
            $line['unit_price'] = $this->safeUnitPrice((int) $line['quantity'], (int) $line['final_line_amount']);

            $totals['discount_amount'] += (int) $line['discount_amount'];
            $totals['fee_amount'] += (int) $line['fee_amount'];

            foreach ($line['applied_promotions'] as $appliedPromotion) {
                $promotionId = (string) ($appliedPromotion['promotion_id'] ?? '');
                if ($promotionId === '') {
                    continue;
                }
                $appliedCatalog[$promotionId] = $appliedPromotion;
            }

            foreach ($line['rejected_promotions'] as $rejectedPromotion) {
                $promotionId = (string) ($rejectedPromotion['promotion_id'] ?? '');
                if ($promotionId === '') {
                    continue;
                }
                $rejectedCatalog[$promotionId.':'.($rejectedPromotion['reason_code'] ?? 'unknown')] = $rejectedPromotion;
            }

            $resolvedLines[] = $line;
        }

        return [
            'lines' => $resolvedLines,
            'snapshot' => [
                'version' => 1,
                'requested_codes' => $normalizedCodes,
                'applied' => array_values($appliedCatalog),
                'rejected' => array_values($rejectedCatalog),
                'totals' => $totals,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $promotionCodes
     * @return array<int, string>
     */
    private function normalizeCodes(array $promotionCodes): array
    {
        $normalized = [];
        foreach ($promotionCodes as $code) {
            $candidate = strtoupper(trim((string) $code));
            if ($candidate === '') {
                continue;
            }
            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function ensurePricingFields(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $baseUnitPrice = max(0, (int) ($line['base_unit_price'] ?? $line['unit_price'] ?? 0));
            $baseLineAmount = $baseUnitPrice * $quantity;

            $line['quantity'] = $quantity;
            $line['base_unit_price'] = $baseUnitPrice;
            $line['base_line_amount'] = $baseLineAmount;
            $line['discount_amount'] = max(0, (int) ($line['discount_amount'] ?? 0));
            $line['fee_amount'] = max(0, (int) ($line['fee_amount'] ?? 0));
            $line['final_line_amount'] = max(0, (int) ($line['final_line_amount'] ?? $baseLineAmount));
            $line['applied_promotions'] = is_array($line['applied_promotions'] ?? null) ? $line['applied_promotions'] : [];
            $line['rejected_promotions'] = is_array($line['rejected_promotions'] ?? null) ? $line['rejected_promotions'] : [];
            $line['unit_price'] = $this->safeUnitPrice($quantity, (int) $line['final_line_amount']);

            $normalized[] = $line;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, TicketPromotion>  $promotions
     * @return array{
     *   applied: array<int, array<string, mixed>>,
     *   rejected: array<int, array<string, mixed>>,
     *   discount_amount: int,
     *   fee_amount: int,
     *   final_line_amount: int
     * }
     */
    private function resolveLinePromotions(array $line, array $promotions, string $eventId, string $occurrenceId): array
    {
        $lineProductId = (string) ($line['ticket_product_id'] ?? '');
        $lineOccurrenceId = (string) ($line['occurrence_id'] ?? $occurrenceId);
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $runningAmount = max(0, (int) ($line['base_line_amount'] ?? 0));
        $discountAmount = 0;
        $feeAmount = 0;

        $candidatesByType = [];
        foreach ($promotions as $promotion) {
            if (! $this->promotionAppliesToLine($promotion, $eventId, $lineOccurrenceId, $lineProductId)) {
                continue;
            }

            $type = (string) ($promotion->type ?? '');
            if ($type === '') {
                continue;
            }

            $existing = $candidatesByType[$type] ?? null;
            if ($existing === null) {
                $candidatesByType[$type] = $promotion;

                continue;
            }

            if ($this->promotionPriorityTuple($promotion) < $this->promotionPriorityTuple($existing)) {
                $candidatesByType[$type] = $promotion;
            }
        }

        /** @var array<int, TicketPromotion> $selected */
        $selected = array_values($candidatesByType);
        usort($selected, function (TicketPromotion $left, TicketPromotion $right): int {
            $leftTuple = $this->promotionPriorityTuple($left);
            $rightTuple = $this->promotionPriorityTuple($right);

            return $leftTuple <=> $rightTuple;
        });

        $applied = [];
        $rejected = [];

        $exclusive = array_values(array_filter($selected, static fn (TicketPromotion $promotion): bool => (string) ($promotion->mode ?? 'stackable') === 'exclusive'));
        if ($exclusive !== []) {
            $winner = $exclusive[0];
            $selected = [$winner];

            foreach ($exclusive as $loser) {
                if ((string) $loser->getAttribute('_id') === (string) $winner->getAttribute('_id')) {
                    continue;
                }

                $rejected[] = $this->rejectedPromotionPayload($loser, 'exclusive_conflict');
            }
        }

        foreach ($selected as $promotion) {
            $effect = $this->applyPromotion(
                promotion: $promotion,
                currentAmount: $runningAmount,
                quantity: $quantity,
            );

            $runningAmount = $effect['next_amount'];
            $discountAmount += $effect['discount_delta'];
            $feeAmount += $effect['fee_delta'];

            $applied[] = array_merge(
                $this->promotionSnapshotPayload($promotion),
                [
                    'discount_delta' => $effect['discount_delta'],
                    'fee_delta' => $effect['fee_delta'],
                    'line_amount_after' => $runningAmount,
                ],
            );
        }

        return [
            'applied' => $applied,
            'rejected' => $rejected,
            'discount_amount' => $discountAmount,
            'fee_amount' => $feeAmount,
            'final_line_amount' => max(0, $runningAmount),
        ];
    }

    private function promotionAppliesToLine(
        TicketPromotion $promotion,
        string $eventId,
        string $occurrenceId,
        string $ticketProductId,
    ): bool {
        if ((string) ($promotion->event_id ?? '') !== $eventId) {
            return false;
        }

        $scopeType = (string) ($promotion->scope_type ?? 'event');

        return match ($scopeType) {
            'event' => true,
            'occurrence' => (string) ($promotion->occurrence_id ?? '') === $occurrenceId,
            'ticket_product' => (string) ($promotion->ticket_product_id ?? '') === $ticketProductId,
            default => false,
        };
    }

    /**
     * @return array{0:int,1:int,2:string}
     */
    private function promotionPriorityTuple(TicketPromotion $promotion): array
    {
        return [
            4 - $this->scopeSpecificity((string) ($promotion->scope_type ?? 'event')),
            (int) ($promotion->priority ?? 100),
            (string) ($promotion->code ?? ''),
        ];
    }

    private function scopeSpecificity(string $scopeType): int
    {
        return match ($scopeType) {
            'ticket_product' => 3,
            'occurrence' => 2,
            default => 1,
        };
    }

    /**
     * @return array{next_amount:int,discount_delta:int,fee_delta:int}
     */
    private function applyPromotion(TicketPromotion $promotion, int $currentAmount, int $quantity): array
    {
        $type = (string) ($promotion->type ?? '');
        $value = is_array($promotion->value ?? null) ? $promotion->value : [];

        $discountDelta = 0;
        $feeDelta = 0;
        $nextAmount = $currentAmount;

        if ($type === 'percent_discount') {
            $percent = max(0.0, min(100.0, (float) ($value['percent'] ?? 0)));
            $discountDelta = (int) floor(($currentAmount * $percent) / 100.0);
            $nextAmount = max(0, $currentAmount - $discountDelta);
        } elseif ($type === 'fixed_discount') {
            $fixedAmount = max(0, (int) ($value['amount'] ?? 0));
            $discountDelta = min($currentAmount, $fixedAmount);
            $nextAmount = max(0, $currentAmount - $discountDelta);
        } elseif ($type === 'service_charge') {
            $serviceAmount = max(0, (int) ($value['amount'] ?? 0));
            $feeDelta = $serviceAmount;
            $nextAmount = $currentAmount + $serviceAmount;
        } elseif ($type === 'bundle_price_override') {
            $overrideUnitAmount = max(0, (int) ($value['amount'] ?? 0));
            $overrideTotal = $overrideUnitAmount * max(1, $quantity);
            if ($overrideTotal <= $currentAmount) {
                $discountDelta = $currentAmount - $overrideTotal;
            } else {
                $feeDelta = $overrideTotal - $currentAmount;
            }
            $nextAmount = $overrideTotal;
        }

        return [
            'next_amount' => $nextAmount,
            'discount_delta' => $discountDelta,
            'fee_delta' => $feeDelta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promotionSnapshotPayload(TicketPromotion $promotion): array
    {
        $value = is_array($promotion->value ?? null) ? $promotion->value : [];

        return [
            'promotion_id' => (string) $promotion->getAttribute('_id'),
            'code' => (string) ($promotion->code ?? ''),
            'scope_type' => (string) ($promotion->scope_type ?? 'event'),
            'type' => (string) ($promotion->type ?? ''),
            'mode' => (string) ($promotion->mode ?? 'stackable'),
            'priority' => (int) ($promotion->priority ?? 100),
            'value' => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectedPromotionPayload(TicketPromotion $promotion, string $reasonCode): array
    {
        return [
            'promotion_id' => (string) $promotion->getAttribute('_id'),
            'code' => (string) ($promotion->code ?? ''),
            'reason_code' => $reasonCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySnapshot(): array
    {
        return [
            'version' => 1,
            'requested_codes' => [],
            'applied' => [],
            'rejected' => [],
            'totals' => [
                'discount_amount' => 0,
                'fee_amount' => 0,
            ],
        ];
    }

    private function safeUnitPrice(int $quantity, int $lineAmount): int
    {
        if ($quantity <= 0) {
            return max(0, $lineAmount);
        }

        return (int) floor($lineAmount / $quantity);
    }
}
