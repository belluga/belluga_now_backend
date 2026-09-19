<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Models\Tenants\TenantProfileType;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Performs the uncached type reads once per Laravel request scope.
 */
final class AccountProfilePublicCatalogSnapshotReader
{
    private ?AccountProfilePublicCatalogSnapshot $catalogSnapshot = null;

    /** @var array<int, string>|null */
    private ?array $publicPoiTypeKeys = null;

    /** @var array<int, string>|null */
    private ?array $publicDetailTypeKeys = null;

    private ?AccountProfilePublicCatalogEligibilityPolicy $publicPoiEligibilityPolicy = null;

    private ?AccountProfilePublicCatalogEligibilityPolicy $publicPhysicalHostEligibilityPolicy = null;

    private int $cacheRevision = -1;

    public function __construct(
        private readonly AccountProfileCapabilityResolverContract $capabilityResolver,
        private readonly AccountProfileTypeSetProvider $profileTypeSets,
    ) {}

    public function catalogSnapshot(): AccountProfilePublicCatalogSnapshot
    {
        $this->refreshIfStale();

        if ($this->catalogSnapshot instanceof AccountProfilePublicCatalogSnapshot) {
            return $this->catalogSnapshot;
        }

        $records = [];
        $catalogTypeKeys = $this->profileTypeSets->publicCatalogTypes();
        $profileTypes = $catalogTypeKeys === []
            ? collect()
            : TenantProfileType::query()
                ->whereIn('type', $catalogTypeKeys)
                ->get([
                    '_id',
                    'type',
                    'label',
                    'visual',
                    'poi_visual',
                    'allowed_taxonomies',
                    'type_asset_url',
                    'capabilities',
                ]);
        foreach ($profileTypes as $profileType) {
            $record = $this->recordFromProfileType($profileType);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        // The type collection remains bounded; this avoids a database sort for UI options.
        usort($records, static fn (array $left, array $right): int => [
            mb_strtolower($left['label']),
            $left['type'],
        ] <=> [
            mb_strtolower($right['label']),
            $right['type'],
        ]);

        $catalogTypeKeys = array_values(array_map(
            static fn (array $record): string => $record['type'],
            $records,
        ));
        $publicDetailTypeKeys = $this->publicDetailTypeKeys();
        $nestedParentTypeKeys = array_values(array_map(
            static fn (array $record): string => $record['type'],
            array_filter(
                $records,
                static fn (array $record): bool => $record['has_nested_profile_groups'],
            ),
        ));

        return $this->catalogSnapshot = new AccountProfilePublicCatalogSnapshot(
            $records,
            $catalogTypeKeys,
            $publicDetailTypeKeys,
            $nestedParentTypeKeys,
        );
    }

    /**
     * @return array<int, string>
     */
    public function publicPoiTypeKeys(): array
    {
        $this->refreshIfStale();

        if ($this->publicPoiTypeKeys !== null) {
            return $this->publicPoiTypeKeys;
        }

        $this->publicPoiTypeKeys = $this->profileTypeSets->publicPoiCatalogTypes();
        sort($this->publicPoiTypeKeys, SORT_STRING);

        return $this->publicPoiTypeKeys;
    }

    public function publicPoiEligibilityPolicy(): AccountProfilePublicCatalogEligibilityPolicy
    {
        $this->refreshIfStale();

        if ($this->publicPoiEligibilityPolicy instanceof AccountProfilePublicCatalogEligibilityPolicy) {
            return $this->publicPoiEligibilityPolicy;
        }

        $publicPoiTypeKeys = $this->publicPoiTypeKeys();
        $publicDetailPoiTypeKeys = array_values(array_intersect(
            $publicPoiTypeKeys,
            $this->publicDetailTypeKeys(),
        ));

        return $this->publicPoiEligibilityPolicy = new AccountProfilePublicCatalogEligibilityPolicy(
            $publicPoiTypeKeys,
            $publicDetailPoiTypeKeys,
            [],
        );
    }

    public function publicPhysicalHostEligibilityPolicy(): AccountProfilePublicCatalogEligibilityPolicy
    {
        $this->refreshIfStale();

        if ($this->publicPhysicalHostEligibilityPolicy instanceof AccountProfilePublicCatalogEligibilityPolicy) {
            return $this->publicPhysicalHostEligibilityPolicy;
        }

        $publicPhysicalHostTypeKeys = $this->profileTypeSets->publicPhysicalHostTypes();
        sort($publicPhysicalHostTypeKeys, SORT_STRING);
        $publicDetailTypeKeys = array_values(array_intersect(
            $publicPhysicalHostTypeKeys,
            $this->publicDetailTypeKeys(),
        ));

        return $this->publicPhysicalHostEligibilityPolicy = new AccountProfilePublicCatalogEligibilityPolicy(
            $publicPhysicalHostTypeKeys,
            $publicDetailTypeKeys,
            [],
        );
    }

    private function refreshIfStale(): void
    {
        $currentRevision = AccountProfileTypeSetProvider::currentRevision();
        if ($this->cacheRevision === $currentRevision) {
            return;
        }

        $this->catalogSnapshot = null;
        $this->publicPoiTypeKeys = null;
        $this->publicDetailTypeKeys = null;
        $this->publicPoiEligibilityPolicy = null;
        $this->publicPhysicalHostEligibilityPolicy = null;
        $this->cacheRevision = $currentRevision;
    }

    /**
     * @return array<int, string>
     */
    private function publicDetailTypeKeys(): array
    {
        if ($this->publicDetailTypeKeys !== null) {
            return $this->publicDetailTypeKeys;
        }

        $this->publicDetailTypeKeys = $this->profileTypeSets->publiclyNavigableTypes();
        sort($this->publicDetailTypeKeys, SORT_STRING);

        return $this->publicDetailTypeKeys;
    }

    /**
     * @return array{type:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string,has_nested_profile_groups:bool}|null
     */
    private function recordFromProfileType(TenantProfileType $profileType): ?array
    {
        $type = trim((string) $profileType->getAttribute('type'));
        if ($type === '') {
            return null;
        }

        $label = trim((string) ($profileType->getAttribute('label') ?? $type));

        return [
            'id' => (string) $profileType->getKey(),
            'type' => $type,
            'label' => $label === '' ? $type : $label,
            'visual' => $this->nullableArray($profileType->getAttribute('visual')),
            'poi_visual' => $this->nullableArray($profileType->getAttribute('poi_visual')),
            'allowed_taxonomies' => $this->normalizeStringList($profileType->getAttribute('allowed_taxonomies')),
            'type_asset_url' => $this->nullableString($profileType->getAttribute('type_asset_url')),
            'has_nested_profile_groups' => $this->capabilityResolver
                ->resolveForProfileType($profileType, 'has_nested_profile_groups')['effective']['value'] === true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nullableArray(mixed $value): ?array
    {
        $normalized = $this->arrayFrom($value);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $normalized = [];
        foreach ($this->arrayFrom($value) as $item) {
            $token = trim((string) $item);
            if ($token !== '') {
                $normalized[$token] = $token;
            }
        }

        return array_values($normalized);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
