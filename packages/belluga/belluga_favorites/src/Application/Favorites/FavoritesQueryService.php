<?php

declare(strict_types=1);

namespace Belluga\Favorites\Application\Favorites;

use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class FavoritesQueryService
{
    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly FavoritesRegistryContract $registry,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function listForOwner(
        string $ownerUserId,
        int $page,
        int $pageSize,
        ?string $registryKey = null,
        ?string $targetType = null,
    ): array {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
        $skip = ($resolvedPage - 1) * $resolvedPageSize;
        $limit = $resolvedPageSize + 1;

        $effectiveRegistryKey = $registryKey;
        if (! is_string($effectiveRegistryKey) || trim($effectiveRegistryKey) === '') {
            $effectiveRegistryKey = (string) config('favorites.default_registry_key', 'account_profile');
        }

        $definition = $this->registry->find($effectiveRegistryKey);
        if (! $definition) {
            return [
                'items' => [],
                'has_more' => false,
            ];
        }

        $effectiveTargetType = $targetType;
        if (! is_string($effectiveTargetType) || trim($effectiveTargetType) === '') {
            $effectiveTargetType = $definition->targetType;
        }

        $pipeline = [
            [
                '$match' => [
                    'owner_user_id' => $ownerUserId,
                    'registry_key' => $definition->registryKey,
                    'target_type' => $effectiveTargetType,
                ],
            ],
            [
                '$lookup' => [
                    'from' => $definition->resolvedSnapshotCollection(),
                    'let' => [
                        'target_id' => '$target_id',
                        'registry_key' => '$registry_key',
                        'target_type' => '$target_type',
                    ],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$expr' => [
                                    '$and' => [
                                        ['$eq' => ['$target_id', '$$target_id']],
                                        ['$eq' => ['$registry_key', '$$registry_key']],
                                        ['$eq' => ['$target_type', '$$target_type']],
                                    ],
                                ],
                            ],
                        ],
                        ['$limit' => 1],
                    ],
                    'as' => 'snapshot_doc',
                ],
            ],
            [
                '$unwind' => [
                    'path' => '$snapshot_doc',
                    'preserveNullAndEmptyArrays' => true,
                ],
            ],
            [
                '$addFields' => [
                    'sort_block' => [
                        '$cond' => [
                            ['$eq' => [['$type' => '$snapshot_doc.next_event_occurrence_at'], 'date']],
                            0,
                            [
                                '$cond' => [
                                    ['$eq' => [['$type' => '$snapshot_doc.last_event_occurrence_at'], 'date']],
                                    1,
                                    2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                '$sort' => [
                    'sort_block' => 1,
                    'snapshot_doc.next_event_occurrence_at' => 1,
                    'snapshot_doc.last_event_occurrence_at' => -1,
                    'favorited_at' => -1,
                    '_id' => 1,
                ],
            ],
            ['$skip' => $skip],
            ['$limit' => $limit],
        ];

        $cursor = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('favorite_edges')
            ->aggregate($pipeline);

        $rows = iterator_to_array($cursor, false);
        $hasMore = count($rows) > $resolvedPageSize;
        $rows = array_slice($rows, 0, $resolvedPageSize);

        return [
            'items' => array_map(fn (mixed $row): array => $this->mapItem($row), $rows),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItem(mixed $row): array
    {
        $row = $this->toArray($row);
        $snapshotDoc = $this->toArray($row['snapshot_doc'] ?? []);
        $target = $this->toArray($snapshotDoc['target'] ?? []);
        $snapshot = $this->toArray($snapshotDoc['snapshot'] ?? []);
        $navigation = $this->toArray($snapshotDoc['navigation'] ?? []);

        $nextOccurrenceId = $snapshotDoc['next_event_occurrence_id'] ?? ($snapshot['next_event_occurrence_id'] ?? null);
        $nextOccurrenceAt = $snapshotDoc['next_event_occurrence_at'] ?? ($snapshot['next_event_occurrence_at'] ?? null);
        $lastOccurrenceAt = $snapshotDoc['last_event_occurrence_at'] ?? ($snapshot['last_event_occurrence_at'] ?? null);

        return [
            'favorite_id' => $this->stringifyId($row['_id'] ?? null),
            'registry_key' => (string) ($row['registry_key'] ?? ''),
            'target_type' => (string) ($row['target_type'] ?? ''),
            'target_id' => (string) ($row['target_id'] ?? ''),
            'favorited_at' => $this->formatDate($row['favorited_at'] ?? null),
            'target' => [
                'id' => (string) ($target['id'] ?? $row['target_id'] ?? ''),
                'slug' => isset($target['slug']) ? (string) $target['slug'] : '',
                'display_name' => isset($target['display_name']) ? (string) $target['display_name'] : '',
                'avatar_url' => $target['avatar_url'] ?? null,
            ],
            'snapshot' => [
                'next_event_occurrence_id' => $nextOccurrenceId ? (string) $nextOccurrenceId : null,
                'next_event_occurrence_at' => $this->formatDate($nextOccurrenceAt),
                'last_event_occurrence_at' => $this->formatDate($lastOccurrenceAt),
            ],
            'navigation' => [
                'kind' => isset($navigation['kind']) ? (string) $navigation['kind'] : (string) ($row['target_type'] ?? ''),
                'target_slug' => isset($navigation['target_slug']) ? (string) $navigation['target_slug'] : (isset($target['slug']) ? (string) $target['slug'] : null),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    private function stringifyId(mixed $value): string
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format(DATE_ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
