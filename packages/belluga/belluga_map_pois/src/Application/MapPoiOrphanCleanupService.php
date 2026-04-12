<?php

declare(strict_types=1);

namespace Belluga\MapPois\Application;

use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;

class MapPoiOrphanCleanupService
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_REF_TYPES = ['event', 'account_profile', 'static'];

    private const DELETE_BATCH_SIZE = 200;

    public function __construct(
        private readonly MapPoiProjectionService $projectionService,
        private readonly MapPoiSourceReaderContract $sourceReader,
    ) {}

    /**
     * @param  array<int, string>|null  $refTypes
     */
    public function cleanup(?array $refTypes = null, ?int $deletedSinceMinutes = null): void
    {
        $resolvedRefTypes = $this->resolveRefTypes($refTypes);
        $deletedSince = $deletedSinceMinutes === null
            ? null
            : Carbon::now()->subMinutes(max($deletedSinceMinutes, 1));

        foreach ($resolvedRefTypes as $refType) {
            $this->cleanupRefType($refType, $deletedSince);
        }
    }

    /**
     * @param  array<int, string>|null  $refTypes
     * @return array<int, string>
     */
    private function resolveRefTypes(?array $refTypes): array
    {
        if ($refTypes === null) {
            return self::SUPPORTED_REF_TYPES;
        }

        $resolved = [];
        foreach ($refTypes as $refType) {
            $normalized = trim((string) $refType);
            if ($normalized === '') {
                continue;
            }
            if (! in_array($normalized, self::SUPPORTED_REF_TYPES, true)) {
                continue;
            }

            $resolved[] = $normalized;
        }

        return array_values(array_unique($resolved));
    }

    private function cleanupRefType(string $refType, ?Carbon $deletedSince): void
    {
        if ($refType === 'event') {
            $this->cleanupEventRefs();

            return;
        }

        if (in_array($refType, ['account_profile', 'static'], true)) {
            if ($deletedSince === null) {
                $this->cleanupMissingRefType($refType);

                return;
            }

            $this->cleanupDeletedSourceRefs($refType, $deletedSince);

            return;
        }

        $this->cleanupMissingRefType($refType);
    }

    private function cleanupDeletedSourceRefs(string $refType, ?Carbon $deletedSince): void
    {
        $orphanRefIds = [];

        foreach ($this->deletedRefIds($refType, $deletedSince) as $refId) {
            $normalizedRefId = trim((string) $refId);
            if ($normalizedRefId === '') {
                continue;
            }

            $orphanRefIds[$normalizedRefId] = $normalizedRefId;
            if (count($orphanRefIds) < self::DELETE_BATCH_SIZE) {
                continue;
            }

            $this->deleteOrphanBatch($refType, $orphanRefIds);
        }

        $this->deleteOrphanBatch($refType, $orphanRefIds);
    }

    /**
     * @return iterable<int|string, string>
     */
    private function deletedRefIds(string $refType, ?Carbon $deletedSince): iterable
    {
        return match ($refType) {
            'account_profile' => $this->sourceReader->allTrashedAccountProfileIds($deletedSince),
            'static' => $this->sourceReader->allTrashedStaticAssetIds($deletedSince),
            default => [],
        };
    }

    private function cleanupMissingRefType(string $refType): void
    {
        $liveRefIds = $this->resolveLiveRefIdSet($refType);
        $orphanRefIds = [];

        MapPoi::query()
            ->select(['_id', 'ref_id'])
            ->where('ref_type', $refType)
            ->orderBy('_id')
            ->cursor()
            ->each(function (MapPoi $poi) use ($refType, $liveRefIds, &$orphanRefIds): void {
                $refId = trim((string) ($poi->ref_id ?? ''));
                if ($refId === '' || isset($liveRefIds[$refId])) {
                    return;
                }

                $orphanRefIds[$refId] = $refId;
                if (count($orphanRefIds) < self::DELETE_BATCH_SIZE) {
                    return;
                }

                $this->deleteOrphanBatch($refType, $orphanRefIds);
            });

        $this->deleteOrphanBatch($refType, $orphanRefIds);
    }

    private function cleanupEventRefs(): void
    {
        $orphanRefIds = [];

        MapPoi::query()
            ->select(['_id', 'ref_id'])
            ->where('ref_type', 'event')
            ->orderBy('_id')
            ->cursor()
            ->each(function (MapPoi $poi) use (&$orphanRefIds): void {
                $refId = trim((string) ($poi->ref_id ?? ''));
                if ($refId === '') {
                    return;
                }

                if ($this->sourceReader->findEventById($refId) !== null) {
                    return;
                }

                $orphanRefIds[$refId] = $refId;
                if (count($orphanRefIds) < self::DELETE_BATCH_SIZE) {
                    return;
                }

                $this->deleteOrphanBatch('event', $orphanRefIds);
            });

        $this->deleteOrphanBatch('event', $orphanRefIds);
    }

    /**
     * @return array<string, true>
     */
    private function resolveLiveRefIdSet(string $refType): array
    {
        $liveRefIds = [];

        foreach ($this->liveRefIds($refType) as $refId) {
            $normalizedRefId = trim((string) $refId);
            if ($normalizedRefId === '') {
                continue;
            }

            $liveRefIds[$normalizedRefId] = true;
        }

        return $liveRefIds;
    }

    /**
     * @return iterable<int|string, string>
     */
    private function liveRefIds(string $refType): iterable
    {
        return match ($refType) {
            'event' => $this->sourceReader->allEventIds(),
            'account_profile' => $this->sourceReader->allAccountProfileIds(),
            'static' => $this->sourceReader->allStaticAssetIds(),
            default => [],
        };
    }

    /**
     * @param  array<int|string, string>  $orphanRefIds
     */
    private function deleteOrphanBatch(string $refType, array &$orphanRefIds): void
    {
        if ($orphanRefIds === []) {
            return;
        }

        $this->projectionService->deleteByRefs($refType, array_values($orphanRefIds));
        $orphanRefIds = [];
    }
}
