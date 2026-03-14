<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Mutations;

use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Throwable;

class InviteCommandIdempotencyService
{
    /**
     * @param  array<string, mixed>  $fingerprintPayload
     * @param  callable():array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function runWithReplay(
        string $command,
        string $actorUserId,
        ?string $idempotencyKey,
        array $fingerprintPayload,
        callable $callback,
    ): array {
        $normalizedKey = trim((string) ($idempotencyKey ?? ''));
        if ($normalizedKey === '') {
            return $callback();
        }

        $fingerprint = $this->fingerprint($fingerprintPayload);
        $collection = DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('invite_command_idempotencies');
        $filter = [
            'command' => $command,
            'actor_user_id' => $actorUserId,
            'idempotency_key' => $normalizedKey,
        ];
        $timestamp = new UTCDateTime((int) Carbon::now()->getTimestampMs());

        $upsertResult = $collection->updateOne(
            $filter,
            [
                '$setOnInsert' => [
                    'command' => $command,
                    'actor_user_id' => $actorUserId,
                    'idempotency_key' => $normalizedKey,
                    'command_fingerprint' => $fingerprint,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            ['upsert' => true],
        );

        if ($upsertResult->getUpsertedCount() === 1) {
            try {
                $response = $callback();
            } catch (Throwable $throwable) {
                $collection->deleteOne($filter);
                throw $throwable;
            }

            $collection->updateOne(
                $filter,
                [
                    '$set' => [
                        'response_payload' => $response,
                        'updated_at' => new UTCDateTime((int) Carbon::now()->getTimestampMs()),
                    ],
                ],
            );

            return $response;
        }

        return $this->replayExisting(
            collection: $collection,
            filter: $filter,
            expectedFingerprint: $fingerprint,
        );
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    private function replayExisting(
        Collection $collection,
        array $filter,
        string $expectedFingerprint,
    ): array {
        $existing = $collection->findOne($filter);
        if ($existing === null) {
            throw new InviteDomainException('idempotency_unavailable', 409);
        }

        $storedFingerprint = (string) ($existing['command_fingerprint'] ?? '');
        if ($storedFingerprint !== '' && $storedFingerprint !== $expectedFingerprint) {
            throw new InviteDomainException(
                'idempotency_key_reused_with_different_payload',
                409,
                'Idempotency key was already used with another payload.'
            );
        }

        $payload = $this->normalizePayload($existing['response_payload'] ?? null);
        if ($payload !== null) {
            return $payload;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            usleep(50000);
            $retry = $collection->findOne($filter);
            if ($retry === null) {
                break;
            }
            $retryPayload = $this->normalizePayload($retry['response_payload'] ?? null);
            if ($retryPayload !== null) {
                return $retryPayload;
            }
        }

        throw new InviteDomainException(
            'idempotency_in_progress',
            409,
            'A command with the same idempotency key is still in progress.'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePayload(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if ($raw instanceof \MongoDB\Model\BSONDocument || $raw instanceof \MongoDB\Model\BSONArray) {
            try {
                $decoded = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
