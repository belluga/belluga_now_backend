<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events\Concerns;

use Belluga\Events\Models\Tenants\Event;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

trait EventManagementPartiesAndMetadata
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $venue
     * @param  array<int, array<string, mixed>>  $artists
     * @return array<int, array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }>
     */
    private function resolveEventParties(
        array $payload,
        ?Event $existing,
        array $venue,
        array $artists,
        bool $venueSourceTouched,
        bool $artistsSourceTouched
    ): array {
        $existingRows = $this->normalizeEventPartiesMap($existing?->event_parties ?? []);
        $resolved = $existingRows;

        if ($venueSourceTouched) {
            foreach ($resolved as $key => $row) {
                if (($row['party_type'] ?? null) === 'venue') {
                    unset($resolved[$key]);
                }
            }
        }

        if ($artistsSourceTouched) {
            foreach ($resolved as $key => $row) {
                if (($row['party_type'] ?? null) === 'artist') {
                    unset($resolved[$key]);
                }
            }
        }

        $venueRefId = isset($venue['id']) ? trim((string) $venue['id']) : '';
        if ($venueRefId !== '') {
            $key = $this->eventPartyKey('venue', $venueRefId);
            $resolved[$key] = $this->buildEventPartyRow(
                'venue',
                $venueRefId,
                $venue,
                $existingRows[$key] ?? null,
                null,
                null,
                'event_parties'
            );
        }

        foreach ($artists as $artist) {
            if (! is_array($artist)) {
                continue;
            }

            $artistRefId = isset($artist['id']) ? trim((string) $artist['id']) : '';
            if ($artistRefId === '') {
                continue;
            }

            $key = $this->eventPartyKey('artist', $artistRefId);
            $resolved[$key] = $this->buildEventPartyRow(
                'artist',
                $artistRefId,
                $artist,
                $existingRows[$key] ?? null,
                null,
                null,
                'event_parties'
            );
        }

        if (array_key_exists('event_parties', $payload)) {
            $incomingRows = $payload['event_parties'];
            if (! is_array($incomingRows)) {
                throw ValidationException::withMessages([
                    'event_parties' => ['event_parties must be an array.'],
                ]);
            }

            foreach ($incomingRows as $index => $incomingRow) {
                if (! is_array($incomingRow)) {
                    throw ValidationException::withMessages([
                        "event_parties.{$index}" => ['event party payload must be an object.'],
                    ]);
                }

                $partyType = trim((string) ($incomingRow['party_type'] ?? ''));
                $partyRefId = trim((string) ($incomingRow['party_ref_id'] ?? ''));

                if ($partyType === '' || $partyRefId === '') {
                    throw ValidationException::withMessages([
                        "event_parties.{$index}" => ['party_type and party_ref_id are required.'],
                    ]);
                }

                $metadataOverride = isset($incomingRow['metadata']) && is_array($incomingRow['metadata'])
                    ? $incomingRow['metadata']
                    : null;
                $overrideCanEdit = null;
                if (isset($incomingRow['permissions']) && is_array($incomingRow['permissions']) && array_key_exists('can_edit', $incomingRow['permissions'])) {
                    $overrideCanEdit = (bool) $incomingRow['permissions']['can_edit'];
                }

                $key = $this->eventPartyKey($partyType, $partyRefId);
                $resolved[$key] = $this->buildEventPartyRow(
                    $partyType,
                    $partyRefId,
                    $metadataOverride ?? [],
                    $resolved[$key] ?? ($existingRows[$key] ?? null),
                    $overrideCanEdit,
                    $metadataOverride,
                    "event_parties.{$index}.party_type"
                );
            }
        }

        return array_values($resolved);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }|null $existingRow
     * @param  array<string, mixed>|null  $metadataOverride
     * @return array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }
     */
    private function buildEventPartyRow(
        string $partyType,
        string $partyRefId,
        array $source,
        ?array $existingRow,
        ?bool $overrideCanEdit,
        ?array $metadataOverride,
        string $validationField
    ): array {
        $mapper = $this->eventPartyMappers->find($partyType);
        if (! $mapper) {
            throw ValidationException::withMessages([
                $validationField => ["Unknown party_type [{$partyType}]."],
            ]);
        }

        $existingCanEdit = null;
        if (
            is_array($existingRow)
            && isset($existingRow['permissions'])
            && is_array($existingRow['permissions'])
            && array_key_exists('can_edit', $existingRow['permissions'])
        ) {
            $existingCanEdit = (bool) $existingRow['permissions']['can_edit'];
        }

        $canEdit = $overrideCanEdit ?? $existingCanEdit ?? $mapper->defaultCanEdit();

        $metadata = $metadataOverride ?? $mapper->mapMetadata($source);
        $metadata = is_array($metadata) ? $metadata : [];

        $row = [
            'party_type' => $partyType,
            'party_ref_id' => $partyRefId,
            'permissions' => [
                'can_edit' => $canEdit,
            ],
        ];

        if ($metadata !== []) {
            $row['metadata'] = $metadata;
        }

        return $row;
    }

    private function eventPartyKey(string $partyType, string $partyRefId): string
    {
        return "{$partyType}:{$partyRefId}";
    }

    /**
     * @return array<string, array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }>
     */
    private function normalizeEventPartiesMap(mixed $value): array
    {
        $rows = $this->normalizeArray($value);
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $partyType = trim((string) ($row['party_type'] ?? ''));
            $partyRefId = trim((string) ($row['party_ref_id'] ?? ''));
            if ($partyType === '' || $partyRefId === '') {
                continue;
            }

            $permissions = isset($row['permissions']) && is_array($row['permissions'])
                ? $row['permissions']
                : [];
            $metadata = isset($row['metadata']) && is_array($row['metadata'])
                ? $row['metadata']
                : null;

            $normalizedRow = [
                'party_type' => $partyType,
                'party_ref_id' => $partyRefId,
                'permissions' => [
                    'can_edit' => (bool) ($permissions['can_edit'] ?? false),
                ],
            ];

            if ($metadata !== null && $metadata !== []) {
                $normalizedRow['metadata'] = $metadata;
            }

            $normalized[$this->eventPartyKey($partyType, $partyRefId)] = $normalizedRow;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string, id: string}
     */
    private function resolveCreatedByPrincipal(array $payload): array
    {
        $principal = $this->normalizeArray($payload['_created_by'] ?? []);
        $type = trim((string) ($principal['type'] ?? ''));
        $id = trim((string) ($principal['id'] ?? ''));

        if ($type === '' || $id === '') {
            return [
                'type' => 'system',
                'id' => 'system',
            ];
        }

        return [
            'type' => $type,
            'id' => $id,
        ];
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
     * @param  array<int, string>|null  $artistIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveArtistPayloads(?array $artistIds, ?Event $existing): array
    {
        $ids = $artistIds;
        if ($ids === null) {
            $current = $existing?->artists ?? [];
            $current = is_array($current) ? $current : [];

            return $current;
        }

        if ($ids === []) {
            return [];
        }

        return $this->eventProfileResolver->resolveArtistsByProfileIds(array_values($ids));
    }

    private function normalizePublishAt(mixed $value): ?Carbon
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
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
