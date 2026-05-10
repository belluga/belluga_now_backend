<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Lifecycle;

use Belluga\Invites\Contracts\InvitePushDeliveryContract;
use Belluga\Invites\Contracts\InviteTelemetryEmitterContract;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use Throwable;

final class InviteLifecycleSideEffectService
{
    public function __construct(
        private readonly InvitePushDeliveryContract $pushDelivery,
        private readonly InviteTelemetryEmitterContract $telemetry,
        private readonly \Belluga\Invites\Application\Feed\PrincipalSocialMetricsService $metrics,
    ) {}

    public function handleDirectInviteCreated(string $inviteId, string $actorUserId): void
    {
        $edge = $this->edgeOrThrow($inviteId);

        try {
            $this->pushDelivery->sendDirectInvite($edge);
        } catch (Throwable) {
            // Invite persistence remains authoritative even when push delivery is unavailable.
        }

        $this->metrics->incrementInvitesSent($this->fromStoredPrincipal((array) $edge->inviter_principal), 1);

        $this->telemetry->emit(
            event: 'invite.created',
            userId: $actorUserId,
            properties: [
                'invite_id' => (string) $edge->getAttribute('_id'),
                'receiver_user_id' => (string) $edge->receiver_user_id,
                'receiver_account_profile_id' => (string) $edge->receiver_account_profile_id,
                'target_ref' => $this->targetRef($edge),
                'inviter_principal' => $this->fromStoredPrincipal((array) $edge->inviter_principal),
                'status' => (string) $edge->status,
                'source' => 'direct_invite',
            ],
            idempotencyKey: 'invite.created:'.(string) $edge->getAttribute('_id'),
            source: 'invite_api',
            context: [
                'actor' => ['type' => 'user', 'id' => $actorUserId],
                'target' => ['type' => 'user', 'id' => (string) $edge->receiver_user_id],
                'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
            ],
        );
    }

    /**
     * @param  array<int, string>  $supersededInviteIds
     */
    public function handleCreditedInviteAccepted(
        string $inviteId,
        string $actorUserId,
        array $supersededInviteIds = [],
        ?string $shareCode = null,
    ): void {
        $edge = $this->edgeOrThrow($inviteId);

        $this->metrics->incrementCreditedAcceptances($this->fromStoredPrincipal((array) $edge->inviter_principal));

        try {
            $this->pushDelivery->sendAcceptedInvite($edge);
        } catch (Throwable) {
            // Invite acceptance remains authoritative even when push delivery is unavailable.
        }

        $this->telemetry->emit(
            event: 'invite.accepted',
            userId: $actorUserId,
            properties: $this->buildAcceptedTelemetryProperties(
                edge: $edge,
                status: 'accepted',
                creditedAcceptance: true,
                supersededIds: $supersededInviteIds,
                shareCode: $shareCode,
            ),
            idempotencyKey: 'invite.accepted:'.(string) $edge->getAttribute('_id').':accepted',
            source: 'invite_api',
            context: [
                'actor' => ['type' => 'user', 'id' => $actorUserId],
                'target' => ['type' => 'user', 'id' => $actorUserId],
                'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
            ],
        );
    }

    private function edgeOrThrow(string $inviteId): InviteEdge
    {
        /** @var InviteEdge|null $edge */
        $edge = InviteEdge::query()->find($inviteId);
        if (! $edge instanceof InviteEdge) {
            throw new InviteDomainException('invite_not_found', 404);
        }

        return $edge;
    }

    /**
     * @param  array<string, mixed>  $principal
     * @return array{kind:string,id:string}
     */
    private function fromStoredPrincipal(array $principal): array
    {
        return [
            'kind' => trim((string) ($principal['kind'] ?? '')),
            'id' => trim((string) ($principal['principal_id'] ?? $principal['id'] ?? '')),
        ];
    }

    /**
     * @param  array<int, string>  $supersededIds
     * @return array<string, mixed>
     */
    private function buildAcceptedTelemetryProperties(
        InviteEdge $edge,
        string $status,
        bool $creditedAcceptance,
        array $supersededIds = [],
        ?string $shareCode = null,
    ): array {
        $properties = [
            'invite_id' => (string) $edge->getAttribute('_id'),
            'status' => $status,
            'credited_acceptance' => $creditedAcceptance,
            'event_id' => (string) $edge->event_id,
            'occurrence_id' => (string) $edge->occurrence_id,
            'source' => 'invite_flow',
            'invite_source' => (string) ($edge->source ?? 'direct_invite'),
            'target_ref' => $this->targetRef($edge),
            'receiver_user_id' => (string) $edge->receiver_user_id,
            'receiver_account_profile_id' => (string) $edge->receiver_account_profile_id,
            'inviter_principal' => $this->fromStoredPrincipal((array) $edge->inviter_principal),
        ];

        if ($status === 'accepted') {
            $properties['superseded_count'] = count($supersededIds);
            $properties['superseded_invite_ids'] = array_values($supersededIds);
        }

        $normalizedShareCode = $shareCode === null ? '' : strtoupper(trim($shareCode));
        if ($normalizedShareCode !== '') {
            $properties['code'] = $normalizedShareCode;
        }

        if ($edge->accepted_at instanceof Carbon) {
            $properties['accepted_at'] = $edge->accepted_at->toISOString();
        }

        return $properties;
    }

    /**
     * @return array{event_id:string,occurrence_id:string}
     */
    private function targetRef(InviteEdge $edge): array
    {
        return [
            'event_id' => (string) $edge->event_id,
            'occurrence_id' => (string) $edge->occurrence_id,
        ];
    }
}
