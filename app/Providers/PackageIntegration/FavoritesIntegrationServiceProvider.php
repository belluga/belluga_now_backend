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
            RebuildFavoriteSnapshotJob::dispatch('account_profile', (string) $profile->getAttribute('_id'));
        });

        AccountProfile::deleted(function (AccountProfile $profile): void {
            RebuildFavoriteSnapshotJob::dispatch('account_profile', (string) $profile->getAttribute('_id'));
        });

        AccountProfile::restored(function (AccountProfile $profile): void {
            RebuildFavoriteSnapshotJob::dispatch('account_profile', (string) $profile->getAttribute('_id'));
        });

        EventOccurrence::saved(function (EventOccurrence $occurrence): void {
            foreach (self::extractAccountProfileIdsFromOccurrence($occurrence) as $profileId) {
                RebuildFavoriteSnapshotJob::dispatch('account_profile', $profileId);
            }
        });

        EventOccurrence::deleted(function (EventOccurrence $occurrence): void {
            foreach (self::extractAccountProfileIdsFromOccurrence($occurrence) as $profileId) {
                RebuildFavoriteSnapshotJob::dispatch('account_profile', $profileId);
            }
        });

        EventOccurrence::restored(function (EventOccurrence $occurrence): void {
            foreach (self::extractAccountProfileIdsFromOccurrence($occurrence) as $profileId) {
                RebuildFavoriteSnapshotJob::dispatch('account_profile', $profileId);
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private static function extractAccountProfileIdsFromOccurrence(EventOccurrence $occurrence): array
    {
        $profileIds = [];

        $venue = $occurrence->getAttribute('venue');
        if ($venue instanceof \MongoDB\Model\BSONDocument || $venue instanceof \MongoDB\Model\BSONArray) {
            $venue = $venue->getArrayCopy();
        }
        if (is_array($venue) && isset($venue['id']) && trim((string) $venue['id']) !== '') {
            $profileIds[] = trim((string) $venue['id']);
        }

        $artists = $occurrence->getAttribute('artists');
        if ($artists instanceof \MongoDB\Model\BSONDocument || $artists instanceof \MongoDB\Model\BSONArray) {
            $artists = $artists->getArrayCopy();
        }

        if (is_array($artists)) {
            foreach ($artists as $artist) {
                if ($artist instanceof \MongoDB\Model\BSONDocument || $artist instanceof \MongoDB\Model\BSONArray) {
                    $artist = $artist->getArrayCopy();
                }

                if (! is_array($artist)) {
                    continue;
                }

                $artistId = isset($artist['id']) ? trim((string) $artist['id']) : '';
                if ($artistId !== '') {
                    $profileIds[] = $artistId;
                }
            }
        }

        $profileIds = array_values(array_unique(array_filter($profileIds, static fn (string $id): bool => $id !== '')));

        return $profileIds;
    }
}
