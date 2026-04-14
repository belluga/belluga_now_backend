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
     * @return array<int, array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }>
     */
    private function resolveEventParties(
        array $payload,
        ?Event $existing
    ): array {
        $existingRows = array_values($this->normalizeEventPartiesMap($existing?->event_parties ?? []));

        if (! array_key_exists('event_parties', $payload)) {
            return $existingRows;
        }

        $incomingRows = $payload['event_parties'];
        if (! is_array($incomingRows)) {
            throw ValidationException::withMessages([
                'event_parties' => ['event_parties must be an array.'],
            ]);
        }

        $existingByRefId = [];
        foreach ($existingRows as $row) {
            $existingByRefId[(string) ($row['party_ref_id'] ?? '')] = $row;
        }

        $profileIds = [];
        $overridesByRefId = [];

        foreach ($incomingRows as $index => $incomingRow) {
            if (! is_array($incomingRow)) {
                throw ValidationException::withMessages([
                    "event_parties.{$index}" => ['event party payload must be an object.'],
                ]);
            }

            $this->assertCanonicalEventPartyWriteShape($incomingRow, $index);

            $partyRefId = trim((string) ($incomingRow['party_ref_id'] ?? ''));
            if ($partyRefId === '') {
                throw ValidationException::withMessages([
                    "event_parties.{$index}.party_ref_id" => ['party_ref_id is required.'],
                ]);
            }

            if (isset($overridesByRefId[$partyRefId])) {
                throw ValidationException::withMessages([
                    "event_parties.{$index}.party_ref_id" => ['Duplicate related account profiles are not allowed.'],
                ]);
            }

            $overrideCanEdit = null;
            if (
                isset($incomingRow['permissions'])
                && is_array($incomingRow['permissions'])
                && array_key_exists('can_edit', $incomingRow['permissions'])
            ) {
                $overrideCanEdit = (bool) $incomingRow['permissions']['can_edit'];
            }

            $profileIds[] = $partyRefId;
            $overridesByRefId[$partyRefId] = [
                'index' => $index,
                'override_can_edit' => $overrideCanEdit,
                'existing_row' => $existingByRefId[$partyRefId] ?? null,
            ];
        }

        $resolvedProfiles = $this->eventProfileResolver->resolveEventPartyProfilesByIds($profileIds);
        $resolvedProfilesByRefId = [];

        foreach ($resolvedProfiles as $profile) {
            if (! is_array($profile)) {
                throw ValidationException::withMessages([
                    'event_parties' => ['Resolved event party profile payload is invalid.'],
                ]);
            }

            $resolvedPartyRefId = trim((string) ($profile['id'] ?? ''));
            if ($resolvedPartyRefId === '') {
                throw ValidationException::withMessages([
                    'event_parties' => ['Resolved event party profile payload is invalid.'],
                ]);
            }

            $resolvedProfilesByRefId[$resolvedPartyRefId] = $profile;
        }

        $missingProfileIds = array_values(
            array_diff($profileIds, array_keys($resolvedProfilesByRefId))
        );
        if ($missingProfileIds !== []) {
            $firstMissingId = $missingProfileIds[0];
            $missingIndex = (int) ($overridesByRefId[$firstMissingId]['index'] ?? 0);

            throw ValidationException::withMessages([
                "event_parties.{$missingIndex}.party_ref_id" => ['Related account profile was not found.'],
            ]);
        }

        $resolved = [];

        foreach ($profileIds as $position => $partyRefId) {
            $profile = $resolvedProfilesByRefId[$partyRefId] ?? null;
            if (! is_array($profile)) {
                throw ValidationException::withMessages([
                    'event_parties' => ['Resolved event party profile order is invalid.'],
                ]);
            }

            $profileType = trim((string) ($profile['profile_type'] ?? ''));

            if (! isset($overridesByRefId[$partyRefId])) {
                throw ValidationException::withMessages([
                    'event_parties' => ['Resolved event party profile order is invalid.'],
                ]);
            }

            $validationIndex = (int) ($overridesByRefId[$partyRefId]['index'] ?? $position);
            if ($profileType === 'venue') {
                throw ValidationException::withMessages([
                    "event_parties.{$validationIndex}.party_ref_id" => ['Venue account profiles must stay on place_ref and cannot be persisted in event_parties.'],
                ]);
            }

            $resolved[] = $this->buildEventPartyRow(
                $profileType,
                $partyRefId,
                $profile,
                is_array($overridesByRefId[$partyRefId]['existing_row'] ?? null)
                    ? $overridesByRefId[$partyRefId]['existing_row']
                    : null,
                $overridesByRefId[$partyRefId]['override_can_edit'] ?? null,
                "event_parties.{$validationIndex}.party_ref_id"
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $incomingRow
     */
    private function assertCanonicalEventPartyWriteShape(array $incomingRow, int $index): void
    {
        $allowedKeys = ['party_ref_id', 'permissions'];
        $unexpectedKeys = array_values(array_diff(array_keys($incomingRow), $allowedKeys));

        if (in_array('party_type', $unexpectedKeys, true)) {
            throw ValidationException::withMessages([
                "event_parties.{$index}.party_type" => ['party_type is inferred from party_ref_id and must not be sent by clients.'],
            ]);
        }

        if (in_array('metadata', $unexpectedKeys, true)) {
            throw ValidationException::withMessages([
                "event_parties.{$index}.metadata" => ['metadata is generated by the backend and must not be sent by clients.'],
            ]);
        }

        if ($unexpectedKeys !== []) {
            throw ValidationException::withMessages([
                "event_parties.{$index}" => ['Unsupported event party fields were provided.'],
            ]);
        }

        if (array_key_exists('permissions', $incomingRow) && ! is_array($incomingRow['permissions'])) {
            throw ValidationException::withMessages([
                "event_parties.{$index}.permissions" => ['permissions must be an object.'],
            ]);
        }

        if (isset($incomingRow['permissions']) && is_array($incomingRow['permissions'])) {
            $unexpectedPermissionKeys = array_values(
                array_diff(array_keys($incomingRow['permissions']), ['can_edit'])
            );

            if ($unexpectedPermissionKeys !== []) {
                throw ValidationException::withMessages([
                    "event_parties.{$index}.permissions" => ['permissions only supports can_edit.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @param array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }|null $existingRow
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

        $displayName = trim((string) ($source['display_name'] ?? ''));
        $slug = trim((string) ($source['slug'] ?? ''));
        $profileType = trim((string) ($source['profile_type'] ?? ''));
        if ($displayName === '' || $slug === '' || $profileType === '') {
            throw ValidationException::withMessages([
                $validationField => ['Resolved account profile metadata must include display_name, slug and profile_type.'],
            ]);
        }
        if ($profileType !== $partyType) {
            throw ValidationException::withMessages([
                $validationField => ["party_type [{$partyType}] must match metadata.profile_type [{$profileType}]."],
            ]);
        }
        $metadata = $mapper->mapMetadata($source);
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
