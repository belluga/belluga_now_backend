<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Promotions;

use Belluga\Ticketing\Models\Tenants\TicketPromotion;
use Belluga\Ticketing\Models\Tenants\TicketPromotionRedemption;
use Belluga\Ticketing\Support\TicketingDomainException;

class TicketPromotionQuotaService
{
    /**
     * @param  array<string, mixed>  $promotionSnapshot
     */
    public function consumeForOrder(
        string $orderId,
        string $eventId,
        string $occurrenceId,
        string $principalId,
        string $principalType,
        array $promotionSnapshot,
    ): void {
        $applied = is_array($promotionSnapshot['applied'] ?? null) ? $promotionSnapshot['applied'] : [];
        foreach ($applied as $appliedPromotion) {
            if (! is_array($appliedPromotion)) {
                continue;
            }

            $promotionId = (string) ($appliedPromotion['promotion_id'] ?? '');
            if ($promotionId === '') {
                continue;
            }

            /** @var TicketPromotionRedemption|null $existing */
            $existing = TicketPromotionRedemption::query()
                ->where('order_id', $orderId)
                ->where('promotion_id', $promotionId)
                ->first();
            if ($existing) {
                continue;
            }

            /** @var TicketPromotion|null $promotion */
            $promotion = TicketPromotion::query()->find($promotionId);
            if (! $promotion || (string) ($promotion->status ?? '') !== 'active') {
                throw new TicketingDomainException('promotion_inactive', 409);
            }

            $globalLimit = is_numeric($promotion->global_uses_limit) ? (int) $promotion->global_uses_limit : null;
            $perPrincipalLimit = is_numeric($promotion->max_uses_per_principal) ? (int) $promotion->max_uses_per_principal : null;
            $redeemedTotal = (int) ($promotion->redeemed_total ?? 0);
            $version = (int) ($promotion->version ?? 1);

            if ($globalLimit !== null && $globalLimit >= 0 && $redeemedTotal >= $globalLimit) {
                throw new TicketingDomainException('promotion_quota_exhausted', 409);
            }

            if ($perPrincipalLimit !== null && $perPrincipalLimit >= 0) {
                $principalRedeemed = TicketPromotionRedemption::query()
                    ->where('promotion_id', $promotionId)
                    ->where('principal_id', $principalId)
                    ->count();

                if ($principalRedeemed >= $perPrincipalLimit) {
                    throw new TicketingDomainException('promotion_user_limit_exhausted', 409);
                }
            }

            $updated = TicketPromotion::query()
                ->where('_id', (string) $promotion->getAttribute('_id'))
                ->where('version', $version)
                ->where('redeemed_total', $redeemedTotal)
                ->update([
                    'redeemed_total' => $redeemedTotal + 1,
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new TicketingDomainException('promotion_quota_conflict', 409);
            }

            TicketPromotionRedemption::query()->create([
                'promotion_id' => $promotionId,
                'order_id' => $orderId,
                'principal_id' => $principalId,
                'principal_type' => $principalType,
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'delta_amount' => (int) (($appliedPromotion['fee_delta'] ?? 0) - ($appliedPromotion['discount_delta'] ?? 0)),
                'currency' => (string) ($appliedPromotion['value']['currency'] ?? 'BRL'),
                'metadata' => [
                    'code' => (string) ($appliedPromotion['code'] ?? ''),
                    'type' => (string) ($appliedPromotion['type'] ?? ''),
                ],
            ]);
        }
    }
}
