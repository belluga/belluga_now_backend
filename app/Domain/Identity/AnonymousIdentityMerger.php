<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Application\ProximityPreferences\ProximityPreferenceOwnershipService;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\IdentityMergeAudit;
use App\Models\Tenants\MergedAccountSnapshot;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteOutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class AnonymousIdentityMerger
{
    public function __construct(
        private readonly ProximityPreferenceOwnershipService $proximityPreferenceOwnershipService,
    ) {}

    /**
     * @param  iterable<AccountUser>  $sources
     *
     * @throws ConcurrencyConflictException|\Throwable
     */
    public function merge(AccountUser $target, iterable $sources, ?string $operatorId = null, string $reason = 'merged'): void
    {
        $sourceCollection = Collection::make($sources)
            ->filter(static fn (AccountUser $user): bool => $user->identity_state === 'anonymous')
            ->values();

        if ($sourceCollection->isEmpty()) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($target, $sourceCollection, $operatorId, $reason): void {
            $target->refresh();
            $currentVersion = $target->version ?? 1;

            $now = Carbon::now();
            $tenantId = Tenant::current()?->id;
            $tenantObjectId = $this->toObjectId($tenantId);
            $operatorObjectId = $this->toObjectId($operatorId);
            $targetObjectId = new ObjectId((string) $target->_id);

            $fingerprints = Collection::make($target->fingerprints ?? [])
                ->keyBy(static fn (array $fingerprint): string => (string) ($fingerprint['hash'] ?? spl_object_id((object) $fingerprint)));

            $devices = Collection::make($target->devices ?? [])
                ->keyBy(static fn (array $device): string => (string) ($device['device_id'] ?? spl_object_id((object) $device)));

            $consents = $target->consents ?? [];
            $mergedSourceIds = Collection::make($target->merged_source_ids ?? []);
            $promotionAudit = Collection::make($target->promotion_audit ?? []);
            $originalPromotionAudit = $promotionAudit->values()->all();
            $mergeAuditSources = Collection::make();

            foreach ($sourceCollection as $source) {
                $source = $this->refreshSource($source);
                $sourceId = (string) $source->_id;
                $sourceObjectId = new ObjectId($sourceId);
                $mergedAt = Carbon::now();

                $snapshot = MergedAccountSnapshot::create([
                    'tenant_id' => $tenantObjectId,
                    'source_user_id' => $sourceObjectId,
                    'merged_into' => $targetObjectId,
                    'identity_state' => $source->identity_state,
                    'snapshot' => $source->getAttributes(),
                    'merged_at' => $mergedAt,
                    'operator_id' => $operatorObjectId,
                    'reason' => $reason,
                ]);

                $sourceFingerprints = Collection::make($source->fingerprints ?? []);
                $firstSeen = $this->resolveFirstSeenAt($sourceFingerprints, $source);
                $lastSeen = $this->resolveLastSeenAt($sourceFingerprints, $source);

                foreach ($source->fingerprints ?? [] as $fingerprint) {
                    if (! isset($fingerprint['hash'])) {
                        continue;
                    }

                    $hash = (string) $fingerprint['hash'];
                    $existing = $fingerprints->get($hash, []);

                    $mergedFingerprint = array_filter([
                        'hash' => $hash,
                        'first_seen_at' => $existing['first_seen_at'] ?? $fingerprint['first_seen_at'] ?? $now,
                        'last_seen_at' => $fingerprint['last_seen_at'] ?? $existing['last_seen_at'] ?? $now,
                        'user_agent' => $fingerprint['user_agent'] ?? $existing['user_agent'] ?? null,
                        'locale' => $fingerprint['locale'] ?? $existing['locale'] ?? null,
                        'metadata' => array_merge($existing['metadata'] ?? [], $fingerprint['metadata'] ?? []),
                    ], static fn ($value) => $value !== null);

                    $fingerprints->put($hash, $mergedFingerprint);
                }

                if (! empty($source->consents)) {
                    $consents = array_replace_recursive($consents, $source->consents);
                }

                $devices = $this->mergeDevices(
                    $devices,
                    $source->devices ?? [],
                    $now
                );

                $mergedSourceIds->push($sourceId);
                $mergeAuditEntry = [
                    'source_user_id' => $sourceObjectId,
                    'merged_at' => $mergedAt,
                    'promotion_audit' => $source->promotion_audit ?? [],
                ];

                if (isset($snapshot->_id)) {
                    $mergeAuditEntry['snapshot_id'] = $snapshot->_id;
                }

                if ($firstSeen !== null) {
                    $mergeAuditEntry['first_seen_at'] = $firstSeen;
                }

                if ($lastSeen !== null) {
                    $mergeAuditEntry['last_seen_at'] = $lastSeen;
                }

                $mergeAuditSources->push($mergeAuditEntry);

                $this->migrateInviteOwnership(
                    sourceUserId: $sourceId,
                    targetUserId: (string) $target->_id,
                );

                $source->tokens()->delete();
                $source->forceDelete();
            }

            $this->proximityPreferenceOwnershipService->mergeOwnership(
                targetUserId: (string) $target->_id,
                sourceUserIds: $sourceCollection->map(
                    static fn (AccountUser $user): string => (string) $user->_id,
                )->all(),
            );

            $promotionAudit = $promotionAudit
                ->sortBy(static function (array $entry) use ($now): int {
                    $timestamp = $entry['promoted_at'] ?? null;
                    if ($timestamp instanceof \DateTimeInterface) {
                        return $timestamp->getTimestamp();
                    }

                    return $now->getTimestamp();
                })
                ->values();

            $updatePayload = [
                'fingerprints' => $fingerprints->values()->all(),
                'devices' => $devices->values()->all(),
                'consents' => $consents,
                'merged_source_ids' => $mergedSourceIds->unique()->values()->all(),
                'promotion_audit' => $promotionAudit->values()->all(),
                'version' => $currentVersion + 1,
            ];

            $aggregateFirst = null;
            $aggregateLast = null;
            if ($mergeAuditSources->isNotEmpty()) {
                $aggregateFirst = $mergeAuditSources
                    ->pluck('first_seen_at')
                    ->filter()
                    ->sortBy(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
                    ->first();

                $aggregateLast = $mergeAuditSources
                    ->pluck('last_seen_at')
                    ->filter()
                    ->sortByDesc(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
                    ->first();
            }

            $existingFirstSeen = $this->toCarbon($target->first_seen_at ?? null);
            $createdAt = $this->toCarbon($target->created_at ?? null);

            $finalFirstSeen = Collection::make([$aggregateFirst, $existingFirstSeen, $createdAt])
                ->filter()
                ->sortBy(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
                ->first();

            if ($finalFirstSeen !== null) {
                $updatePayload['first_seen_at'] = $finalFirstSeen;
            }

            if (in_array($target->identity_state, ['registered', 'validated'], true)) {
                $currentRegisteredAt = $this->toCarbon($target->registered_at ?? null);
                $resolvedRegisteredAt = $this->resolveRegisteredAt($target->promotion_audit ?? [], $currentRegisteredAt);

                if ($resolvedRegisteredAt !== null) {
                    $updatePayload['registered_at'] = $resolvedRegisteredAt;
                }
            }

            $affectedRows = AccountUser::query()
                ->where('_id', $target->_id)
                ->where('version', $currentVersion)
                ->update($updatePayload);

            if ($affectedRows === 0) {
                throw new ConcurrencyConflictException('Failed to merge identities due to a concurrency conflict.');
            }

            $target->refresh();

            if ($mergeAuditSources->isNotEmpty()) {
                $targetFingerprintLastSeen = Collection::make($target->fingerprints ?? [])
                    ->map(fn (array $fingerprint): ?Carbon => $this->toCarbon($fingerprint['last_seen_at'] ?? null))
                    ->filter()
                    ->sortByDesc(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
                    ->first();

                $timelineFirst = $finalFirstSeen ?? $aggregateFirst;
                $timelineLast = Collection::make([$aggregateLast, $targetFingerprintLastSeen, $this->toCarbon($target->updated_at ?? null)])
                    ->filter()
                    ->sortByDesc(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
                    ->first();

                IdentityMergeAudit::create([
                    'tenant_id' => $tenantObjectId,
                    'canonical_user_id' => $targetObjectId,
                    'merged_source_ids' => $mergeAuditSources->pluck('source_user_id')->all(),
                    'consolidated_at' => $now,
                    'operator' => array_filter([
                        'id' => $operatorObjectId,
                        'reason' => $reason,
                    ], static fn ($value) => $value !== null),
                    'timeline' => array_filter([
                        'first_seen_at' => $timelineFirst,
                        'last_seen_at' => $timelineLast,
                    ], static fn ($value) => $value !== null),
                    'sources' => $mergeAuditSources->values()->all(),
                    'target_promotion_audit_before_merge' => $originalPromotionAudit,
                    'target_identity_state' => $target->identity_state,
                ]);
            }
        });
    }

    private function toObjectId(?string $value): ?ObjectId
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new ObjectId($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function refreshSource(AccountUser $source): AccountUser
    {
        return AccountUser::query()->find($source->_id) ?? $source;
    }

    private function migrateInviteOwnership(string $sourceUserId, string $targetUserId): void
    {
        InviteEdge::query()
            ->where('receiver_user_id', $sourceUserId)
            ->update([
                'receiver_user_id' => $targetUserId,
            ]);

        $this->migrateInviteProjectionOwnership(
            sourceUserId: $sourceUserId,
            targetUserId: $targetUserId,
        );

        InviteOutboxEvent::query()
            ->where('receiver_user_id', $sourceUserId)
            ->update([
                'receiver_user_id' => $targetUserId,
            ]);
    }

    private function migrateInviteProjectionOwnership(string $sourceUserId, string $targetUserId): void
    {
        InviteFeedProjection::query()
            ->where('receiver_user_id', $sourceUserId)
            ->get()
            ->each(function (InviteFeedProjection $sourceProjection) use ($targetUserId): void {
                $groupKey = trim((string) ($sourceProjection->group_key ?? ''));
                if ($groupKey === '') {
                    $sourceProjection->receiver_user_id = $targetUserId;
                    $sourceProjection->save();

                    return;
                }

                /** @var InviteFeedProjection|null $targetProjection */
                $targetProjection = InviteFeedProjection::query()
                    ->where('receiver_user_id', $targetUserId)
                    ->where('group_key', $groupKey)
                    ->first();

                if ($targetProjection === null) {
                    $sourceProjection->receiver_user_id = $targetUserId;
                    $sourceProjection->save();

                    return;
                }

                $targetProjection->fill($this->mergeInviteProjectionAttributes(
                    targetProjection: $targetProjection,
                    sourceProjection: $sourceProjection,
                    targetUserId: $targetUserId,
                    groupKey: $groupKey,
                ));
                $targetProjection->save();

                $sourceProjection->delete();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeInviteProjectionAttributes(
        InviteFeedProjection $targetProjection,
        InviteFeedProjection $sourceProjection,
        string $targetUserId,
        string $groupKey,
    ): array {
        $mergedCandidates = $this->mergeInviteProjectionCandidates(
            $targetProjection->inviter_candidates ?? null,
            $sourceProjection->inviter_candidates ?? null,
        );

        return [
            'receiver_user_id' => $targetUserId,
            'group_key' => $groupKey,
            'event_id' => $this->preferProjectionValue($targetProjection->event_id ?? null, $sourceProjection->event_id ?? null),
            'occurrence_id' => $this->preferProjectionValue($targetProjection->occurrence_id ?? null, $sourceProjection->occurrence_id ?? null),
            'event_name' => $this->preferProjectionValue($targetProjection->event_name ?? null, $sourceProjection->event_name ?? null),
            'event_slug' => $this->preferProjectionValue($targetProjection->event_slug ?? null, $sourceProjection->event_slug ?? null),
            'event_date' => $this->preferProjectionValue($targetProjection->event_date ?? null, $sourceProjection->event_date ?? null),
            'event_image_url' => $this->preferProjectionValue($targetProjection->event_image_url ?? null, $sourceProjection->event_image_url ?? null),
            'location' => $this->preferProjectionValue($targetProjection->location ?? null, $sourceProjection->location ?? null),
            'host_name' => $this->preferProjectionValue($targetProjection->host_name ?? null, $sourceProjection->host_name ?? null),
            'message' => $this->preferProjectionValue($targetProjection->message ?? null, $sourceProjection->message ?? null),
            'tags' => $this->mergeInviteProjectionTags(
                $targetProjection->tags ?? null,
                $sourceProjection->tags ?? null,
            ),
            'attendance_policy' => $this->preferProjectionValue($targetProjection->attendance_policy ?? null, $sourceProjection->attendance_policy ?? null),
            'inviter_candidates' => $mergedCandidates,
            'social_proof' => $this->mergeInviteProjectionSocialProof(
                $targetProjection->social_proof ?? null,
                $sourceProjection->social_proof ?? null,
                $mergedCandidates,
            ),
        ];
    }

    private function preferProjectionValue(mixed $current, mixed $incoming): mixed
    {
        if (is_string($current)) {
            return trim($current) !== '' ? $current : $incoming;
        }

        if (is_array($current)) {
            return $current !== [] ? $current : $incoming;
        }

        return $current ?? $incoming;
    }

    /**
     * @return array<int, string>
     */
    private function mergeInviteProjectionTags(mixed $currentTags, mixed $incomingTags): array
    {
        return Collection::make(is_array($currentTags) ? $currentTags : [])
            ->concat(is_array($incomingTags) ? $incomingTags : [])
            ->map(static fn (mixed $tag): string => trim((string) $tag))
            ->filter(static fn (string $tag): bool => $tag !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mergeInviteProjectionCandidates(mixed $currentCandidates, mixed $incomingCandidates): array
    {
        $merged = [];

        foreach ([is_array($currentCandidates) ? $currentCandidates : [], is_array($incomingCandidates) ? $incomingCandidates : []] as $candidates) {
            foreach ($candidates as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $key = $this->inviteProjectionCandidateKey($candidate);
                $merged[$key] = array_replace_recursive($merged[$key] ?? [], $candidate);
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function inviteProjectionCandidateKey(array $candidate): string
    {
        $inviteId = trim((string) ($candidate['invite_id'] ?? ''));
        if ($inviteId !== '') {
            return 'invite:'.$inviteId;
        }

        $principal = is_array($candidate['inviter_principal'] ?? null)
            ? $candidate['inviter_principal']
            : [];

        $principalKey = implode('|', [
            trim((string) ($principal['kind'] ?? '')),
            trim((string) ($principal['id'] ?? '')),
            trim((string) ($candidate['display_name'] ?? '')),
        ]);

        if ($principalKey !== '||') {
            return 'principal:'.$principalKey;
        }

        return 'fallback:'.sha1(json_encode($candidate) ?: '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $mergedCandidates
     * @return array<string, mixed>
     */
    private function mergeInviteProjectionSocialProof(
        mixed $currentSocialProof,
        mixed $incomingSocialProof,
        array $mergedCandidates,
    ): array {
        $current = is_array($currentSocialProof) ? $currentSocialProof : [];
        $incoming = is_array($incomingSocialProof) ? $incomingSocialProof : [];

        return array_merge($current, $incoming, [
            'additional_inviter_count' => max(
                0,
                count($mergedCandidates) - 1,
                (int) ($current['additional_inviter_count'] ?? 0),
                (int) ($incoming['additional_inviter_count'] ?? 0),
            ),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $devices
     * @param  array<int, array<string, mixed>>  $sourceDevices
     */
    private function mergeDevices(Collection $devices, array $sourceDevices, Carbon $now): Collection
    {
        foreach ($sourceDevices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $deviceId = (string) ($device['device_id'] ?? '');
            if ($deviceId === '') {
                continue;
            }
            $existing = $devices->get($deviceId);
            if ($existing === null) {
                $devices->put($deviceId, $device);

                continue;
            }
            $devices->put(
                $deviceId,
                $this->resolveLatestDevice($existing, $device, $now)
            );
        }

        return $devices;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     */
    private function resolveLatestDevice(array $current, array $incoming, Carbon $now): array
    {
        $currentAt = $this->toCarbon($current['updated_at'] ?? $current['created_at'] ?? null);
        $incomingAt = $this->toCarbon($incoming['updated_at'] ?? $incoming['created_at'] ?? null);

        if ($currentAt === null && $incomingAt === null) {
            return $incoming;
        }
        if ($currentAt === null) {
            return $incoming;
        }
        if ($incomingAt === null) {
            return $current;
        }
        if ($incomingAt->greaterThan($currentAt)) {
            return $incoming;
        }

        return $current;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $fingerprints
     */
    private function resolveFirstSeenAt(Collection $fingerprints, AccountUser $source): ?Carbon
    {
        $firstFingerprintSeen = $fingerprints
            ->map(fn (array $fingerprint): ?Carbon => $this->toCarbon($fingerprint['first_seen_at'] ?? null))
            ->filter()
            ->sortBy(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
            ->first();

        $identityCreatedAt = $this->toCarbon($source->created_at ?? null);
        if ($identityCreatedAt === null) {
            return $firstFingerprintSeen;
        }

        if ($firstFingerprintSeen === null || $identityCreatedAt->lessThan($firstFingerprintSeen)) {
            return $identityCreatedAt;
        }

        return $firstFingerprintSeen;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $fingerprints
     */
    private function resolveLastSeenAt(Collection $fingerprints, AccountUser $source): ?Carbon
    {
        $lastFingerprintSeen = $fingerprints
            ->map(fn (array $fingerprint): ?Carbon => $this->toCarbon($fingerprint['last_seen_at'] ?? null))
            ->filter()
            ->sortByDesc(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
            ->first();

        $identityUpdatedAt = $this->toCarbon($source->updated_at ?? null);
        if ($identityUpdatedAt === null) {
            return $lastFingerprintSeen;
        }

        if ($lastFingerprintSeen === null || $identityUpdatedAt->greaterThan($lastFingerprintSeen)) {
            return $identityUpdatedAt;
        }

        return $lastFingerprintSeen;
    }

    /**
     * @param  array<int, array<string, mixed>>  $promotionAudit
     */
    private function resolveRegisteredAt(array $promotionAudit, ?Carbon $currentRegisteredAt): ?Carbon
    {
        $candidates = Collection::make($promotionAudit)
            ->filter(static function (array $entry): bool {
                $toState = $entry['to_state'] ?? null;

                return in_array($toState, ['registered', 'validated'], true);
            })
            ->map(fn (array $entry): ?Carbon => $this->toCarbon($entry['promoted_at'] ?? null))
            ->filter()
            ->values();

        if ($currentRegisteredAt !== null) {
            $candidates->push($currentRegisteredAt);
        }

        return $candidates
            ->sortBy(static fn (Carbon $timestamp): int => $timestamp->getTimestamp())
            ->first();
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
