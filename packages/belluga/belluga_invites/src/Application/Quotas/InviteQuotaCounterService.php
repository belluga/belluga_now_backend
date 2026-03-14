<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Quotas;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

class InviteQuotaCounterService
{
    /**
     * @return array{allowed:bool,current_count:int}
     */
    public function reserve(
        string $scope,
        string $scopeId,
        string $windowKey,
        int $limit,
        Carbon $now,
    ): array {
        if ($limit <= 0) {
            return [
                'allowed' => false,
                'current_count' => 0,
            ];
        }

        $collection = DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('invite_quota_counters');

        $timestamp = new UTCDateTime((int) $now->getTimestampMs());

        try {
            $document = $collection->findOneAndUpdate(
                [
                    'scope' => $scope,
                    'scope_id' => $scopeId,
                    'window_key' => $windowKey,
                    'count' => ['$lt' => $limit],
                ],
                [
                    '$inc' => ['count' => 1],
                    '$set' => ['updated_at' => $timestamp],
                    '$setOnInsert' => [
                        'scope' => $scope,
                        'scope_id' => $scopeId,
                        'window_key' => $windowKey,
                        'created_at' => $timestamp,
                    ],
                ],
                [
                    'upsert' => true,
                    'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                ],
            );

            if ($document !== null) {
                return [
                    'allowed' => true,
                    'current_count' => (int) ($document['count'] ?? 0),
                ];
            }
        } catch (Throwable $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }
        }

        return [
            'allowed' => false,
            'current_count' => $this->currentCount($collection, $scope, $scopeId, $windowKey),
        ];
    }

    private function currentCount(
        Collection $collection,
        string $scope,
        string $scopeId,
        string $windowKey,
    ): int {
        $document = $collection->findOne(
            [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'window_key' => $windowKey,
            ],
            ['projection' => ['count' => 1]],
        );

        return (int) ($document['count'] ?? 0);
    }

    private function isDuplicateKey(Throwable $exception): bool
    {
        if ((int) $exception->getCode() === 11000) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'duplicate key');
    }
}
