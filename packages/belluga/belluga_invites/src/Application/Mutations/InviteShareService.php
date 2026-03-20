<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Mutations;

use Belluga\Invites\Application\Feed\InviteProjectionService;
use Belluga\Invites\Application\Quotas\InviteQuotaCounterService;
use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Application\Targets\InviteTargetResolverService;
use Belluga\Invites\Contracts\InviteAttendanceGatewayContract;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Contracts\InviteTelemetryEmitterContract;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InviteShareService
{
    private const SUPERSESSION_REASON_OTHER_INVITE_CREDITED = 'other_invite_credited';

    private const SUPERSESSION_REASON_DIRECT_CONFIRMATION = 'direct_confirmation';

    public function __construct(
        private readonly InviteAttendanceGatewayContract $attendanceGateway,
        private readonly InviteIdentityGatewayContract $identityGateway,
        private readonly InviteTelemetryEmitterContract $telemetry,
        private readonly InviteTargetResolverService $targetResolver,
        private readonly InviteProjectionService $projectionService,
        private readonly InviteRuntimeSettingsService $runtimeSettings,
        private readonly InviteQuotaCounterService $quotaCounters,
        private readonly InviteCommandIdempotencyService $idempotencyService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(mixed $user, array $payload): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        $inviter = $this->identityGateway->resolveInviterPrincipal(
            $user,
            isset($payload['account_profile_id']) ? (string) $payload['account_profile_id'] : null
        );
        $target = $this->targetResolver->resolve((array) ($payload['target_ref'] ?? []));
        $expiresAt = $target['event_snapshot']['expires_at'];
        $now = Carbon::now();

        /** @var InviteShareCode|null $existing */
        $existing = InviteShareCode::query()
            ->where('event_id', $target['target_ref']['event_id'])
            ->where('occurrence_id', $target['target_ref']['occurrence_id'])
            ->where('inviter_principal.kind', $inviter['principal']['kind'])
            ->where('inviter_principal.principal_id', $inviter['principal']['id'])
            ->orderByDesc('created_at')
            ->first();

        if ($existing && ! $this->isExpired($existing)) {
            return $this->shareCreateResponse($existing);
        }

        $this->enforceShareCreateLimits(
            issuedByUserId: (string) $inviter['issued_by_user_id'],
            targetRef: $target['target_ref'],
            limits: $this->runtimeSettings->limits(),
            cooldowns: $this->runtimeSettings->cooldowns(),
            now: $now,
        );

        $share = new InviteShareCode([
            'code' => $this->generateCode(),
            'event_id' => $target['target_ref']['event_id'],
            'occurrence_id' => $target['target_ref']['occurrence_id'],
            'inviter_principal' => $this->toStoredPrincipal($inviter['principal']),
            'issued_by_user_id' => $inviter['issued_by_user_id'],
            'account_profile_id' => $inviter['account_profile_id'],
            'inviter_display_name' => $inviter['display_name'],
            'inviter_avatar_url' => $inviter['avatar_url'],
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $share->save();

        $this->telemetry->emit(
            event: 'invite.share_code_created',
            userId: $userId,
            properties: [
                'code' => (string) $share->code,
                'target_ref' => [
                    'event_id' => (string) $share->event_id,
                    'occurrence_id' => $share->occurrence_id ? (string) $share->occurrence_id : null,
                ],
                'inviter_principal' => $this->fromStoredPrincipal(is_array($share->inviter_principal) ? $share->inviter_principal : []),
            ],
            idempotencyKey: 'invite.share_code_created:'.(string) $share->code,
            source: 'invite_api',
            context: [
                'actor' => ['type' => 'user', 'id' => $userId],
                'target' => ['type' => 'event', 'id' => (string) $share->event_id],
                'object' => ['type' => 'invite_share_code', 'id' => (string) $share->code],
            ],
        );

        return $this->shareCreateResponse($share);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $code): array
    {
        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            throw new InviteDomainException('invite_share_not_found', 404);
        }

        /** @var InviteShareCode|null $share */
        $share = InviteShareCode::query()
            ->where('code', $normalizedCode)
            ->first();
        if (! $share || $this->isExpired($share)) {
            throw new InviteDomainException('invite_share_not_found', 404);
        }

        $target = $this->targetResolver->resolve([
            'event_id' => (string) $share->event_id,
            'occurrence_id' => $share->occurrence_id ? (string) $share->occurrence_id : null,
        ]);
        $principal = $this->fromStoredPrincipal(is_array($share->inviter_principal) ? $share->inviter_principal : []);
        $inviterDisplayName = trim((string) ($share->inviter_display_name ?? ''));
        if ($inviterDisplayName === '') {
            $inviterDisplayName = 'Um amigo';
        }
        $targetRef = [
            'event_id' => (string) $target['target_ref']['event_id'],
            'occurrence_id' => $target['target_ref']['occurrence_id'],
        ];
        $eventDate = $target['event_snapshot']['event_date'] instanceof Carbon
            ? $target['event_snapshot']['event_date']
            : Carbon::now();
        $eventImageUrl = trim((string) ($target['event_snapshot']['event_image_url'] ?? ''));
        if ($eventImageUrl === '') {
            $eventImageUrl = 'https://example.com/invite-preview.jpg';
        }
        $location = trim((string) ($target['event_snapshot']['location'] ?? ''));
        if (mb_strlen($location) < 3) {
            $location = 'Guarapari';
        }
        $hostName = trim((string) ($target['event_snapshot']['host_name'] ?? ''));
        if (mb_strlen($hostName) < 3) {
            $hostName = 'Belluga';
        }
        $eventName = trim((string) ($target['event_snapshot']['event_name'] ?? ''));
        if (mb_strlen($eventName) < 5) {
            $eventName = 'Convite Belluga';
        }

        return [
            'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
            'code' => $normalizedCode,
            'target_ref' => $targetRef,
            'inviter_principal' => $principal,
            'invite' => [
                'id' => 'share:'.$normalizedCode,
                'target_ref' => $targetRef,
                'event_name' => $eventName,
                'event_date' => $eventDate->toISOString(),
                'event_image_url' => $eventImageUrl,
                'location' => $location,
                'host_name' => $hostName,
                'message' => 'Entre para aceitar ou recusar o convite.',
                'tags' => is_array($target['event_snapshot']['tags'] ?? null)
                    ? array_values(array_map('strval', $target['event_snapshot']['tags']))
                    : [],
                'attendance_policy' => (string) ($target['event_snapshot']['attendance_policy'] ?? 'free_confirmation_only'),
                'inviter_candidates' => [[
                    'invite_id' => 'share:'.$normalizedCode,
                    'inviter_principal' => $principal,
                    'display_name' => $inviterDisplayName,
                    'avatar_url' => $share->inviter_avatar_url,
                    'status' => 'pending',
                ]],
                'social_proof' => [
                    'additional_inviter_count' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function materialize(mixed $user, string $code, ?string $idempotencyKey = null): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }
        if ($this->isAnonymousIdentity($user)) {
            throw new InviteDomainException('auth_required', 401, 'Authenticated account required for invite share acceptance.');
        }

        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            throw new InviteDomainException('invite_share_not_found', 404);
        }

        return $this->idempotencyService->runWithReplay(
            command: 'invite.share_materialize',
            actorUserId: $userId,
            idempotencyKey: $idempotencyKey,
            fingerprintPayload: ['code' => $normalizedCode],
            callback: fn (): array => $this->materializeWithoutReplay($userId, $normalizedCode),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function materializeWithoutReplay(string $userId, string $normalizedCode): array
    {
        /** @var InviteShareCode|null $share */
        $share = InviteShareCode::query()
            ->where('code', $normalizedCode)
            ->first();
        if (! $share) {
            throw new InviteDomainException('invite_share_not_found', 404);
        }

        if ($this->isExpired($share)) {
            return [
                'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
                'invite_id' => null,
                'target_ref' => [
                    'event_id' => (string) $share->event_id,
                    'occurrence_id' => $share->occurrence_id ? (string) $share->occurrence_id : null,
                ],
                'inviter_principal' => $this->fromStoredPrincipal(is_array($share->inviter_principal) ? $share->inviter_principal : []),
                'status' => 'expired',
                'attendance_policy' => 'free_confirmation_only',
                'credited_acceptance' => false,
                'accepted_at' => null,
            ];
        }

        /** @var InviteEdge|null $existing */
        $existing = $this->findExistingInviteEdge($userId, $share);
        if ($existing === null) {
            $existing = $this->createMaterializedInviteEdge($userId, $share);
            $this->projectionService->rebuildReceiverTargetProjection($userId, [
                'event_id' => (string) $existing->event_id,
                'occurrence_id' => $existing->occurrence_id ? (string) $existing->occurrence_id : null,
            ]);
        }

        return $this->materializeResponse($existing, $share);
    }

    /**
     * @return array<string, mixed>
     */
    private function shareCreateResponse(InviteShareCode $share): array
    {
        return [
            'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
            'code' => (string) $share->code,
            'target_ref' => [
                'event_id' => (string) $share->event_id,
                'occurrence_id' => $share->occurrence_id ? (string) $share->occurrence_id : null,
            ],
            'inviter_principal' => $this->fromStoredPrincipal(is_array($share->inviter_principal) ? $share->inviter_principal : []),
        ];
    }

    private function generateCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtoupper(Str::random(10));

            $exists = InviteShareCode::query()
                ->where('code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        throw new InviteDomainException('share_code_generation_failed', 500);
    }

    private function isExpired(InviteShareCode $share): bool
    {
        return $share->expires_at instanceof Carbon && $share->expires_at->isPast();
    }

    /**
     * @param  array{event_id:string,occurrence_id:?string}  $targetRef
     * @param  array<string,int>  $limits
     * @param  array<string,int>  $cooldowns
     */
    private function enforceShareCreateLimits(
        string $issuedByUserId,
        array $targetRef,
        array $limits,
        array $cooldowns,
        Carbon $now,
    ): void {
        $cooldownSeconds = (int) ($cooldowns['share_code_cooldown_seconds'] ?? 0);
        if ($cooldownSeconds > 0) {
            /** @var InviteShareCode|null $recent */
            $recent = InviteShareCode::query()
                ->where('issued_by_user_id', $issuedByUserId)
                ->where('event_id', $targetRef['event_id'])
                ->where('occurrence_id', $targetRef['occurrence_id'])
                ->where('created_at', '>=', $now->copy()->subSeconds($cooldownSeconds))
                ->orderByDesc('created_at')
                ->first();

            if ($recent && $recent->created_at instanceof Carbon) {
                $elapsed = (int) $recent->created_at->diffInSeconds($now, absolute: true);
                $retryAfterSeconds = max(1, $cooldownSeconds - $elapsed);
                $resetAt = $now->copy()->addSeconds($retryAfterSeconds)->toISOString();

                throw new InviteDomainException(
                    'share_rate_limited',
                    429,
                    'Share code cooldown active for this target.',
                    $this->buildLimitPayload(
                        limitKey: 'share_code_cooldown_seconds',
                        scope: 'share_target',
                        maxAllowed: 1,
                        currentCount: 1,
                        window: 'cooldown',
                        resetAt: $resetAt,
                        retryAfterSeconds: $retryAfterSeconds,
                    ),
                );
            }
        }

        $dailyLimit = (int) ($limits['max_share_codes_per_day_per_user_actor'] ?? 30);
        $dailyQuota = $this->quotaCounters->reserve(
            scope: 'share_user_actor_daily',
            scopeId: $issuedByUserId,
            windowKey: $this->dailyWindowKey($now),
            limit: $dailyLimit,
            now: $now,
        );

        if ($dailyQuota['allowed']) {
            return;
        }

        throw new InviteDomainException(
            'rate_limited',
            429,
            'Daily share-code limit reached.',
            $this->buildLimitPayload(
                limitKey: 'max_share_codes_per_day_per_user_actor',
                scope: 'share_user_actor',
                maxAllowed: $dailyLimit,
                currentCount: $dailyQuota['current_count'],
                window: 'day',
                resetAt: $this->runtimeSettings->resetAtForWindow('day', $now),
                retryAfterSeconds: null,
            ),
        );
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
        ?int $retryAfterSeconds,
    ): array {
        return [
            'limit_key' => $limitKey,
            'scope' => $scope,
            'max_allowed' => $maxAllowed,
            'current_count' => $currentCount,
            'window' => $window,
            'reset_at' => $resetAt,
            'retry_after_seconds' => $retryAfterSeconds,
        ];
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

    private function inviteEdgeId(InviteEdge $edge): string
    {
        $id = null;
        if (method_exists($edge, 'getKey')) {
            $id = $edge->getKey();
        }
        if ($id === null) {
            $id = $edge->getAttribute('_id');
        }

        return (string) $id;
    }

    private function findExistingInviteEdge(string $userId, InviteShareCode $share): ?InviteEdge
    {
        /** @var InviteEdge|null $existing */
        $existing = InviteEdge::query()
            ->where('receiver_user_id', $userId)
            ->where('event_id', (string) $share->event_id)
            ->where('occurrence_id', $share->occurrence_id ? (string) $share->occurrence_id : null)
            ->where('inviter_principal.kind', data_get($share->inviter_principal, 'kind'))
            ->where('inviter_principal.principal_id', data_get($share->inviter_principal, 'principal_id'))
            ->first();

        return $existing;
    }

    private function createMaterializedInviteEdge(string $userId, InviteShareCode $share): InviteEdge
    {
        $target = $this->targetResolver->resolve([
            'event_id' => (string) $share->event_id,
            'occurrence_id' => $share->occurrence_id ? (string) $share->occurrence_id : null,
        ]);

        $recipient = $this->identityGateway->resolveUserRecipient($userId);
        if ($recipient === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        [$status, $supersessionReason] = $this->materializedInviteState(
            $recipient['user_id'],
            $target['target_ref']['event_id'],
            $target['target_ref']['occurrence_id'],
        );

        /** @var InviteEdge $edge */
        $edge = InviteEdge::query()->create([
            'event_id' => $target['target_ref']['event_id'],
            'occurrence_id' => $target['target_ref']['occurrence_id'],
            'receiver_user_id' => $recipient['user_id'],
            'receiver_contact_hash' => null,
            'inviter_principal' => $share->inviter_principal,
            'account_profile_id' => $share->account_profile_id ? (string) $share->account_profile_id : null,
            'issued_by_user_id' => (string) $share->issued_by_user_id,
            'inviter_display_name' => $share->inviter_display_name,
            'inviter_avatar_url' => $share->inviter_avatar_url,
            'status' => $status,
            'supersession_reason' => $supersessionReason,
            'credited_acceptance' => false,
            'source' => 'share_url',
            'message' => null,
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
        ]);

        return $edge;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function materializedInviteState(string $userId, string $eventId, ?string $occurrenceId): array
    {
        if ($this->attendanceGateway->hasActiveAttendanceConfirmation($userId, $eventId, $occurrenceId)) {
            return ['superseded', self::SUPERSESSION_REASON_DIRECT_CONFIRMATION];
        }

        $creditedWinnerExists = InviteEdge::query()
            ->where('receiver_user_id', $userId)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->where('credited_acceptance', true)
            ->exists();
        if ($creditedWinnerExists) {
            return ['superseded', self::SUPERSESSION_REASON_OTHER_INVITE_CREDITED];
        }

        return ['pending', null];
    }

    /**
     * @return array<string, mixed>
     */
    private function materializeResponse(InviteEdge $edge, InviteShareCode $share): array
    {
        return [
            'tenant_id' => $this->runtimeSettings->settingsPayload()['tenant_id'],
            'invite_id' => $this->inviteEdgeId($edge),
            'target_ref' => [
                'event_id' => (string) $edge->event_id,
                'occurrence_id' => $edge->occurrence_id ? (string) $edge->occurrence_id : null,
            ],
            'inviter_principal' => $this->fromStoredPrincipal(is_array($share->inviter_principal) ? $share->inviter_principal : []),
            'status' => (string) ($edge->status ?? 'pending'),
            'attendance_policy' => (string) ($edge->attendance_policy ?? 'free_confirmation_only'),
            'credited_acceptance' => (bool) ($edge->credited_acceptance ?? false),
            'accepted_at' => $edge->accepted_at?->toISOString(),
        ];
    }

    private function isAnonymousIdentity(mixed $user): bool
    {
        if (! is_object($user)) {
            return false;
        }

        $identityState = null;
        if (property_exists($user, 'identity_state')) {
            $identityState = $user->identity_state;
        }
        if (($identityState === null || $identityState === '') && method_exists($user, 'getAttribute')) {
            $identityState = $user->getAttribute('identity_state');
        }

        return is_string($identityState) && trim($identityState) === 'anonymous';
    }
}
