<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use MongoDB\Driver\Session;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Operation\FindOneAndUpdate;

final class AccountProfileAdmissionFenceService
{
    /**
     * @param  list<string>  $profileTypes
     * @param  array<string, int>  $expectedCapabilityRevisions
     * @return array<string, array<string, mixed>>
     */
    public function touchProfileTypes(
        Database $database,
        Session $session,
        array $profileTypes,
        array $expectedCapabilityRevisions = [],
    ): array {
        $types = $this->normalizedStrings($profileTypes);
        $documents = [];

        foreach ($types as $profileType) {
            $filter = ['type' => $profileType];
            if (array_key_exists($profileType, $expectedCapabilityRevisions)) {
                $expectedRevision = max(0, $expectedCapabilityRevisions[$profileType]);
                $filter += $expectedRevision === 0
                    ? ['$or' => [
                        ['capability_revision' => 0],
                        ['capability_revision' => ['$exists' => false]],
                    ]]
                    : ['capability_revision' => $expectedRevision];
            }
            $document = $this->document($database->selectCollection('account_profile_types')->findOneAndUpdate(
                $filter,
                ['$inc' => ['host_admission_fence_revision' => 1]],
                [
                    'session' => $session,
                    'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_BEFORE,
                ],
            ));
            if ($document === null) {
                throw new ConcurrencyConflictException('Account Profile Type admission target changed or is unavailable.');
            }

            $documents[$profileType] = $document;
        }

        return $documents;
    }

    /**
     * @param  list<string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    public function touchProfiles(Database $database, Session $session, array $profileIds): array
    {
        $ids = $this->normalizedStrings($profileIds);
        $documents = [];

        foreach ($ids as $profileId) {
            try {
                $objectId = new ObjectId($profileId);
            } catch (\Throwable) {
                throw new ConcurrencyConflictException('Account Profile admission target id is invalid.');
            }

            $document = $this->document($database->selectCollection('account_profiles')->findOneAndUpdate([
                '_id' => $objectId,
                'deleted_at' => null,
            ], [
                '$inc' => ['lifecycle_fence_revision' => 1],
            ], [
                'session' => $session,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_BEFORE,
            ]));
            if ($document === null) {
                throw new ConcurrencyConflictException('Account Profile admission target is unavailable.');
            }

            $documents[$profileId] = $document;
        }

        return $documents;
    }

    /** @param list<string> $values @return list<string> */
    private function normalizedStrings(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== '')));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function document(mixed $value): ?array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }
        if (! is_array($value)) {
            return null;
        }
        foreach ($value as $key => $item) {
            if ($item instanceof BSONDocument || $item instanceof BSONArray || $item instanceof \Traversable) {
                $value[$key] = $this->document($item) ?? [];
            }
        }

        return $value;
    }
}
