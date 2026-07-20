<?php

declare(strict_types=1);

namespace App\Application\Profiles;

use App\Application\AccountProfiles\AccountProfileDeletionBarrier;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Operation\FindOneAndUpdate;

final class CurrentTenantAccountDeletionAttemptService
{
    private const COLLECTION = 'account_profile_deletion_attempts';

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileMediaService $profileMedia,
        private readonly AccountProfileDeletionBarrier $deletionBarrier,
    ) {}

    /** @return array<string, mixed> */
    public function captureOrResume(string $userId): array
    {
        $attempt = $this->reserveCaptureAttempt($userId);
        if ((string) ($attempt['phase'] ?? '') !== 'capture_claimed') {
            return $attempt;
        }

        try {
            return $this->captureClaimedAttempt($attempt);
        } catch (\Throwable $exception) {
            $this->discardCaptureClaim($attempt);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    public function transition(array $attempt, string $expectedPhase, string $nextPhase): array
    {
        $userId = trim((string) ($attempt['_id'] ?? ''));
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        $stateRevision = (int) ($attempt['state_revision'] ?? 0);
        if ($userId === '' || $claimToken === '' || $stateRevision < 1) {
            throw new ConcurrencyConflictException('Account Profile deletion attempt claim is invalid.');
        }
        if ($nextPhase === 'media_purged' && $this->frozenMediaDescriptors($attempt) !== []) {
            throw new ConcurrencyConflictException('Frozen Account Profile media descriptors are not fully purged.');
        }

        return $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($userId, $claimToken, $stateRevision, $expectedPhase, $nextPhase): array {
            $now = new UTCDateTime((int) now()->getTimestampMs());
            $update = [
                '$set' => [
                    'phase' => $nextPhase,
                    'updated_at' => $now,
                ],
                '$inc' => ['state_revision' => 1],
            ];
            if ($nextPhase === 'completed') {
                $update['$set']['completed_at'] = $now;
                $update['$set']['last_error'] = null;
                $update['$unset'] = [
                    'claim_token' => true,
                    'claim_expires_at' => true,
                ];
            }

            $updated = $context->collection(self::COLLECTION)->findOneAndUpdate(
                [
                    '_id' => $userId,
                    'phase' => $expectedPhase,
                    'state_revision' => $stateRevision,
                    'claim_token' => $claimToken,
                ],
                $update,
                [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
            );
            $updated = $this->documentToArray($updated);
            if ($updated !== null) {
                return $updated;
            }

            throw new ConcurrencyConflictException('Account Profile deletion attempt phase transition was rejected.');
        });
    }

    public function releaseClaim(?array $attempt): void
    {
        $userId = trim((string) ($attempt['_id'] ?? ''));
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        if ($userId === '' || $claimToken === '') {
            return;
        }

        $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($userId, $claimToken): void {
            $now = new UTCDateTime((int) now()->getTimestampMs());
            $context->collection(self::COLLECTION)->updateOne(
                [
                    '_id' => $userId,
                    'phase' => ['$ne' => 'completed'],
                    'claim_token' => $claimToken,
                ],
                [
                    '$set' => ['updated_at' => $now],
                    '$inc' => ['state_revision' => 1],
                    '$unset' => [
                        'claim_token' => true,
                        'claim_expires_at' => true,
                    ],
                ],
                $context->rawOptions(),
            );
        });
    }

    /**
     * @param  array<string, mixed>|null  $attempt
     * @return array<string, mixed>|null
     */
    public function recordFailure(?array $attempt, \Throwable $exception): ?array
    {
        $userId = trim((string) ($attempt['_id'] ?? ''));
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        $stateRevision = (int) ($attempt['state_revision'] ?? 0);
        if ($userId === '' || $claimToken === '' || $stateRevision < 1) {
            return $attempt;
        }

        return $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($userId, $claimToken, $stateRevision, $exception): ?array {
            $now = new UTCDateTime((int) now()->getTimestampMs());
            $updated = $context->collection(self::COLLECTION)->findOneAndUpdate(
                [
                    '_id' => $userId,
                    'phase' => ['$ne' => 'completed'],
                    'state_revision' => $stateRevision,
                    'claim_token' => $claimToken,
                ],
                [
                    '$set' => [
                        'last_error' => [
                            'class' => $exception::class,
                            'message' => mb_substr($exception->getMessage(), 0, 500),
                            'occurred_at' => $now,
                        ],
                        'updated_at' => $now,
                    ],
                    '$inc' => ['state_revision' => 1],
                ],
                [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
            );

            return $this->documentToArray($updated);
        });
    }

    /**
     * Deletes only frozen media entries not yet durably marked as purged.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    public function purgeFrozenMediaDescriptors(array $attempt): array
    {
        foreach ($this->pendingFrozenMediaDescriptors($attempt) as $descriptor) {
            $this->profileMedia->purgeFrozenDeletionMediaDescriptors([[
                'path' => $descriptor['path'],
                'checksum' => $descriptor['checksum'],
            ]]);
            $attempt = $this->markFrozenMediaDescriptorPurged(
                $attempt,
                $descriptor['profile_id'],
                $descriptor['path'],
                $descriptor['checksum'],
            );
        }

        return $attempt;
    }

    /** @param array<string, mixed> $attempt
     * @return list<array{path:string,checksum:string}>
     */
    public function frozenMediaDescriptors(array $attempt): array
    {
        $descriptors = [];
        foreach ((array) ($attempt['profile_descriptors'] ?? []) as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            foreach ((array) ($profile['media_descriptors'] ?? []) as $media) {
                if (! is_array($media)) {
                    continue;
                }
                if (array_key_exists('purged_at', $media) && $media['purged_at'] !== null) {
                    continue;
                }
                $path = trim((string) ($media['path'] ?? ''));
                $checksum = trim((string) ($media['checksum'] ?? ''));
                if ($path !== '' && $checksum !== '') {
                    $descriptors[] = ['path' => $path, 'checksum' => $checksum];
                }
            }
        }

        return $descriptors;
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array{profile_id:string,path:string,checksum:string}>
     */
    private function pendingFrozenMediaDescriptors(array $attempt): array
    {
        $descriptors = [];
        foreach ((array) ($attempt['profile_descriptors'] ?? []) as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $profileId = trim((string) ($profile['profile_id'] ?? ''));
            if ($profileId === '') {
                continue;
            }
            foreach ((array) ($profile['media_descriptors'] ?? []) as $media) {
                if (! is_array($media) || (array_key_exists('purged_at', $media) && $media['purged_at'] !== null)) {
                    continue;
                }
                $path = trim((string) ($media['path'] ?? ''));
                $checksum = trim((string) ($media['checksum'] ?? ''));
                if ($path !== '' && $checksum !== '') {
                    $descriptors[] = [
                        'profile_id' => $profileId,
                        'path' => $path,
                        'checksum' => $checksum,
                    ];
                }
            }
        }

        return $descriptors;
    }

    /** @return array<string, mixed> */
    private function reserveCaptureAttempt(string $userId): array
    {
        for ($reservationAttempt = 1; $reservationAttempt <= 2; $reservationAttempt++) {
            try {
                return $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($userId): array {
                    $attempt = $this->attempt($context, $userId);
                    if ((string) ($attempt['phase'] ?? '') === 'completed') {
                        return $attempt;
                    }

                    $this->deletionBarrier->touch($context, $userId);
                    if ($attempt !== null) {
                        return $this->claim($context, $attempt);
                    }

                    $now = new UTCDateTime((int) now()->getTimestampMs());
                    $attempt = [
                        '_id' => $userId,
                        'schema_version' => 1,
                        'attempt_generation' => 1,
                        'state_revision' => 1,
                        'phase' => 'capture_claimed',
                        'claim_token' => (string) Str::uuid(),
                        'claim_expires_at' => new UTCDateTime((int) now()->addMinutes(5)->getTimestampMs()),
                        'profile_descriptors' => [],
                        'account_descriptors' => [],
                        'attempts' => 1,
                        'last_error' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $context->collection(self::COLLECTION)->insertOne($attempt, $context->rawOptions());

                    return $attempt;
                });
            } catch (\Throwable $exception) {
                if ($reservationAttempt === 1 && $this->isDuplicateAttemptKey($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('Account Profile deletion attempt reservation was exhausted.');
    }

    /** @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function captureClaimedAttempt(array $attempt): array
    {
        $userId = trim((string) ($attempt['_id'] ?? ''));
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        $stateRevision = (int) ($attempt['state_revision'] ?? 0);
        if ($userId === '' || $claimToken === '' || $stateRevision < 1) {
            throw new ConcurrencyConflictException('Account Profile deletion capture claim is invalid.');
        }

        return $this->transactionRunner->run(
            function (AccountProfileTransactionContext $context) use ($userId, $claimToken, $stateRevision): array {
                $currentAttempt = $this->attempt($context, $userId);
                if (
                    $currentAttempt === null
                    || (string) ($currentAttempt['phase'] ?? '') !== 'capture_claimed'
                    || (int) ($currentAttempt['state_revision'] ?? 0) !== $stateRevision
                    || ! hash_equals($claimToken, (string) ($currentAttempt['claim_token'] ?? ''))
                ) {
                    throw new ConcurrencyConflictException('Account Profile deletion capture claim was lost.');
                }

                $profiles = AccountProfile::withTrashed()
                    ->where('created_by', $userId)
                    ->where('created_by_type', 'tenant')
                    ->where('profile_type', 'personal')
                    ->orderBy('_id')
                    ->get();
                $profileDescriptors = [];
                $accountIds = [];
                foreach ($profiles as $profile) {
                    $profileId = trim((string) $profile->getKey());
                    $accountId = trim((string) $profile->account_id);
                    if ($profileId === '') {
                        continue;
                    }

                    $mediaDescriptors = $this->profileMedia->freezeDeletionMediaDescriptors($profile);
                    $profile->setAttribute(
                        'lifecycle_fence_revision',
                        max(0, (int) $profile->getAttribute('lifecycle_fence_revision')) + 1,
                    );
                    $profile->setAttribute('account_profile_deletion_attempt_id', $userId);
                    $profile->save();

                    $profileDescriptors[] = [
                        'profile_id' => $profileId,
                        'account_id' => $accountId,
                        'aggregate_revision' => max(0, (int) $profile->getAttribute('aggregate_revision')),
                        'lifecycle_fence_revision' => (int) $profile->getAttribute('lifecycle_fence_revision'),
                        'was_soft_deleted' => $profile->deleted_at !== null,
                        'media_descriptors' => $mediaDescriptors,
                    ];
                    if ($accountId !== '') {
                        $accountIds[] = $accountId;
                    }
                }

                $accountDescriptors = $this->captureAccountGates(
                    $context,
                    $userId,
                    array_values(array_unique($accountIds)),
                );
                $now = new UTCDateTime((int) now()->getTimestampMs());
                $captured = [
                    ...$currentAttempt,
                    'phase' => 'captured_and_fenced',
                    'profile_descriptors' => $profileDescriptors,
                    'account_descriptors' => $accountDescriptors,
                    'updated_at' => $now,
                ];
                $result = $context->collection(self::COLLECTION)->updateOne(
                    [
                        '_id' => $userId,
                        'phase' => 'capture_claimed',
                        'state_revision' => $stateRevision,
                        'claim_token' => $claimToken,
                    ],
                    [
                        '$set' => [
                            'phase' => 'captured_and_fenced',
                            'profile_descriptors' => $profileDescriptors,
                            'account_descriptors' => $accountDescriptors,
                            'updated_at' => $now,
                        ],
                    ],
                    $context->rawOptions(),
                );
                if ($result->getMatchedCount() !== 1) {
                    throw new ConcurrencyConflictException('Account Profile deletion capture claim was not promoted.');
                }

                return $captured;
            },
        );
    }

    /** @param array<string, mixed> $attempt */
    private function discardCaptureClaim(array $attempt): void
    {
        $userId = trim((string) ($attempt['_id'] ?? ''));
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        $stateRevision = (int) ($attempt['state_revision'] ?? 0);
        if ($userId === '' || $claimToken === '' || $stateRevision < 1) {
            return;
        }

        try {
            $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($userId, $claimToken, $stateRevision): void {
                $context->collection(self::COLLECTION)->deleteOne(
                    [
                        '_id' => $userId,
                        'phase' => 'capture_claimed',
                        'state_revision' => $stateRevision,
                        'claim_token' => $claimToken,
                    ],
                    $context->rawOptions(),
                );
            });
        } catch (\Throwable) {
            // Preserve the original capture failure; a changed claim is never deleted.
        }
    }

    private function isDuplicateAttemptKey(\Throwable $exception): bool
    {
        return (int) $exception->getCode() === 11000;
    }

    /** @return array<string, mixed>|null */
    private function attempt(AccountProfileTransactionContext $context, string $userId): ?array
    {
        $attempt = $context->collection(self::COLLECTION)->findOne(['_id' => $userId], $context->rawOptions());

        return $this->documentToArray($attempt);
    }

    /** @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function claim(AccountProfileTransactionContext $context, array $attempt): array
    {
        if ((string) ($attempt['phase'] ?? '') === 'completed') {
            return $attempt;
        }

        $userId = trim((string) ($attempt['_id'] ?? ''));
        $stateRevision = (int) ($attempt['state_revision'] ?? 0);
        if ($userId === '' || $stateRevision < 1) {
            throw new ConcurrencyConflictException('Account Profile deletion attempt state is invalid.');
        }

        $now = new UTCDateTime((int) now()->getTimestampMs());
        $claimed = $context->collection(self::COLLECTION)->findOneAndUpdate(
            [
                '_id' => $userId,
                'state_revision' => $stateRevision,
                'phase' => ['$ne' => 'completed'],
                '$or' => [
                    ['claim_token' => ['$exists' => false]],
                    ['claim_expires_at' => ['$lte' => $now]],
                ],
            ],
            [
                '$set' => [
                    'claim_token' => (string) Str::uuid(),
                    'claim_expires_at' => new UTCDateTime((int) now()->addMinutes(5)->getTimestampMs()),
                    'updated_at' => $now,
                ],
                '$inc' => [
                    'state_revision' => 1,
                    'attempts' => 1,
                ],
            ],
            [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        $claimed = $this->documentToArray($claimed);
        if ($claimed === null) {
            throw new ConcurrencyConflictException('Account Profile deletion attempt is already claimed.');
        }

        return $claimed;
    }

    /**
     * The filesystem delete may have completed before a process crash. A retry
     * therefore treats an absent file as success, then records this exact
     * frozen descriptor with the claim/state CAS.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    private function markFrozenMediaDescriptorPurged(
        array $attempt,
        string $profileId,
        string $path,
        string $checksum,
    ): array {
        $userId = trim((string) ($attempt['_id'] ?? ''));
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        $stateRevision = (int) ($attempt['state_revision'] ?? 0);
        if ($userId === '' || $claimToken === '' || $stateRevision < 1) {
            throw new ConcurrencyConflictException('Account Profile deletion media purge claim is invalid.');
        }

        return $this->transactionRunner->run(
            function (AccountProfileTransactionContext $context) use ($userId, $claimToken, $stateRevision, $profileId, $path, $checksum): array {
                $now = new UTCDateTime((int) now()->getTimestampMs());
                $updated = $context->collection(self::COLLECTION)->findOneAndUpdate(
                    [
                        '_id' => $userId,
                        'phase' => 'terminalized',
                        'state_revision' => $stateRevision,
                        'claim_token' => $claimToken,
                        'profile_descriptors' => [
                            '$elemMatch' => [
                                'profile_id' => $profileId,
                                'media_descriptors' => [
                                    '$elemMatch' => [
                                        'path' => $path,
                                        'checksum' => $checksum,
                                        'purged_at' => ['$exists' => false],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        '$set' => [
                            'profile_descriptors.$[profile].media_descriptors.$[media].purged_at' => $now,
                            'updated_at' => $now,
                        ],
                        '$inc' => ['state_revision' => 1],
                    ],
                    [
                        ...$context->rawOptions(),
                        'arrayFilters' => [
                            ['profile.profile_id' => $profileId],
                            [
                                'media.path' => $path,
                                'media.checksum' => $checksum,
                                'media.purged_at' => ['$exists' => false],
                            ],
                        ],
                        'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                    ],
                );
                $updated = $this->documentToArray($updated);
                if ($updated !== null) {
                    return $updated;
                }

                $current = $this->attempt($context, $userId);
                if ($current !== null && $this->isDescriptorPurged($current, $profileId, $path, $checksum)) {
                    return $current;
                }

                throw new ConcurrencyConflictException('Account Profile deletion media purge state transition was rejected.');
            },
        );
    }

    /** @param array<string, mixed> $attempt */
    private function isDescriptorPurged(array $attempt, string $profileId, string $path, string $checksum): bool
    {
        foreach ((array) ($attempt['profile_descriptors'] ?? []) as $profile) {
            if (! is_array($profile) || ! hash_equals($profileId, trim((string) ($profile['profile_id'] ?? '')))) {
                continue;
            }
            foreach ((array) ($profile['media_descriptors'] ?? []) as $media) {
                if (! is_array($media)) {
                    continue;
                }
                if (
                    hash_equals($path, trim((string) ($media['path'] ?? '')))
                    && hash_equals($checksum, trim((string) ($media['checksum'] ?? '')))
                ) {
                    return array_key_exists('purged_at', $media) && $media['purged_at'] !== null;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function documentToArray(mixed $value): ?array
    {
        $value = $this->normalizeValue($value);

        return is_array($value) ? $value : null;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $entry) {
            $value[$key] = $this->normalizeValue($entry);
        }

        return $value;
    }

    /** @param list<string> $candidateAccountIds
     * @return list<array{account_id:string}>
     */
    private function captureAccountGates(
        AccountProfileTransactionContext $context,
        string $userId,
        array $candidateAccountIds,
    ): array {
        $accountIds = Account::query()
            ->whereIn('_id', $candidateAccountIds)
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('ownership_state', 'unmanaged')
            ->orderBy('_id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
        if ($accountIds === []) {
            return [];
        }

        $result = $context->collection((new Account)->getTable())
            ->updateMany(
                [
                    '_id' => ['$in' => array_map(static fn (string $id): ObjectId => new ObjectId($id), $accountIds)],
                    'created_by' => $userId,
                    'created_by_type' => 'tenant',
                    'ownership_state' => 'unmanaged',
                    'deleted_at' => null,
                    '$or' => [
                        ['account_profile_deletion_gate' => null],
                        [
                            'account_profile_deletion_gate.attempt_id' => $userId,
                            'account_profile_deletion_gate.attempt_generation' => 1,
                        ],
                    ],
                ],
                [
                    '$set' => [
                        'account_profile_deletion_gate' => [
                            'attempt_id' => $userId,
                            'attempt_generation' => 1,
                        ],
                        'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
                    ],
                ],
                $context->rawOptions(),
            );
        if ($result->getMatchedCount() !== count($accountIds)) {
            throw new ConcurrencyConflictException('Account Profile deletion is already gated by another attempt.');
        }

        return array_map(
            static fn (string $accountId): array => ['account_id' => $accountId],
            $accountIds,
        );
    }
}
