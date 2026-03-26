<?php

declare(strict_types=1);

namespace App\Application\Shared\MapPois;

use Belluga\MapPois\Models\Tenants\MapPoi;
use MongoDB\BSON\ObjectId;

final class MapPoiProjectionRefService
{
    /**
     * @param  array<int, string>  $rawIds
     * @param  callable(string): void  $dispatcher
     */
    public function dispatchForEachRefId(array $rawIds, callable $dispatcher): void
    {
        foreach ($this->normalizeRefIds($rawIds) as $refId) {
            $dispatcher($refId);
        }
    }

    /**
     * @param  array<int, string>  $rawIds
     */
    public function countByRefType(string $refType, array $rawIds): int
    {
        [$stringRefIds, $objectRefIds] = $this->splitQueryRefIds($rawIds);
        if ($stringRefIds === [] && $objectRefIds === []) {
            return 0;
        }

        $query = MapPoi::query()
            ->where('ref_type', $refType)
            ->where(function ($nested) use ($stringRefIds, $objectRefIds): void {
                if ($stringRefIds !== []) {
                    $nested->whereIn('ref_id', $stringRefIds);
                }
                if ($objectRefIds !== []) {
                    $nested->orWhereIn('ref_id', $objectRefIds);
                }
            });

        return (int) $query->count();
    }

    /**
     * @param  array<int, string>  $rawIds
     * @return array<int, string>
     */
    private function normalizeRefIds(array $rawIds): array
    {
        $normalized = [];
        foreach ($rawIds as $rawId) {
            $id = trim((string) $rawId);
            if ($id === '') {
                continue;
            }

            $normalized[] = $id;
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $rawIds
     * @return array{0: array<int, string>, 1: array<int, ObjectId>}
     */
    private function splitQueryRefIds(array $rawIds): array
    {
        $stringIds = $this->normalizeRefIds($rawIds);
        $objectIds = [];

        foreach ($stringIds as $id) {
            if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
                continue;
            }

            try {
                $objectIds[] = new ObjectId($id);
            } catch (\Throwable) {
                // Ignore invalid ObjectId conversions and keep string matching.
            }
        }

        return [$stringIds, $objectIds];
    }
}
