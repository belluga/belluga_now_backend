<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

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

    private ?AccountProfilePublicCatalogEligibilityPolicy $publicPoiEligibilityPolicy = null;

    public function __construct(
        private readonly AccountProfileTypeCapabilityCatalog $capabilityCatalog,
    ) {}

    public function catalogSnapshot(): AccountProfilePublicCatalogSnapshot
    {
        if ($this->catalogSnapshot instanceof AccountProfilePublicCatalogSnapshot) {
            return $this->catalogSnapshot;
        }

        $records = [];
        foreach (TenantProfileType::query()
            ->publicCatalog()
            ->get([
                '_id',
                'type',
                'label',
                'visual',
                'poi_visual',
                'allowed_taxonomies',
                'type_asset_url',
                'capabilities',
            ]) as $profileType) {
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
        $publicDetailTypeKeys = $catalogTypeKeys;
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
        if ($this->publicPoiTypeKeys !== null) {
            return $this->publicPoiTypeKeys;
        }

        $keys = [];
        foreach (TenantProfileType::query()->publicPoiCatalog()->get(['type']) as $profileType) {
            $type = trim((string) $profileType->getAttribute('type'));
            if ($type !== '') {
                $keys[$type] = $type;
            }
        }
        $this->publicPoiTypeKeys = array_values($keys);
        sort($this->publicPoiTypeKeys, SORT_STRING);

        return $this->publicPoiTypeKeys;
    }

    public function publicPoiEligibilityPolicy(): AccountProfilePublicCatalogEligibilityPolicy
    {
        if ($this->publicPoiEligibilityPolicy instanceof AccountProfilePublicCatalogEligibilityPolicy) {
            return $this->publicPoiEligibilityPolicy;
        }

        return $this->publicPoiEligibilityPolicy = new AccountProfilePublicCatalogEligibilityPolicy(
            $this->publicPoiTypeKeys(),
            $this->publicPoiTypeKeys(),
            [],
        );
    }

    /**
     * @return array{type:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string,is_poi_enabled:bool,has_nested_profile_groups:bool}|null
     */
    private function recordFromProfileType(TenantProfileType $profileType): ?array
    {
        $type = trim((string) $profileType->getAttribute('type'));
        if ($type === '') {
            return null;
        }

        $capabilities = $this->arrayFrom($profileType->getAttribute('capabilities'));
        $label = trim((string) ($profileType->getAttribute('label') ?? $type));

        return [
            'id' => (string) $profileType->getKey(),
            'type' => $type,
            'label' => $label === '' ? $type : $label,
            'visual' => $this->nullableArray($profileType->getAttribute('visual')),
            'poi_visual' => $this->nullableArray($profileType->getAttribute('poi_visual')),
            'allowed_taxonomies' => $this->normalizeStringList($profileType->getAttribute('allowed_taxonomies')),
            'type_asset_url' => $this->nullableString($profileType->getAttribute('type_asset_url')),
            'is_poi_enabled' => $this->capabilityCatalog->isExplicitlyEnabled(
                AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED,
                $capabilities,
            ),
            'has_nested_profile_groups' => $this->capabilityCatalog->isExplicitlyEnabled(
                AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS,
                $capabilities,
            ),
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
