<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use App\Models\Landlord\Tenant;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use RuntimeException;

final class EventProfileGroupMemberStore
{
    public const COLLECTION = 'event_profile_group_members';

    private const DOC_TYPE_HEAD = 'group_head';

    private const DOC_TYPE_MEMBER = 'member_row';

    private const OWNER_EVENT = 'event';

    private const OWNER_OCCURRENCE = 'occurrence';

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array{id:string,label:string,order:int}>
     */
    public function metadataOnly(array $groups): array
    {
        return array_values(array_map(
            static fn (array $group): array => [
                'id' => trim((string) ($group['id'] ?? '')),
                'label' => (string) ($group['label'] ?? ''),
                'order' => (int) ($group['order'] ?? 0),
            ],
            array_values(array_filter($groups, static fn (array $group): bool => trim((string) ($group['id'] ?? '')) !== ''))
        ));
    }

    public function materializeLegacyIfNeeded(Event $event, bool $includeTrashedOccurrences = false): void
    {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            return;
        }

        $existing = $this->collection()->countDocuments([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
        ]);

        if ($existing > 0) {
            return;
        }

        $rows = $this->rowsForOwner(
            self::OWNER_EVENT,
            $eventId,
            $eventId,
            $this->normalizeGroups($event->profile_groups ?? [])
        );

        $occurrences = $includeTrashedOccurrences
            ? EventOccurrence::withTrashed()->where('event_id', $eventId)->get()
            : EventOccurrence::query()->where('event_id', $eventId)->get();

        foreach ($occurrences as $occurrence) {
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($occurrenceId === '') {
                continue;
            }

            $rows = array_merge(
                $rows,
                $this->rowsForOwner(
                    self::OWNER_OCCURRENCE,
                    $occurrenceId,
                    $eventId,
                    $this->normalizeGroups($occurrence->own_profile_groups ?? [])
                )
            );
        }

        if ($rows !== []) {
            $this->collection()->insertMany($rows);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    public function syncEventGroups(Event $event, array $groups): void
    {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            return;
        }

        $this->replaceOwnerRows(
            self::OWNER_EVENT,
            $eventId,
            $eventId,
            $groups,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    public function syncOccurrenceGroups(string $eventId, EventOccurrence $occurrence, array $groups): void
    {
        $occurrenceId = trim((string) $occurrence->getKey());
        if ($occurrenceId === '') {
            return;
        }

        $this->replaceOwnerRows(
            self::OWNER_OCCURRENCE,
            $occurrenceId,
            $eventId,
            $groups,
        );
    }

    public function purgeByEventId(string $eventId): void
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return;
        }

        $this->collection()->deleteMany([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
        ]);
    }

