<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Mutations;

use Belluga\Invites\Application\Feed\InviteProjectionService;
use Belluga\Invites\Application\Feed\PrincipalSocialMetricsService;
use Belluga\Invites\Application\Quotas\InviteQuotaCounterService;
use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Application\Targets\InviteTargetResolverService;
use Belluga\Invites\Application\Transactions\InviteTransactionRunner;
use Belluga\Invites\Contracts\InviteAttendanceGatewayContract;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Contracts\InviteTelemetryEmitterContract;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;

class InviteMutationService
{
    private const SUPERSESSION_REASON_OTHER_INVITE_CREDITED = 'other_invite_credited';

    private const SUPERSESSION_REASON_DIRECT_CONFIRMATION = 'direct_confirmation';

    public function __construct(
        private readonly InviteTransactionRunner $transactions,
        private readonly InviteAttendanceGatewayContract $attendanceGateway,
        private readonly InviteIdentityGatewayContract $identityGateway,
        private readonly InviteTelemetryEmitterContract $telemetry,
        private readonly InviteTargetResolverService $targetResolver,
        private readonly InviteRuntimeSettingsService $runtimeSettings,
        private readonly InviteProjectionService $projectionService,
        private readonly PrincipalSocialMetricsService $metricsService,
        private readonly InviteQuotaCounterService $quotaCounters,
        private readonly InviteCommandIdempotencyService $idempotencyService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(mixed $user, array $payload): array
    {
        $senderUserId = $this->userId($user);
        if ($senderUserId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        $inviter = $this->identityGateway->resolveInviterPrincipal(
            $user,
            isset($payload['account_profile_id']) ? (string) $payload['account_profile_id'] : null
        );
        $target = $this->targetResolver->resolve((array) ($payload['target_ref'] ?? []));
        $limits = $this->runtimeSettings->limits();
        $now = Carbon::now();

        $recipientPayloads = $this->normalizeRecipients($payload['recipients'] ?? []);
        $result = [
            'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
            'target_ref' => $target['target_ref'],
            'created' => [],
            'already_invited' => [],
            'blocked' => [],
        ];

        $createdCount = 0;

        foreach ($recipientPayloads as $recipientPayload) {
            $resolvedRecipient = $this->resolveRecipient($senderUserId, $recipientPayload);
            if ($resolvedRecipient === null) {
                $result['blocked'][] = [
                    'receiver_user_id' => $recipientPayload['receiver_user_id'] ?? null,
                    'receiver_account_profile_id' => $recipientPayload['receiver_account_profile_id'] ?? null,
                    'reason' => 'suppressed',
                ];

                continue;
            }

            if ($resolvedRecipient['user_id'] === $senderUserId) {
                $result['blocked'][] = [
                    'receiver_user_id' => $resolvedRecipient['user_id'],
                    'receiver_account_profile_id' => $resolvedRecipient['receiver_account_profile_id'] ?? null,
                    'reason' => 'suppressed',
                ];

                continue;
            }

            $receiverUserId = $resolvedRecipient['user_id'];
            $receiverAccountProfileId = $resolvedRecipient['receiver_account_profile_id'] ?? null;

            $existing = $this->existingInvite(
                receiverUserId: $receiverUserId,
                receiverAccountProfileId: is_string($receiverAccountProfileId) ? $receiverAccountProfileId : null,
                targetRef: $target['target_ref'],
                inviterPrincipal: $inviter['principal'],
            );
            if ($existing !== null) {
                $result['already_invited'][] = [
                    'receiver_user_id' => $receiverUserId,
                    'receiver_account_profile_id' => $receiverAccountProfileId,
                ];

                continue;
            }

            /** @var InviteEdge $edge */
            $edge = $this->transactions->run(function () use ($resolvedRecipient, $recipientPayload, $inviter, $target, $payload, $limits, $now): InviteEdge {
                $this->reserveSenderQuotasOrThrow(
                    issuedByUserId: (string) $inviter['issued_by_user_id'],
                    limits: $limits,
                    now: $now,
                );

                $edge = InviteEdge::query()->create([
                    'event_id' => $target['target_ref']['event_id'],
                    'occurrence_id' => $target['target_ref']['occurrence_id'],
                    'receiver_user_id' => $resolvedRecipient['user_id'],
                    'receiver_account_profile_id' => $resolvedRecipient['receiver_account_profile_id'] ?? null,
                    'receiver_contact_hash' => $recipientPayload['contact_hash'] ?? null,
                    'inviter_principal' => $this->toStoredPrincipal($inviter['principal']),
                    'account_profile_id' => $inviter['account_profile_id'],
                    'issued_by_user_id' => $inviter['issued_by_user_id'],
                    'inviter_display_name' => $inviter['display_name'],
                    'inviter_avatar_url' => $inviter['avatar_url'],
                    'status' => 'pending',
                    'credited_acceptance' => false,
                    'source' => 'direct_invite',
                    'message' => isset($payload['message']) ? trim((string) $payload['message']) : null,
                    'event_name' => $target['event_snapshot']['event_name'],
                    'event_slug' => $target['event_snapshot']['event_slug'],
                    'event_date' => $target['event_snapshot']['event_date'],
                    'event_image_url' => $target['event_snapshot']['event_image_url'],
                    'location_label' => $target['event_snapshot']['location'],
                    'host_name' => $target['event_snapshot']['host_name'],
                    'tags' => $target['event_snapshot']['tags'],
                    'attendance_policy' => $target['event_snapshot']['attendance_policy'],
                    'expires_at' => $target['event_snapshot']['expires_at'],
                    'accepted_at' => null,
                    'declined_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $edge;
            });

            $this->projectionService->rebuildReceiverTargetProjection($receiverUserId, $target['target_ref']);

            $result['created'][] = [
                'invite_id' => (string) $edge->getAttribute('_id'),
                'receiver_user_id' => $receiverUserId,
                'receiver_account_profile_id' => $receiverAccountProfileId,
            ];

            $this->telemetry->emit(
                event: 'invite.created',
                userId: $senderUserId,
                properties: [
                    'invite_id' => (string) $edge->getAttribute('_id'),
                    'receiver_user_id' => $receiverUserId,
                    'receiver_account_profile_id' => $receiverAccountProfileId,
                    'target_ref' => $target['target_ref'],
                    'inviter_principal' => $inviter['principal'],
                    'source' => 'direct_invite',
                ],
                idempotencyKey: 'invite.created:'.(string) $edge->getAttribute('_id'),
                source: 'invite_api',
                context: [
                    'actor' => ['type' => 'user', 'id' => $senderUserId],
                    'target' => ['type' => 'user', 'id' => $receiverUserId],
                    'object' => ['type' => 'event', 'id' => (string) $target['target_ref']['event_id']],
                ],
            );
            $createdCount++;
        }

        if ($createdCount > 0) {
            $this->metricsService->incrementInvitesSent($inviter['principal'], $createdCount);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function accept(mixed $user, string $inviteId, ?string $idempotencyKey = null): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        return $this->acceptForUserId($userId, $inviteId, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptForUserId(string $userId, string $inviteId, ?string $idempotencyKey = null): array
    {
        return $this->idempotencyService->runWithReplay(
            command: 'invite.accept',
            actorUserId: $userId,
            idempotencyKey: $idempotencyKey,
            fingerprintPayload: ['invite_id' => $inviteId],
            callback: fn (): array => $this->acceptForUserIdWithoutReplay($userId, $inviteId),
        );
    }

    /**
     * @return array<int, string>
     */
    public function supersedePendingInvitesForDirectConfirmation(
        string $userId,
        string $eventId,
        ?string $occurrenceId = null,
    ): array {
        $targetRef = [
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
        ];

        $supersededIds = $this->supersedePendingInvites(
            receiverUserId: $userId,
            receiverAccountProfileId: $this->recipientAccountProfileIdForUserId($userId),
            targetRef: $targetRef,
            reason: self::SUPERSESSION_REASON_DIRECT_CONFIRMATION,
        );

        if ($supersededIds !== []) {
            $this->projectionService->rebuildReceiverTargetProjection($userId, $targetRef);
        }

        return $supersededIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptForUserIdWithoutReplay(string $userId, string $inviteId): array
    {
        /** @var InviteEdge|null $edge */
        $edge = InviteEdge::query()->find($inviteId);
        $actingReceiverAccountProfileId = $this->recipientAccountProfileIdForUserId($userId);
        if (! $edge || ! $this->edgeBelongsToReceiver($edge, $userId, $actingReceiverAccountProfileId)) {
            throw new InviteDomainException('invite_not_found', 404);
        }
        $receiverAccountProfileId = $this->receiverAccountProfileIdForEdge($edge, $userId);

        if ($this->isExpired($edge)) {
            $edge->status = 'expired';
            $edge->save();
            $this->projectionService->rebuildReceiverTargetProjection($userId, $this->targetRef($edge));

            return $this->acceptResponse($edge, 'expired', [], false);
        }

        $existingWinnerQuery = InviteEdge::query()
            ->where('event_id', (string) $edge->event_id)
            ->where('occurrence_id', $edge->occurrence_id ? (string) $edge->occurrence_id : null)
            ->where('credited_acceptance', true);
        $this->applyReceiverScope($existingWinnerQuery, $userId, $receiverAccountProfileId);

        /** @var InviteEdge|null $existingWinner */
        $existingWinner = $existingWinnerQuery->first();

        if ($existingWinner !== null && (string) $existingWinner->getAttribute('_id') !== (string) $edge->getAttribute('_id')) {
            if (in_array((string) $edge->status, ['pending', 'viewed'], true)) {
                $edge->fill([
                    'status' => 'superseded',
                    'supersession_reason' => self::SUPERSESSION_REASON_OTHER_INVITE_CREDITED,
                    'credited_acceptance' => false,
                ]);
                $edge->save();
                $this->projectionService->rebuildReceiverTargetProjection($userId, $this->targetRef($edge));
            }

            $this->telemetry->emit(
                event: 'invite.accepted',
                userId: $userId,
                properties: [
                    'invite_id' => (string) $existingWinner->getAttribute('_id'),
                    'status' => 'already_accepted',
                    'credited_acceptance' => false,
                    'target_ref' => $this->targetRef($existingWinner),
                ],
                idempotencyKey: 'invite.accepted:'.(string) $existingWinner->getAttribute('_id').':already_accepted',
                source: 'invite_api',
                context: [
                    'actor' => ['type' => 'user', 'id' => $userId],
                    'target' => ['type' => 'user', 'id' => $userId],
                    'object' => ['type' => 'event', 'id' => (string) $existingWinner->event_id],
                ],
            );

            return $this->acceptResponse($existingWinner, 'already_accepted', [], false);
        }

        if ((string) $edge->status === 'accepted' && (bool) $edge->credited_acceptance) {
            $this->telemetry->emit(
                event: 'invite.accepted',
                userId: $userId,
                properties: [
                    'invite_id' => (string) $edge->getAttribute('_id'),
                    'status' => 'already_accepted',
                    'credited_acceptance' => true,
                    'target_ref' => $this->targetRef($edge),
                ],
                idempotencyKey: 'invite.accepted:'.(string) $edge->getAttribute('_id').':already_accepted',
                source: 'invite_api',
                context: [
                    'actor' => ['type' => 'user', 'id' => $userId],
                    'target' => ['type' => 'user', 'id' => $userId],
                    'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
                ],
            );

            return $this->acceptResponse($edge, 'already_accepted', [], true);
        }

        if (
            (string) $edge->status === 'superseded' &&
            (string) ($edge->supersession_reason ?? '') === self::SUPERSESSION_REASON_DIRECT_CONFIRMATION
        ) {
            $this->telemetry->emit(
                event: 'invite.accepted',
                userId: $userId,
                properties: [
                    'invite_id' => (string) $edge->getAttribute('_id'),
                    'status' => 'already_accepted',
                    'credited_acceptance' => false,
                    'target_ref' => $this->targetRef($edge),
                ],
                idempotencyKey: 'invite.accepted:'.(string) $edge->getAttribute('_id').':already_confirmed',
                source: 'invite_api',
                context: [
                    'actor' => ['type' => 'user', 'id' => $userId],
                    'target' => ['type' => 'user', 'id' => $userId],
                    'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
                ],
            );

            return $this->acceptResponse($edge, 'already_accepted', [], false);
        }

        if ($this->attendanceGateway->hasActiveAttendanceConfirmation(
            $userId,
            (string) $edge->event_id,
            $edge->occurrence_id ? (string) $edge->occurrence_id : null,
        )) {
            if (in_array((string) $edge->status, ['pending', 'viewed'], true)) {
                $edge->fill([
                    'status' => 'superseded',
                    'supersession_reason' => self::SUPERSESSION_REASON_DIRECT_CONFIRMATION,
                    'credited_acceptance' => false,
                ]);
                $edge->save();
                $this->projectionService->rebuildReceiverTargetProjection($userId, $this->targetRef($edge));
            }

            $this->telemetry->emit(
                event: 'invite.accepted',
                userId: $userId,
                properties: [
                    'invite_id' => (string) $edge->getAttribute('_id'),
                    'status' => 'already_accepted',
                    'credited_acceptance' => false,
                    'target_ref' => $this->targetRef($edge),
                ],
                idempotencyKey: 'invite.accepted:'.(string) $edge->getAttribute('_id').':already_confirmed',
                source: 'invite_api',
                context: [
                    'actor' => ['type' => 'user', 'id' => $userId],
                    'target' => ['type' => 'user', 'id' => $userId],
                    'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
                ],
            );

            return $this->acceptResponse($edge, 'already_accepted', [], false);
        }

        $result = $this->transactions->run(function () use ($edge, $userId, $receiverAccountProfileId): array {
            $acceptedAt = Carbon::now();

            $edge->fill([
                'status' => 'accepted',
                'supersession_reason' => null,
                'credited_acceptance' => true,
                'accepted_at' => $acceptedAt,
            ]);
            $edge->save();

            $supersededIds = $this->supersedePendingInvites(
                receiverUserId: $userId,
                receiverAccountProfileId: $receiverAccountProfileId,
                targetRef: $this->targetRef($edge),
                reason: self::SUPERSESSION_REASON_OTHER_INVITE_CREDITED,
                exceptInviteId: (string) $edge->getAttribute('_id'),
            );

            return [$edge, $supersededIds];
        });

        /** @var InviteEdge $acceptedEdge */
        [$acceptedEdge, $supersededIds] = $result;
        $this->projectionService->rebuildReceiverTargetProjection($userId, $this->targetRef($acceptedEdge));
        $this->metricsService->incrementCreditedAcceptances($this->fromStoredPrincipal((array) $acceptedEdge->inviter_principal));

        $this->telemetry->emit(
            event: 'invite.accepted',
            userId: $userId,
            properties: [
                'invite_id' => (string) $acceptedEdge->getAttribute('_id'),
                'status' => 'accepted',
                'credited_acceptance' => true,
                'superseded_count' => count($supersededIds),
                'superseded_invite_ids' => array_values($supersededIds),
                'target_ref' => $this->targetRef($acceptedEdge),
            ],
            idempotencyKey: 'invite.accepted:'.(string) $acceptedEdge->getAttribute('_id').':accepted',
            source: 'invite_api',
            context: [
                'actor' => ['type' => 'user', 'id' => $userId],
                'target' => ['type' => 'user', 'id' => $userId],
                'object' => ['type' => 'event', 'id' => (string) $acceptedEdge->event_id],
            ],
        );

        return $this->acceptResponse($acceptedEdge, 'accepted', $supersededIds, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function decline(mixed $user, string $inviteId, ?string $idempotencyKey = null): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        return $this->idempotencyService->runWithReplay(
            command: 'invite.decline',
            actorUserId: $userId,
            idempotencyKey: $idempotencyKey,
            fingerprintPayload: ['invite_id' => $inviteId],
            callback: fn (): array => $this->declineForUserIdWithoutReplay($userId, $inviteId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function declineForUserIdWithoutReplay(string $userId, string $inviteId): array
    {
        /** @var InviteEdge|null $edge */
        $edge = InviteEdge::query()->find($inviteId);
        $actingReceiverAccountProfileId = $this->recipientAccountProfileIdForUserId($userId);
        if (! $edge || ! $this->edgeBelongsToReceiver($edge, $userId, $actingReceiverAccountProfileId)) {
            throw new InviteDomainException('invite_not_found', 404);
        }

        if ($this->isExpired($edge)) {
            $edge->status = 'expired';
            $edge->save();
            $this->projectionService->rebuildReceiverTargetProjection($userId, $this->targetRef($edge));

            return $this->declineResponse($edge, 'expired', false);
        }

        if ((string) $edge->status === 'declined') {
            $this->telemetry->emit(
                event: 'invite.declined',
                userId: $userId,
                properties: [
                    'invite_id' => (string) $edge->getAttribute('_id'),
                    'status' => 'already_declined',
                    'target_ref' => $this->targetRef($edge),
                ],
                idempotencyKey: 'invite.declined:'.(string) $edge->getAttribute('_id').':already_declined',
                source: 'invite_api',
                context: [
                    'actor' => ['type' => 'user', 'id' => $userId],
                    'target' => ['type' => 'user', 'id' => $userId],
                    'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
                ],
            );

            return $this->declineResponse($edge, 'already_declined', $this->groupHasOtherPending($edge));
        }

        if (! in_array((string) $edge->status, ['pending', 'viewed'], true)) {
            return $this->declineResponse($edge, 'expired', false);
        }

        $edge->fill([
            'status' => 'declined',
            'declined_at' => Carbon::now(),
        ]);
        $edge->save();

        $this->projectionService->rebuildReceiverTargetProjection($userId, $this->targetRef($edge));

        $this->telemetry->emit(
            event: 'invite.declined',
            userId: $userId,
            properties: [
                'invite_id' => (string) $edge->getAttribute('_id'),
                'status' => 'declined',
                'target_ref' => $this->targetRef($edge),
            ],
            idempotencyKey: 'invite.declined:'.(string) $edge->getAttribute('_id').':declined',
            source: 'invite_api',
            context: [
                'actor' => ['type' => 'user', 'id' => $userId],
                'target' => ['type' => 'user', 'id' => $userId],
                'object' => ['type' => 'event', 'id' => (string) $edge->event_id],
            ],
        );

        return $this->declineResponse($edge, 'declined', $this->groupHasOtherPending($edge));
    }

    /**
     * @param  array<string,int>  $limits
     */
    private function reserveSenderQuotasOrThrow(
        string $issuedByUserId,
        array $limits,
        Carbon $now,
    ): void {
        $dailyWindowKey = $this->dailyWindowKey($now);
        $dailyActorLimit = (int) ($limits['max_invites_per_day_per_user_actor'] ?? 100);
        $dailyActorQuota = $this->quotaCounters->reserve(
            scope: 'user_actor_daily',
            scopeId: $issuedByUserId,
            windowKey: $dailyWindowKey,
            limit: $dailyActorLimit,
            now: $now,
        );

        if (! $dailyActorQuota['allowed']) {
            throw new InviteDomainException(
                'rate_limited',
                429,
                'Daily invite limit reached.',
                $this->buildLimitPayload(
                    limitKey: 'max_invites_per_day_per_user_actor',
                    scope: 'user_actor',
                    maxAllowed: $dailyActorLimit,
                    currentCount: $dailyActorQuota['current_count'],
                    window: 'day',
                    resetAt: $this->runtimeSettings->resetAtForWindow('day', $now),
                ),
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipients
     * @return array<int, array{receiver_user_id:?string,receiver_account_profile_id:?string,contact_hash:?string}>
     */
    private function normalizeRecipients(array $recipients): array
    {
        $seen = [];
        $normalized = [];

        foreach ($recipients as $recipient) {
            $receiverUserId = isset($recipient['receiver_user_id']) && trim((string) $recipient['receiver_user_id']) !== ''
                ? trim((string) $recipient['receiver_user_id'])
                : null;
            $receiverAccountProfileId = isset($recipient['receiver_account_profile_id']) && trim((string) $recipient['receiver_account_profile_id']) !== ''
                ? trim((string) $recipient['receiver_account_profile_id'])
                : null;
            $contactHash = isset($recipient['contact_hash']) && trim((string) $recipient['contact_hash']) !== ''
                ? trim((string) $recipient['contact_hash'])
                : null;

            if ($receiverUserId === null && $receiverAccountProfileId === null && $contactHash === null) {
                continue;
            }

            $signature = ($receiverAccountProfileId ?? $receiverUserId ?? 'contact').'::'.($contactHash ?? '');
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $normalized[] = [
                'receiver_user_id' => $receiverUserId,
                'receiver_account_profile_id' => $receiverAccountProfileId,
                'contact_hash' => $contactHash,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{receiver_user_id:?string,receiver_account_profile_id:?string,contact_hash:?string}  $recipient
     * @return array{user_id:string,receiver_account_profile_id?:?string,display_name:?string,avatar_url:?string}|null
     */
    private function resolveRecipient(string $senderUserId, array $recipient): ?array
    {
        if ($recipient['receiver_account_profile_id'] !== null) {
            return $this->identityGateway->resolveAccountProfileRecipient($recipient['receiver_account_profile_id']);
        }

        if ($recipient['receiver_user_id'] !== null) {
            return $this->identityGateway->resolveUserRecipient($recipient['receiver_user_id']);
        }

        /** @var ContactHashDirectory|null $directory */
        $directory = ContactHashDirectory::query()
            ->where('importing_user_id', $senderUserId)
            ->where('contact_hash', $recipient['contact_hash'])
            ->first();

        if (! $directory || ! is_string($directory->matched_user_id) || trim($directory->matched_user_id) === '') {
            return null;
        }

        return $this->identityGateway->resolveUserRecipient((string) $directory->matched_user_id);
    }

    /**
     * @param  array{event_id:string,occurrence_id:?string}  $targetRef
     * @param  array{kind:string,id:string}  $inviterPrincipal
     */
    private function existingInvite(
        string $receiverUserId,
        ?string $receiverAccountProfileId,
        array $targetRef,
        array $inviterPrincipal,
    ): ?InviteEdge {
        $query = InviteEdge::query()
            ->where('event_id', $targetRef['event_id'])
            ->where('occurrence_id', $targetRef['occurrence_id'])
            ->where('inviter_principal.kind', $inviterPrincipal['kind'])
            ->where('inviter_principal.principal_id', $inviterPrincipal['id']);

        if ($receiverAccountProfileId !== null && $receiverAccountProfileId !== '') {
            $query->where('receiver_account_profile_id', $receiverAccountProfileId);
        } else {
            $query->where('receiver_user_id', $receiverUserId);
        }

        /** @var InviteEdge|null $edge */
        $edge = $query->first();

        return $edge;
    }

    /**
     * @return array{event_id:string,occurrence_id:?string}
     */
    private function targetRef(InviteEdge $edge): array
    {
        return [
            'event_id' => (string) $edge->event_id,
            'occurrence_id' => $edge->occurrence_id ? (string) $edge->occurrence_id : null,
        ];
    }

    /**
     * @param  array<int, string>  $supersededIds
     * @return array<string, mixed>
     */
    private function acceptResponse(InviteEdge $edge, string $status, array $supersededIds, bool $creditedAcceptance): array
    {
        $attendancePolicy = (string) ($edge->attendance_policy ?? 'free_confirmation_only');

        return [
            'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
            'invite_id' => (string) $edge->getAttribute('_id'),
            'target_ref' => $this->targetRef($edge),
            'status' => $status,
            'credited_acceptance' => $creditedAcceptance,
            'attendance_policy' => $attendancePolicy,
            'next_step' => $this->runtimeSettings->nextStepForPolicy($attendancePolicy),
            'superseded_invite_ids' => array_values($supersededIds),
            'accepted_at' => $edge->accepted_at?->toISOString(),
        ];
    }

    /**
     * @param  array{event_id:string,occurrence_id:?string}  $targetRef
     * @return array<int, string>
     */
    private function supersedePendingInvites(
        string $receiverUserId,
        ?string $receiverAccountProfileId,
        array $targetRef,
        string $reason,
        ?string $exceptInviteId = null,
    ): array {
        /** @var \Illuminate\Support\Collection<int, InviteEdge> $candidates */
        $query = InviteEdge::query()
            ->where('event_id', $targetRef['event_id'])
            ->where('occurrence_id', $targetRef['occurrence_id'])
            ->whereIn('status', ['pending', 'viewed']);
        $this->applyReceiverScope($query, $receiverUserId, $receiverAccountProfileId);

        $candidates = $query->get();

        $supersededIds = [];
        foreach ($candidates as $candidate) {
            $candidateId = (string) $candidate->getAttribute('_id');
            if ($exceptInviteId !== null && $candidateId === $exceptInviteId) {
                continue;
            }

            $candidate->fill([
                'status' => 'superseded',
                'supersession_reason' => $reason,
                'credited_acceptance' => false,
            ]);
            $candidate->save();
            $supersededIds[] = $candidateId;
        }

        return $supersededIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function declineResponse(InviteEdge $edge, string $status, bool $groupHasOtherPending): array
    {
        return [
            'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
            'invite_id' => (string) $edge->getAttribute('_id'),
            'target_ref' => $this->targetRef($edge),
            'status' => $status,
            'group_has_other_pending' => $groupHasOtherPending,
            'declined_at' => $edge->declined_at?->toISOString(),
        ];
    }

    private function groupHasOtherPending(InviteEdge $edge): bool
    {
        $query = InviteEdge::query()
            ->where('event_id', (string) $edge->event_id)
            ->where('occurrence_id', $edge->occurrence_id ? (string) $edge->occurrence_id : null)
            ->whereIn('status', ['pending', 'viewed']);
        $this->applyReceiverScope(
            $query,
            (string) $edge->receiver_user_id,
            $this->receiverAccountProfileIdForEdge($edge),
        );

        return $query->count() > 0;
    }

    private function edgeBelongsToReceiver(
        InviteEdge $edge,
        string $receiverUserId,
        ?string $receiverAccountProfileId,
    ): bool {
        $edgeProfileId = $this->nullableString($edge->receiver_account_profile_id ?? null);
        if ($edgeProfileId !== null) {
            return $receiverAccountProfileId !== null && $edgeProfileId === $receiverAccountProfileId;
        }

        return (string) $edge->receiver_user_id === $receiverUserId;
    }

    private function receiverAccountProfileIdForEdge(InviteEdge $edge, ?string $fallbackUserId = null): ?string
    {
        $profileId = $this->nullableString($edge->receiver_account_profile_id ?? null);
        if ($profileId !== null) {
            return $profileId;
        }

        $userId = $fallbackUserId ?? $this->nullableString($edge->receiver_user_id ?? null);
        if ($userId === null) {
            return null;
        }

        return $this->recipientAccountProfileIdForUserId($userId);
    }

    private function recipientAccountProfileIdForUserId(string $userId): ?string
    {
        $recipient = $this->identityGateway->resolveUserRecipientOwnership($userId);
        if (! is_array($recipient)) {
            return null;
        }

        return $this->nullableString($recipient['receiver_account_profile_id'] ?? null);
    }

    private function applyReceiverScope(mixed $query, string $receiverUserId, ?string $receiverAccountProfileId): void
    {
        if ($receiverAccountProfileId !== null && $receiverAccountProfileId !== '') {
            $query->where('receiver_account_profile_id', $receiverAccountProfileId);

            return;
        }

        $query->where('receiver_user_id', $receiverUserId);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isExpired(InviteEdge $edge): bool
    {
        return $edge->expires_at instanceof Carbon && $edge->expires_at->isPast();
    }

    /**
     * @param  array{kind:string,id:string}  $principal
     * @return array{kind:string,principal_id:string}
     */
    private function toStoredPrincipal(array $principal): array
    {
        return [
            'kind' => (string) ($principal['kind'] ?? ''),
            'principal_id' => (string) ($principal['id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $principal
     * @return array{kind:string,id:string}
     */
    private function fromStoredPrincipal(array $principal): array
    {
        return [
            'kind' => (string) ($principal['kind'] ?? ''),
            'id' => (string) ($principal['principal_id'] ?? $principal['id'] ?? ''),
        ];
    }

    private function userId(mixed $user): ?string
    {
        if (! is_object($user)) {
            return null;
        }

        $id = null;
        if (method_exists($user, 'getKey')) {
            $id = $user->getKey();
        }
        if ($id === null && property_exists($user, '_id')) {
            $id = $user->_id;
        }
        if ($id === null && method_exists($user, 'getAttribute')) {
            $id = $user->getAttribute('_id');
        }
        if ($id === null && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        }

        return is_scalar($id) ? (string) $id : null;
    }

    private function dailyWindowKey(Carbon $now): string
    {
        return $now->copy()->utc()->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLimitPayload(
        string $limitKey,
        string $scope,
        int $maxAllowed,
        int $currentCount,
        string $window,
        ?string $resetAt,
    ): array {
        return [
            'limit_key' => $limitKey,
            'scope' => $scope,
            'max_allowed' => $maxAllowed,
            'current_count' => $currentCount,
            'window' => $window,
            'reset_at' => $resetAt,
        ];
    }
}
