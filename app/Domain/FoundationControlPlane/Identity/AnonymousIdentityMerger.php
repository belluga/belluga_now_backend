<?php

declare(strict_types=1);

namespace App\Domain\FoundationControlPlane\Identity;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\IdentityMergeAudit;
use App\Models\Tenants\MergedAccountSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class AnonymousIdentityMerger
{
    /**
     * @param iterable<AccountUser> $sources
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

            $now = Carbon::now();
            $tenantId = Tenant::current()?->id;
            $tenantObjectId = $this->toObjectId($tenantId);
            $operatorObjectId = $this->toObjectId($operatorId);
            $targetObjectId = new ObjectId((string) $target->_id);

            $fingerprints = Collection::make($target->fingerprints ?? [])
                ->keyBy(static fn (array $fingerprint): string => (string) ($fingerprint['hash'] ?? spl_object_id((object) $fingerprint)));

            $consents = $target->consents ?? [];
            $mergedSourceIds = Collection::make($target->merged_source_ids ?? []);
            $promotionAudit = Collection::make($target->promotion_audit ?? []);
            $originalPromotionAudit = $promotionAudit->values()->all();
            $mergeAuditSources = Collection::make();

            foreach ($sourceCollection as $source) {
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

                $source->tokens()->delete();
                $source->forceDelete();
            }

            $promotionAudit = $promotionAudit
                ->sortBy(static function (array $entry) use ($now): int {
                    $timestamp = $entry['promoted_at'] ?? null;
                    if ($timestamp instanceof \DateTimeInterface) {
                        return $timestamp->getTimestamp();
                    }

                    return $now->getTimestamp();
                })
                ->values();

            $target->fingerprints = $fingerprints->values()->all();
            $target->consents = $consents;
            $target->merged_source_ids = $mergedSourceIds->unique()->values()->all();
            $target->promotion_audit = $promotionAudit->values()->all();

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
                $target->first_seen_at = $finalFirstSeen;
            }

            if (in_array($target->identity_state, ['registered', 'validated'], true)) {
                $currentRegisteredAt = $this->toCarbon($target->registered_at ?? null);
                $resolvedRegisteredAt = $this->resolveRegisteredAt($target->promotion_audit ?? [], $currentRegisteredAt);

                if ($resolvedRegisteredAt !== null) {
                    $target->registered_at = $resolvedRegisteredAt;
                }
            }

            $target->save();

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

    /**
     * @param Collection<int, array<string, mixed>> $fingerprints
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
     * @param Collection<int, array<string, mixed>> $fingerprints
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
     * @param array<int, array<string, mixed>> $promotionAudit
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
