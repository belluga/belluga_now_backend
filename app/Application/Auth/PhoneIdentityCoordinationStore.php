<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

final class PhoneIdentityCoordinationStore
{
    private const string COLLECTION = 'phone_identity_coordination_leases';

    /**
     * @param  array<int, string>  $phoneHashes
     *
     * @throws ConcurrencyConflictException
     */
    public function acquire(array $phoneHashes, string $operation): PhoneIdentityCoordinationLease
    {
        $phoneHashes = $this->normalizedPhoneHashes($phoneHashes);
        if ($phoneHashes === []) {
            return new PhoneIdentityCoordinationLease([], '', $operation);
        }

        $ownerToken = (string) Str::uuid();
        $deadline = Carbon::now()->addSeconds($this->acquireTimeoutSeconds());

        do {
            $acquired = [];
            $now = Carbon::now();

            try {
                foreach ($phoneHashes as $phoneHash) {
                    if (! $this->tryAcquireSingle($phoneHash, $ownerToken, $operation, $now)) {
                        throw new ConcurrencyConflictException('Phone identity coordination is busy.');
                    }

                    $acquired[] = $phoneHash;
                }

                return new PhoneIdentityCoordinationLease($phoneHashes, $ownerToken, $operation);
            } catch (ConcurrencyConflictException $exception) {
                $this->release(new PhoneIdentityCoordinationLease($acquired, $ownerToken, $operation));

                if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                    throw $exception;
                }

                usleep($this->retryDelayMilliseconds() * 1000);
            }
        } while (true);
    }

    /**
     * @throws ConcurrencyConflictException
     */
    public function assertStillOwned(PhoneIdentityCoordinationLease $lease): void
    {
        if ($lease->phoneHashes === []) {
            return;
        }

        $nowUtc = $this->toUtcDateTime(Carbon::now());

        foreach ($lease->phoneHashes as $phoneHash) {
            $owned = $this->collection()->countDocuments([
                '_id' => $phoneHash,
                'owner_token' => $lease->ownerToken,
                'lease_expires_at' => ['$gt' => $nowUtc],
            ], ['limit' => 1]);

            if ($owned !== 1) {
                throw new ConcurrencyConflictException('Phone identity coordination lease was lost.');
            }
        }
    }

    public function release(PhoneIdentityCoordinationLease $lease): void
    {
        if ($lease->phoneHashes === [] || $lease->ownerToken === '') {
            return;
        }

        $this->collection()->deleteMany([
            '_id' => ['$in' => $lease->phoneHashes],
            'owner_token' => $lease->ownerToken,
        ]);
    }

    private function tryAcquireSingle(string $phoneHash, string $ownerToken, string $operation, Carbon $now): bool
    {
        $nowUtc = $this->toUtcDateTime($now);
        $expiresAtUtc = $this->toUtcDateTime($now->copy()->addSeconds($this->leaseTtlSeconds()));

        try {
            $document = $this->collection()->findOneAndUpdate(
                [
                    '_id' => $phoneHash,
                    '$or' => [
                        ['lease_expires_at' => ['$lte' => $nowUtc]],
                        ['owner_token' => $ownerToken],
                    ],
                ],
                [
                    '$set' => [
                        'owner_token' => $ownerToken,
                        'operation' => $operation,
                        'lease_expires_at' => $expiresAtUtc,
                        'updated_at' => $nowUtc,
                    ],
                    '$setOnInsert' => [
                        'created_at' => $nowUtc,
                    ],
                ],
                [
                    'upsert' => true,
                    'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                ],
            );
        } catch (Throwable $exception) {
            if ($this->isDuplicateKey($exception)) {
                return false;
            }

            throw $exception;
        }

        return isset($document['_id']) && (string) $document['_id'] === $phoneHash
            && (string) ($document['owner_token'] ?? '') === $ownerToken;
    }

    /**
     * @param  array<int, string>  $phoneHashes
     * @return array<int, string>
     */
    private function normalizedPhoneHashes(array $phoneHashes): array
    {
        return collect($phoneHashes)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function leaseTtlSeconds(): int
    {
        $value = (int) (getenv('BELLUGA_TEST_PHONE_IDENTITY_LEASE_TTL_SECONDS') ?: 5);

        return max(1, $value);
    }

    private function acquireTimeoutSeconds(): int
    {
        $value = (int) (getenv('BELLUGA_TEST_PHONE_IDENTITY_ACQUIRE_TIMEOUT_SECONDS') ?: 5);

        return max(1, $value);
    }

    private function retryDelayMilliseconds(): int
    {
        $value = (int) (getenv('BELLUGA_TEST_PHONE_IDENTITY_RETRY_DELAY_MS') ?: 50);

        return max(10, $value);
    }

    private function collection(): \MongoDB\Collection
    {
        return DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection(self::COLLECTION);
    }

    private function toUtcDateTime(Carbon $value): UTCDateTime
    {
        return new UTCDateTime((int) $value->getTimestampMs());
    }

    private function isDuplicateKey(Throwable $exception): bool
    {
        if ((int) $exception->getCode() === 11000) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'duplicate key')
            || str_contains($exception->getMessage(), 'E11000');
    }
}
