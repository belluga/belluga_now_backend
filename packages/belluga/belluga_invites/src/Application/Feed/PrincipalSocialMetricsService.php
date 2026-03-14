<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Feed;

use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;

class PrincipalSocialMetricsService
{
    /**
     * @param  array{kind:string,id:string}  $principal
     */
    public function incrementInvitesSent(array $principal, int $amount = 1): void
    {
        if ($amount <= 0 || trim((string) ($principal['id'] ?? '')) === '') {
            return;
        }

        $metric = $this->firstOrNew($principal['kind'], $principal['id']);
        $metric->invites_sent = ((int) $metric->invites_sent) + $amount;
        $metric->save();
    }

    /**
     * @param  array{kind:string,id:string}  $principal
     */
    public function incrementCreditedAcceptances(array $principal, int $amount = 1): void
    {
        if ($amount <= 0 || trim((string) ($principal['id'] ?? '')) === '') {
            return;
        }

        $metric = $this->firstOrNew($principal['kind'], $principal['id']);
        $metric->credited_invite_acceptances = ((int) $metric->credited_invite_acceptances) + $amount;
        $metric->save();
    }

    public function syncPendingInvitesReceived(string $userId, int $count): void
    {
        $metric = $this->firstOrNew('user', $userId);
        $metric->pending_invites_received = max(0, $count);
        $metric->save();
    }

    private function firstOrNew(string $principalKind, string $principalId): PrincipalSocialMetric
    {
        /** @var PrincipalSocialMetric|null $metric */
        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', $principalKind)
            ->where('principal_id', $principalId)
            ->first();

        if ($metric) {
            return $metric;
        }

        return new PrincipalSocialMetric([
            'principal_kind' => $principalKind,
            'principal_id' => $principalId,
            'invites_sent' => 0,
            'credited_invite_acceptances' => 0,
            'pending_invites_received' => 0,
        ]);
    }
}
