<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfilePublicCatalogSnapshot
{
    private readonly AccountProfilePublicCatalogEligibilityPolicy $policy;

    /**
     * @param  array<int, array{id:string,type:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string,is_poi_enabled:bool,has_nested_profile_groups:bool}>  $typeRecords
     * @param  array<int, string>  $catalogTypeKeys
     * @param  array<int, string>  $publicDetailTypeKeys
     * @param  array<int, string>  $nestedParentTypeKeys
     */
    public function __construct(
        private readonly array $typeRecords,
        private readonly array $catalogTypeKeys,
        private readonly array $publicDetailTypeKeys,
        private readonly array $nestedParentTypeKeys,
    ) {
        $this->policy = new AccountProfilePublicCatalogEligibilityPolicy(
            $this->catalogTypeKeys,
            $this->publicDetailTypeKeys,
            $this->nestedParentTypeKeys,
        );
    }

    /**
     * @return array<int, string>
     */
    public function catalogTypeKeys(): array
    {
        return $this->catalogTypeKeys;
    }

    /**
     * @return array<int, string>
     */
    public function nestedParentTypeKeys(): array
    {
        return $this->nestedParentTypeKeys;
    }

    public function policy(): AccountProfilePublicCatalogEligibilityPolicy
    {
        return $this->policy;
    }

    /**
     * @return array<int, array{id:string,type:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string,is_poi_enabled:bool,has_nested_profile_groups:bool}>
     */
    public function typeRecords(): array
    {
        return $this->typeRecords;
    }

    /**
     * @return array<int, array{id:string,value:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string}>
     */
    public function filterOptions(): array
    {
        return array_map(
            static fn (array $record): array => [
                'id' => $record['id'],
                'value' => $record['type'],
                'label' => $record['label'],
                'visual' => $record['visual'],
                'poi_visual' => $record['poi_visual'],
                'allowed_taxonomies' => $record['allowed_taxonomies'],
                'type_asset_url' => $record['type_asset_url'],
            ],
            $this->typeRecords,
        );
    }
}
