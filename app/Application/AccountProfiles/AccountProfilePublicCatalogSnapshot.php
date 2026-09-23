<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfilePublicCatalogSnapshot
{
    private readonly AccountProfilePublicVisibilityPolicy $policy;

    /**
     * @param  array<int, array{id:string,type:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string,has_nested_profile_groups:bool}>  $typeRecords
     * @param  array<int, string>  $catalogTypeKeys
     * @param  array<int, string>  $publicDetailTypeKeys
     * @param  array<int, string>  $nestedParentTypeKeys
     * @param  array<int, string>  $avatarEnabledTypeKeys
     * @param  array<int, string>  $coverEnabledTypeKeys
     */
    public function __construct(
        private readonly array $typeRecords,
        private readonly array $catalogTypeKeys,
        private readonly array $publicDetailTypeKeys,
        private readonly array $nestedParentTypeKeys,
        private readonly array $avatarEnabledTypeKeys,
        private readonly array $coverEnabledTypeKeys,
    ) {
        $this->policy = new AccountProfilePublicVisibilityPolicy(
            $this->catalogTypeKeys,
            $this->publicDetailTypeKeys,
            $this->nestedParentTypeKeys,
            $this->avatarEnabledTypeKeys,
            $this->coverEnabledTypeKeys,
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

    public function policy(): AccountProfilePublicVisibilityPolicy
    {
        return $this->policy;
    }

    /**
     * @return array<int, array{id:string,type:string,label:string,visual:array<string, mixed>|null,poi_visual:array<string, mixed>|null,allowed_taxonomies:array<int, string>,type_asset_url:?string,has_nested_profile_groups:bool}>
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
