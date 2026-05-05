<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Tenants\PhoneOtpChallenge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

class PhoneOtpChallengeStore
{
    public function issue(
        string $phone,
        string $phoneHash,
        string $codeHash,
        string $deliveryChannel,
        ?string $deliveryWebhookUrl,
        Carbon $expiresAt,
        Carbon $resendAvailableAt,
        int $maxAttempts,
        ?string $deviceName,
        Carbon $now,
    ): PhoneOtpChallenge {
        $collection = $this->collection();
        $nowUtc = $this->toUtcDateTime($now);
        $expiresAtUtc = $this->toUtcDateTime($expiresAt);
        $resendAvailableAtUtc = $this->toUtcDateTime($resendAvailableAt);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->expireStalePendingForPhone($phone, $now);

            $pending = $this->activePendingForPhone($phone, $now);
            if ($pending !== null) {
                $cooldownAt = $this->toCarbon($pending->resend_available_at);
                if ($cooldownAt !== null && $cooldownAt->isFuture()) {
                    throw new PhoneOtpCooldownException(max(1, (int) ceil($now->diffInSeconds($cooldownAt))));
                }
            }

            try {
                $document = $collection->findOneAndUpdate(
                    [
                        'phone' => $phone,
                        'status' => PhoneOtpChallenge::STATUS_PENDING,
                        'resend_available_at' => ['$lte' => $nowUtc],
                    ],
                    [
                        '$set' => [
                            'phone' => $phone,
                            'phone_hash' => $phoneHash,
                            'code_hash' => $codeHash,
                            'status' => PhoneOtpChallenge::STATUS_PENDING,
                            'delivery_channel' => $deliveryChannel,
                            'delivery_webhook_url' => $deliveryWebhookUrl,
                            'expires_at' => $expiresAtUtc,
                            'resend_available_at' => $resendAvailableAtUtc,
                            'attempts' => 0,
                            'max_attempts' => $maxAttempts,
                            'device_name' => $deviceName,
                            'requested_at' => $nowUtc,
                            'updated_at' => $nowUtc,
                        ],
                        '$unset' => [
                            'verified_at' => '',
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

                $challenge = $this->findByObjectId($document['_id'] ?? null);
                if ($challenge !== null) {
                    return $challenge;
                }
            } catch (Throwable $exception) {
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }

                $this->expireStalePendingForPhone($phone, $now);

                $pending = $this->activePendingForPhone($phone, $now);
                if ($pending !== null) {
                    $cooldownAt = $this->toCarbon($pending->resend_available_at);
                    if ($cooldownAt !== null && $cooldownAt->isFuture()) {
                        throw new PhoneOtpCooldownException(max(1, (int) ceil($now->diffInSeconds($cooldownAt))));
                    }
                }

                usleep(50_000);
            }
        }

        throw new \RuntimeException('Unable to issue a phone OTP challenge.');
    }

    public function consumePending(string $challengeId, string $phone, Carbon $now): bool
    {
        $objectId = $this->parseObjectId($challengeId);
        if ($objectId === null) {
            return false;
        }

        $result = $this->collection()->updateOne(
            [
                '_id' => $objectId,
                'phone' => $phone,
                'status' => PhoneOtpChallenge::STATUS_PENDING,
                'expires_at' => ['$gt' => $this->toUtcDateTime($now)],
            ],
            [
                '$set' => [
                    'status' => PhoneOtpChallenge::STATUS_VERIFIED,
                    'verified_at' => $this->toUtcDateTime($now),
                    'updated_at' => $this->toUtcDateTime($now),
                ],
            ],
        );

        return $result->getModifiedCount() === 1;
    }

    public function markExpiredIfPending(string $challengeId, Carbon $now): void
    {
        $objectId = $this->parseObjectId($challengeId);
        if ($objectId === null) {
            return;
        }

        $this->collection()->updateOne(
            [
                '_id' => $objectId,
                'status' => PhoneOtpChallenge::STATUS_PENDING,
            ],
            [
                '$set' => [
                    'status' => PhoneOtpChallenge::STATUS_EXPIRED,
                    'updated_at' => $this->toUtcDateTime($now),
                ],
            ],
        );
    }

    public function recordInvalidAttempt(string $challengeId, string $phone, int $maxAttempts, Carbon $now): string
    {
        $objectId = $this->parseObjectId($challengeId);
        if ($objectId === null) {
            return 'inactive';
        }

        $collection = $this->collection();
        $nowUtc = $this->toUtcDateTime($now);
        $lockThreshold = max(0, $maxAttempts - 1);

        $pending = $collection->findOneAndUpdate(
            [
                '_id' => $objectId,
                'phone' => $phone,
                'status' => PhoneOtpChallenge::STATUS_PENDING,
                'expires_at' => ['$gt' => $nowUtc],
                'attempts' => ['$lt' => $lockThreshold],
            ],
            [
                '$inc' => ['attempts' => 1],
                '$set' => ['updated_at' => $nowUtc],
            ],
            [
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ],
        );

        if ($pending !== null) {
            return PhoneOtpChallenge::STATUS_PENDING;
        }

        $locked = $collection->findOneAndUpdate(
            [
                '_id' => $objectId,
                'phone' => $phone,
                'status' => PhoneOtpChallenge::STATUS_PENDING,
                'expires_at' => ['$gt' => $nowUtc],
                'attempts' => ['$gte' => $lockThreshold],
            ],
            [
                '$inc' => ['attempts' => 1],
                '$set' => [
                    'status' => PhoneOtpChallenge::STATUS_LOCKED,
                    'updated_at' => $nowUtc,
                ],
            ],
            [
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ],
        );

        if ($locked !== null) {
            return PhoneOtpChallenge::STATUS_LOCKED;
        }

        return 'inactive';
    }

    public function pendingForPhone(string $phone): ?PhoneOtpChallenge
    {
        /** @var PhoneOtpChallenge|null $challenge */
        $challenge = PhoneOtpChallenge::query()
            ->where('phone', $phone)
            ->where('status', PhoneOtpChallenge::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->first();

        return $challenge;
    }

    public function activePendingForPhone(string $phone, Carbon $now): ?PhoneOtpChallenge
    {
        /** @var PhoneOtpChallenge|null $challenge */
        $challenge = PhoneOtpChallenge::query()
            ->where('phone', $phone)
            ->where('status', PhoneOtpChallenge::STATUS_PENDING)
            ->where('expires_at', '>', $now)
            ->orderByDesc('created_at')
            ->first();

        return $challenge;
    }

    private function collection(): \MongoDB\Collection
    {
        return DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('phone_otp_challenges');
    }

    private function expireStalePendingForPhone(string $phone, Carbon $now): void
    {
        $nowUtc = $this->toUtcDateTime($now);

        $this->collection()->updateMany(
            [
                'phone' => $phone,
                'status' => PhoneOtpChallenge::STATUS_PENDING,
                'expires_at' => ['$lte' => $nowUtc],
            ],
            [
                '$set' => [
                    'status' => PhoneOtpChallenge::STATUS_EXPIRED,
                    'updated_at' => $nowUtc,
                ],
            ],
        );
    }

    private function parseObjectId(string $challengeId): ?ObjectId
    {
        try {
            return new ObjectId($challengeId);
        } catch (Throwable) {
            return null;
        }
    }

    private function findByObjectId(mixed $objectId): ?PhoneOtpChallenge
    {
        if (! $objectId instanceof ObjectId) {
            return null;
        }

        /** @var PhoneOtpChallenge|null $challenge */
        $challenge = PhoneOtpChallenge::query()->find((string) $objectId);

        return $challenge;
    }

    private function toUtcDateTime(Carbon $value): UTCDateTime
    {
        return new UTCDateTime((int) $value->getTimestampMs());
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
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
