<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventProfileResolverContract;

class EventAccountContextResolver
{
    public function __construct(
        private readonly EventProfileResolverContract $eventProfileResolver,
    ) {}

    /**
     * @param  array<int, string>  $baseAccountContextIds
     * @param  array<int, array<string, mixed>>  $eventParties
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, string>
     */
    public function resolveForAggregate(
        array $baseAccountContextIds,
        array $eventParties,
        ?array $placeRef,
        array $occurrences
    ): array {
        $profileIds = $this->profileIdsFromParties($eventParties);
        $profileIds = $this->mergeStringLists($profileIds, $this->profileIdsFromPlaceRef($placeRef));

        foreach ($occurrences as $occurrence) {
            $occurrencePayload = $this->normalizeArray($occurrence);
            $profileIds = $this->mergeStringLists(
                $profileIds,
                $this->profileIdsFromParties($this->normalizeArray($occurrencePayload['event_parties'] ?? [])),
                $this->profileIdsFromProgrammingItems($this->normalizeArray($occurrencePayload['programming_items'] ?? []))
            );
        }

        return $this->resolveAccountIds($baseAccountContextIds, $profileIds);
    }

    /**
     * @param  array<int, string>  $baseAccountContextIds
     * @param  array<int, array<string, mixed>>  $eventParties
     * @param  array<int, array<string, mixed>>  $programmingItems
     * @return array<int, string>
     */
    public function resolveForOccurrence(
        array $baseAccountContextIds,
        array $eventParties,
        ?array $placeRef,
        array $programmingItems
    ): array {
        return $this->resolveAccountIds(
            $baseAccountContextIds,
            $this->mergeStringLists(
                $this->profileIdsFromParties($eventParties),
                $this->profileIdsFromPlaceRef($placeRef),
                $this->profileIdsFromProgrammingItems($programmingItems)
            )
        );
    }

    /**
     * @param  array<int, string>  $baseAccountContextIds
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    private function resolveAccountIds(array $baseAccountContextIds, array $profileIds): array
    {
        return $this->mergeStringLists(
            $baseAccountContextIds,
            $this->eventProfileResolver->resolveAccountIdsForProfileIds($profileIds)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, string>
     */
    private function profileIdsFromParties(array $eventParties): array
    {
        $profileIds = [];

        foreach ($eventParties as $party) {
            $partyRefId = trim((string) ($this->normalizeArray($party)['party_ref_id'] ?? ''));
            if ($partyRefId !== '') {
                $profileIds[] = $partyRefId;
            }
        }

        return array_values(array_unique($profileIds));
    }

    /**
     * @return array<int, string>
     */
    private function profileIdsFromPlaceRef(?array $placeRef): array
    {
        $profileId = $this->profileIdFromPlaceRef($placeRef);

        return $profileId === null ? [] : [$profileId];
    }

    /**
     * @param  array<int, array<string, mixed>>  $programmingItems
     * @return array<int, string>
     */
    private function profileIdsFromProgrammingItems(array $programmingItems): array
    {
        $profileIds = [];

        foreach ($programmingItems as $item) {
            $payload = $this->normalizeArray($item);
            foreach ($this->normalizeArray($payload['account_profile_ids'] ?? []) as $profileId) {
                $normalized = trim((string) $profileId);
                if ($normalized !== '') {
                    $profileIds[] = $normalized;
                }
            }

            $profileIds = $this->mergeStringLists(
                $profileIds,
                $this->profileIdsFromPlaceRef($this->normalizeNullableArray($payload['place_ref'] ?? null))
            );
        }

        return array_values(array_unique($profileIds));
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
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
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
     * @return array<string, mixed>|null
     */
    private function normalizeNullableArray(mixed $value): ?array
    {
        $normalized = $this->normalizeArray($value);

        return $normalized === [] ? null : $normalized;
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
}
