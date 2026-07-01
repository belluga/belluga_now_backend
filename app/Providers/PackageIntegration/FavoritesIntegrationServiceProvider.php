<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Belluga\Favorites\Jobs\RebuildFavoriteSnapshotJob;
use Belluga\Favorites\Support\FavoriteRegistryDefinition;
use Illuminate\Support\ServiceProvider;

class FavoritesIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var FavoritesRegistryContract $registry */
        $registry = $this->app->make(FavoritesRegistryContract::class);

        $registries = config('favorites.registries', []);
        if (! is_array($registries)) {
            $registries = [];
        }

        foreach ($registries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $registryKey = isset($entry['registry_key']) ? trim((string) $entry['registry_key']) : '';
            $targetType = isset($entry['target_type']) ? trim((string) $entry['target_type']) : '';
            $snapshotBuilder = isset($entry['snapshot_builder']) ? trim((string) $entry['snapshot_builder']) : '';

            if ($registryKey === '' || $targetType === '' || $snapshotBuilder === '') {
                continue;
            }

            $snapshotCollection = isset($entry['snapshot_collection']) ? trim((string) $entry['snapshot_collection']) : null;
            if ($snapshotCollection === '') {
                $snapshotCollection = null;
            }

            $requiresSpecificIndexes = (bool) ($entry['requires_specific_indexes'] ?? false);
            $sharedEnvelopeFields = $entry['shared_envelope_fields'] ?? ['registry_key', 'target_type', 'target_id', 'updated_at'];
            $sharedEnvelopeFields = is_array($sharedEnvelopeFields) ? array_values(array_map('strval', $sharedEnvelopeFields)) : ['registry_key', 'target_type', 'target_id', 'updated_at'];

            $registry->register(new FavoriteRegistryDefinition(
                registryKey: $registryKey,
                targetType: $targetType,
                snapshotBuilderClass: $snapshotBuilder,
                snapshotCollection: $snapshotCollection,
                requiresSpecificIndexes: $requiresSpecificIndexes,
                sharedEnvelopeFields: $sharedEnvelopeFields,
            ));
        }

        AccountProfile::saved(function (AccountProfile $profile): void {
            self::rebuildAccountProfileSnapshot((string) $profile->getAttribute('_id'));
        });

        AccountProfile::deleted(function (AccountProfile $profile): void {
            self::rebuildAccountProfileSnapshot((string) $profile->getAttribute('_id'));
        });

        AccountProfile::restored(function (AccountProfile $profile): void {
            self::rebuildAccountProfileSnapshot((string) $profile->getAttribute('_id'));
        });

        EventOccurrence::saved(function (EventOccurrence $occurrence): void {
            foreach (self::extractAccountProfileIdsFromOccurrence($occurrence) as $profileId) {
                self::rebuildAccountProfileSnapshot($profileId);
            }
        });

        EventOccurrence::deleted(function (EventOccurrence $occurrence): void {
            foreach (self::extractAccountProfileIdsFromOccurrence($occurrence) as $profileId) {
                self::rebuildAccountProfileSnapshot($profileId);
            }
        });

        EventOccurrence::restored(function (EventOccurrence $occurrence): void {
            foreach (self::extractAccountProfileIdsFromOccurrence($occurrence) as $profileId) {
                self::rebuildAccountProfileSnapshot($profileId);
            }
        });
    }

    private static function rebuildAccountProfileSnapshot(string $profileId): void
    {
        $resolvedProfileId = trim($profileId);
        if ($resolvedProfileId === '') {
            return;
        }

        RebuildFavoriteSnapshotJob::dispatchSync('account_profile', $resolvedProfileId);
    }

    /**
     * @return array<int, string>
     */
    private static function extractAccountProfileIdsFromOccurrence(EventOccurrence $occurrence): array
    {
        $profileIds = [];

        $placeRef = self::normalizeArray($occurrence->getAttribute('place_ref'));
        if (($placeRef['type'] ?? null) === 'account_profile') {
            $placeRefId = self::extractEmbeddedId($placeRef);
            if ($placeRefId !== '') {
                $profileIds[] = $placeRefId;
            }
        }

        foreach (self::normalizeList($occurrence->getAttribute('event_parties')) as $eventParty) {
            $partyRefId = trim((string) ($eventParty['party_ref_id'] ?? ''));
            if ($partyRefId !== '') {
                $profileIds[] = $partyRefId;
            }
        }

        $profileIds = array_values(array_unique(array_filter($profileIds, static fn (string $id): bool => $id !== '')));

        return $profileIds;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeList(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = self::normalizeArray($item);
        }

        return $normalized;
    }

    /**
     * Accept both legacy `id` and production-shaped `_id` embedded references.
     */
    private static function extractEmbeddedId(array $payload): string
    {
        foreach (['id', '_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