    /**
     * @return array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     */
    public function inflateGroupsWithMembers(mixed $rawGroups, string $ownerType, string $ownerId): array
    {
        $groups = $this->normalizeGroups($rawGroups);
        if ($groups === []) {
            return [];
        }

        $membersByGroup = $this->memberIdsByGroup($ownerType, $ownerId);

        return array_values(array_map(function (array $group) use ($membersByGroup): array {
            $groupId = (string) ($group['id'] ?? '');
            $memberIds = $membersByGroup[$groupId] ?? ($group['account_profile_ids'] ?? []);

            return [
                'id' => $groupId,
                'label' => (string) ($group['label'] ?? ''),
                'order' => (int) ($group['order'] ?? 0),
                'account_profile_ids' => array_values(array_unique(array_filter(array_map(
                    static fn (mixed $memberId): string => trim((string) $memberId),
                    $memberIds
                )))),
            ];
        }, $groups));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function memberIdsByGroup(string $ownerType, string $ownerId): array
    {
        $rows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $this->tenantId(),
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'sort' => ['group_id' => 1, 'relation_order' => 1, '_id' => 1],
            ],
        ));

        $membersByGroup = [];
        foreach ($rows as $row) {
            $document = $this->documentToArray($row);
            $groupId = trim((string) ($document['group_id'] ?? ''));
            $memberId = trim((string) ($document['member_profile_id'] ?? ''));
            if ($groupId === '' || $memberId === '') {
                continue;
            }

            $membersByGroup[$groupId] ??= [];
            if (! in_array($memberId, $membersByGroup[$groupId], true)) {
                $membersByGroup[$groupId][] = $memberId;
            }
        }

        return $membersByGroup;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function replaceOwnerRows(string $ownerType, string $ownerId, string $eventId, array $groups): void
    {
        $filter = [
            'tenant_id' => $this->tenantId(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'event_id' => $eventId,
        ];

        $this->collection()->deleteMany($filter);

        $rows = $this->rowsForOwner($ownerType, $ownerId, $eventId, $groups);
        if ($rows !== []) {
            $this->collection()->insertMany($rows);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function rowsForOwner(string $ownerType, string $ownerId, string $eventId, array $groups): array
    {
        $tenantId = $this->tenantId();
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $rows = [];

        foreach ($groups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $rows[] = [
                '_id' => $this->headId($ownerType, $ownerId, $groupId),
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'group_id' => $groupId,
                'group_label' => (string) ($group['label'] ?? ''),
                'group_order' => (int) ($group['order'] ?? 0),
                'doc_type' => self::DOC_TYPE_HEAD,
                'updated_at' => $now,
            ];

            foreach (array_values($group['account_profile_ids'] ?? []) as $position => $memberId) {
                $memberId = trim((string) $memberId);
                if ($memberId === '') {
                    continue;
                }

                $rows[] = [
                    '_id' => $this->memberId($ownerType, $ownerId, $groupId, $memberId),
                    'tenant_id' => $tenantId,
                    'event_id' => $eventId,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'group_id' => $groupId,
                    'member_profile_id' => $memberId,
                    'relation_order' => $position,
                    'doc_type' => self::DOC_TYPE_MEMBER,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     */
    private function normalizeGroups(mixed $rawGroups): array
    {
        $rows = $this->normalizeArray($rawGroups);
        $groups = [];

        foreach ($rows as $index => $row) {
            $payload = $this->normalizeArray($row);
            $label = trim((string) ($payload['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = trim((string) ($payload['id'] ?? $payload['key'] ?? ''));
            if ($id === '') {
                $id = 'group-'.$index;
            }

            $memberIds = [];
            foreach ($this->normalizeArray($payload['account_profile_ids'] ?? $payload['profile_ids'] ?? []) as $rawMemberId) {
                $memberId = trim((string) $rawMemberId);
                if ($memberId !== '' && ! in_array($memberId, $memberIds, true)) {
                    $memberIds[] = $memberId;
                }
            }

            $groups[] = [
                '_source_index' => $index,
                'id' => $id,
                'label' => $label,
                'order' => isset($payload['order']) ? (int) ($payload['order']) : $index,
                'account_profile_ids' => $memberIds,
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group): array => [
                'id' => (string) $group['id'],
                'label' => (string) $group['label'],
                'order' => (int) $group['order'],
                'account_profile_ids' => array_values($group['account_profile_ids']),
            ],
            $groups
        ));
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentToArray(mixed $document): array
    {
        if ($document instanceof BSONDocument) {
            $document = $document->getArrayCopy();
        }

        if ($document instanceof BSONArray) {
            $document = $document->getArrayCopy();
        }

        return is_array($document) ? $document : [];
    }

    private function headId(string $ownerType, string $ownerId, string $groupId): string
    {
        return sprintf('event-group-head:%s:%s:%s', $ownerType, $ownerId, $groupId);
    }

    private function memberId(string $ownerType, string $ownerId, string $groupId, string $memberProfileId): string
    {
        return sprintf('event-group-member:%s:%s:%s:%s', $ownerType, $ownerId, $groupId, $memberProfileId);
    }

    private function tenantId(): string
    {
        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Current tenant is required for Event profile group member storage.');
        }

        return $tenantId;
    }

    private function collection(): \MongoDB\Collection
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for Event profile group member storage.');
        }

        return $connection->getDatabase()->selectCollection(self::COLLECTION);
    }
}
