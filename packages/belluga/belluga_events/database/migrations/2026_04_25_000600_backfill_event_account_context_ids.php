<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\ObjectId;

return new class extends Migration
{
    private const EVENT_INDEX = 'idx_events_account_context_management_v1';
    private const OCCURRENCE_INDEX = 'idx_event_occurrences_account_context_management_v1';

    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        $events = DB::connection('tenant')->getCollection('events');
        $occurrences = Schema::hasTable('event_occurrences')
            ? DB::connection('tenant')->getCollection('event_occurrences')
            : null;

        $profileAccountIds = $this->resolveProfileAccountIds(
            $this->collectReferencedProfileIds($events, $occurrences)
        );

        $accountIdsByEventId = [];
        if ($occurrences !== null) {
            foreach ($occurrences->find([], [
                'projection' => [
                    '_id' => 1,
                    'event_id' => 1,
                    'account_context_ids' => 1,
                    'event_parties' => 1,
                    'own_event_parties' => 1,
                    'place_ref' => 1,
                    'programming_items' => 1,
                ],
            ]) as $occurrence) {
                $accountIds = $this->accountIdsForProfileIds(
                    $this->profileIdsFromOccurrence($occurrence),
                    $profileAccountIds
                );
                $accountIds = $this->mergeStringLists(
                    $this->normalizeStringList($occurrence['account_context_ids'] ?? []),
                    $accountIds
                );

                $eventId = trim((string) ($occurrence['event_id'] ?? ''));
                if ($eventId !== '') {
                    $accountIdsByEventId[$eventId] = $this->mergeStringLists(
                        $accountIdsByEventId[$eventId] ?? [],
                        $accountIds
                    );
                }

                $occurrences->updateOne(
                    ['_id' => $occurrence['_id']],
                    ['$set' => ['account_context_ids' => $accountIds]]
                );
            }
        }

        foreach ($events->find([], [
            'projection' => [
                '_id' => 1,
                'account_context_ids' => 1,
                'event_parties' => 1,
                'place_ref' => 1,
            ],
        ]) as $event) {
            $eventId = isset($event['_id']) ? (string) $event['_id'] : '';
            $accountIds = $this->accountIdsForProfileIds(
                $this->profileIdsFromEvent($event),
                $profileAccountIds
            );
            $accountIds = $this->mergeStringLists(
                $this->normalizeStringList($event['account_context_ids'] ?? []),
                $accountIds,
                $eventId === '' ? [] : ($accountIdsByEventId[$eventId] ?? [])
            );

            $events->updateOne(
                ['_id' => $event['_id']],
                ['$set' => ['account_context_ids' => $accountIds]]
            );
        }

        $this->createIndexes();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('events', self::EVENT_INDEX);
        $this->dropIndexIfExists('event_occurrences', self::OCCURRENCE_INDEX);

        if (Schema::hasTable('events')) {
            DB::connection('tenant')->getCollection('events')
                ->updateMany([], ['$unset' => ['account_context_ids' => '']]);
        }

        if (Schema::hasTable('event_occurrences')) {
            DB::connection('tenant')->getCollection('event_occurrences')
                ->updateMany([], ['$unset' => ['account_context_ids' => '']]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectReferencedProfileIds($events, $occurrences): array
    {
        $profileIds = [];

        foreach ($events->find([], [
            'projection' => [
                'event_parties' => 1,
                'place_ref' => 1,
            ],
        ]) as $event) {
            $profileIds = $this->mergeStringLists($profileIds, $this->profileIdsFromEvent($event));
        }

        if ($occurrences !== null) {
            foreach ($occurrences->find([], [
                'projection' => [
                    'event_parties' => 1,
                    'own_event_parties' => 1,
                    'place_ref' => 1,
                    'programming_items' => 1,
                ],
            ]) as $occurrence) {
                $profileIds = $this->mergeStringLists($profileIds, $this->profileIdsFromOccurrence($occurrence));
            }
        }

        return $profileIds;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, string>
     */
    private function resolveProfileAccountIds(array $profileIds): array
    {
        if ($profileIds === [] || ! Schema::hasTable('account_profiles')) {
            return [];
        }

        $profiles = DB::connection('tenant')->getCollection('account_profiles');
        $map = [];

        foreach (array_chunk($profileIds, 500) as $chunk) {
            $lookupIds = $this->profileLookupIds($chunk);
            if ($lookupIds === []) {
                continue;
            }

            foreach ($profiles->find([
                '_id' => ['$in' => $lookupIds],
            ], [
                'projection' => ['_id' => 1, 'account_id' => 1],
            ]) as $profile) {
                $profileId = isset($profile['_id']) ? (string) $profile['_id'] : '';
                $accountId = trim((string) ($profile['account_id'] ?? ''));
                if ($profileId !== '' && $accountId !== '') {
                    $map[$profileId] = $accountId;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @param  array<string, string>  $profileAccountIds
     * @return array<int, string>
     */
    private function accountIdsForProfileIds(array $profileIds, array $profileAccountIds): array
    {
        $accountIds = [];
        foreach ($profileIds as $profileId) {
            $accountId = $profileAccountIds[$profileId] ?? null;
            if ($accountId !== null && $accountId !== '') {
                $accountIds[] = $accountId;
            }
        }

        return array_values(array_unique($accountIds));
    }

    /**
     * @return array<int, string>
     */
    private function profileIdsFromEvent(mixed $event): array
    {
        $payload = $this->normalizeArray($event);
        $profileIds = [];

        foreach ($this->normalizeArray($payload['event_parties'] ?? []) as $party) {
            $partyPayload = $this->normalizeArray($party);
            $profileId = trim((string) ($partyPayload['party_ref_id'] ?? ''));
            if ($profileId !== '') {
                $profileIds[] = $profileId;
            }
        }

        $placeRefId = $this->profileIdFromPlaceRef(
            $this->normalizeNullableArray($payload['place_ref'] ?? null)
        );
        if ($placeRefId !== null) {
            $profileIds[] = $placeRefId;
        }

        return array_values(array_unique($profileIds));
    }

    /**
     * @return array<int, string>
     */
    private function profileIdsFromOccurrence(mixed $occurrence): array
    {
        $payload = $this->normalizeArray($occurrence);
        $profileIds = [];

        foreach (['event_parties', 'own_event_parties'] as $field) {
            foreach ($this->normalizeArray($payload[$field] ?? []) as $party) {
                $partyPayload = $this->normalizeArray($party);
                $profileId = trim((string) ($partyPayload['party_ref_id'] ?? ''));
                if ($profileId !== '') {
                    $profileIds[] = $profileId;
                }
            }
        }

        $placeRefId = $this->profileIdFromPlaceRef(
            $this->normalizeNullableArray($payload['place_ref'] ?? null)
        );
        if ($placeRefId !== null) {
            $profileIds[] = $placeRefId;
        }

        foreach ($this->normalizeArray($payload['programming_items'] ?? []) as $item) {
            $itemPayload = $this->normalizeArray($item);
            foreach ($this->normalizeArray($itemPayload['account_profile_ids'] ?? []) as $profileId) {
                $normalized = trim((string) $profileId);
                if ($normalized !== '') {
                    $profileIds[] = $normalized;
                }
            }

            $programmingPlaceRefId = $this->profileIdFromPlaceRef(
                $this->normalizeNullableArray($itemPayload['place_ref'] ?? null)
            );
            if ($programmingPlaceRefId !== null) {
                $profileIds[] = $programmingPlaceRefId;
            }
        }

        return array_values(array_unique($profileIds));
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeNullableArray(mixed $value): ?array
    {
        $normalized = $this->normalizeArray($value);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = $this->normalizeArray($value);
        $normalized = [];

        foreach ($items as $item) {
            $string = trim((string) $item);
            if ($string !== '') {
                $normalized[] = $string;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function profileIdFromPlaceRef(?array $placeRef): ?string
    {
        if ($placeRef === null) {
            return null;
        }

        $type = trim((string) ($placeRef['type'] ?? ''));
        if ($type !== 'account_profile' && $type !== 'venue') {
            return null;
        }

        $id = trim((string) ($placeRef['id'] ?? ($placeRef['_id'] ?? '')));

        return $id === '' ? null : $id;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, mixed>
     */
    private function profileLookupIds(array $profileIds): array
    {
        $lookupIds = [];
        $seen = [];

        foreach ($profileIds as $profileId) {
            $this->appendUniqueLookupId($lookupIds, $seen, $profileId);
            if (preg_match('/^[a-f0-9]{24}$/i', $profileId) === 1) {
                $this->appendUniqueLookupId($lookupIds, $seen, new ObjectId($profileId));
            }
        }

        return $lookupIds;
    }

    /**
     * @param  array<int, mixed>  $lookupIds
     * @param  array<string, bool>  $seen
     */
    private function appendUniqueLookupId(array &$lookupIds, array &$seen, mixed $value): void
    {
        $key = $value instanceof ObjectId
            ? 'oid:'.(string) $value
            : 'scalar:'.(string) $value;

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $lookupIds[] = $value;
    }

    /**
     * @param  array<int, string>  ...$lists
     * @return array<int, string>
     */
    private function mergeStringLists(array ...$lists): array
    {
        $merged = [];

        foreach ($lists as $list) {
            foreach ($list as $item) {
                $normalized = trim((string) $item);
                if ($normalized !== '') {
                    $merged[] = $normalized;
                }
            }
        }

        return array_values(array_unique($merged));
    }

    private function createIndexes(): void
    {
        $this->dropIndexIfExists('events', self::EVENT_INDEX);
        DB::connection('tenant')->getCollection('events')->createIndex(
            [
                'account_context_ids' => 1,
                'deleted_at' => 1,
                'date_time_start' => 1,
                '_id' => -1,
            ],
            ['name' => self::EVENT_INDEX]
        );

        if (! Schema::hasTable('event_occurrences')) {
            return;
        }

        $this->dropIndexIfExists('event_occurrences', self::OCCURRENCE_INDEX);
        DB::connection('tenant')->getCollection('event_occurrences')->createIndex(
            [
                'account_context_ids' => 1,
                'deleted_at' => 1,
                'is_event_published' => 1,
                'starts_at' => 1,
                'event_id' => 1,
            ],
            ['name' => self::OCCURRENCE_INDEX]
        );
    }

    private function dropIndexIfExists(string $collectionName, string $indexName): void
    {
        if (! Schema::hasTable($collectionName)) {
            return;
        }

        try {
            DB::connection('tenant')->getCollection($collectionName)->dropIndex($indexName);
        } catch (Throwable) {
            // Index may not exist on partially migrated local/test databases.
        }
    }
};
