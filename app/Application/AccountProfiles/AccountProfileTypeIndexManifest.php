<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfileTypeIndexManifest
{
    /** @var array<int, array{id:string, capability:string, name:string}> */
    private const CAPABILITY_FLAG_INDEXES = [
        ['id' => 'C-01', 'capability' => 'is_queryable', 'name' => 'idx_account_profile_types_capability_is_queryable_v1'],
        ['id' => 'C-02', 'capability' => 'is_publicly_navigable', 'name' => 'idx_account_profile_types_capability_is_publicly_navigable_v1'],
        ['id' => 'C-03', 'capability' => 'is_publicly_discoverable', 'name' => 'idx_account_profile_types_capability_is_publicly_discoverable_v1'],
        ['id' => 'C-04', 'capability' => 'is_favoritable', 'name' => 'idx_account_profile_types_capability_is_favoritable_v1'],
        ['id' => 'C-05', 'capability' => 'is_inviteable', 'name' => 'idx_account_profile_types_capability_is_inviteable_v1'],
        ['id' => 'C-06', 'capability' => 'is_poi_enabled', 'name' => 'idx_account_profile_types_capability_is_poi_enabled_v1'],
        ['id' => 'C-07', 'capability' => 'is_reference_location_enabled', 'name' => 'idx_account_profile_types_capability_is_reference_location_enabled_v1'],
        ['id' => 'C-08', 'capability' => 'has_bio', 'name' => 'idx_account_profile_types_capability_has_bio_v1'],
        ['id' => 'C-09', 'capability' => 'has_content', 'name' => 'idx_account_profile_types_capability_has_content_v1'],
        ['id' => 'C-10', 'capability' => 'has_taxonomies', 'name' => 'idx_account_profile_types_capability_has_taxonomies_v1'],
        ['id' => 'C-11', 'capability' => 'has_avatar', 'name' => 'idx_account_profile_types_capability_has_avatar_v1'],
        ['id' => 'C-12', 'capability' => 'has_cover', 'name' => 'idx_account_profile_types_capability_has_cover_v1'],
        ['id' => 'C-13', 'capability' => 'has_events', 'name' => 'idx_account_profile_types_capability_has_events_v1'],
        ['id' => 'C-14', 'capability' => 'has_gallery', 'name' => 'idx_account_profile_types_capability_has_gallery_v1'],
        ['id' => 'C-15', 'capability' => 'has_nested_profile_groups', 'name' => 'idx_account_profile_types_capability_has_nested_profile_groups_v1'],
        ['id' => 'C-16', 'capability' => 'has_contact_channels', 'name' => 'idx_account_profile_types_capability_has_contact_channels_v1'],
    ];

    /**
     * @return array<int, array{id:string, owner:string, facade:string, name:string, keys:array<string, int>, projection:array<string, int>, collation:array{locale:string}, partial_filter:null, explain_scenario:string}>
     */
    public function definitions(): array
    {
        $definitions = [
            $this->definition('M-01', 'U04 DDL / U06 query', 'queryable', 'idx_account_profile_types_candidate_queryable_v1', ['capabilities.is_queryable' => 1, 'type' => 1], 'candidate browse/search type-key read'),
            $this->definition('M-02', 'U04 DDL / historical candidate migration', 'historical contact composite', 'idx_account_profile_types_candidate_contact_capable_v1', ['capabilities.has_contact_channels' => 1, 'type' => 1], 'historical inventory; active direct contact read uses C-16'),
            $this->definition('M-03', 'U04', 'publiclyNavigable', 'idx_account_profile_types_public_navigation_v1', ['capabilities.is_publicly_navigable' => 1, 'type' => 1], 'Event public navigation type-key read'),
            $this->definition('M-04', 'U04 / U07', 'publiclyDiscoverable/publicCatalog', 'idx_account_profile_types_public_discovery_v1', ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_discoverable' => 1, 'type' => 1], 'discoverable type-set and U07 direct catalog snapshot'),
            $this->definition('M-05', 'U04', 'publicPoiCatalog', 'idx_account_profile_types_public_poi_catalog_v2', ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_discoverable' => 1, 'capabilities.is_poi_enabled' => 1, 'type' => 1], 'U07 near-only public POI read'),
            $this->definition('M-06', 'U04', 'queryable->poiEnabled', 'idx_account_profile_types_queryable_poi_enabled_v1', ['capabilities.is_queryable' => 1, 'capabilities.is_poi_enabled' => 1, 'type' => 1], 'Event venue/place resolver'),
            $this->definition('M-07', 'U04', 'queryable->publiclyNavigable->poiEnabled', 'idx_account_profile_types_queryable_public_navigation_poi_enabled_v1', ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_navigable' => 1, 'capabilities.is_poi_enabled' => 1, 'type' => 1], 'Event public place resolver'),
        ];

        foreach (self::CAPABILITY_FLAG_INDEXES as $index) {
            $definitions[] = $this->definition(
                $index['id'],
                'U04',
                'capability flag',
                $index['name'],
                ["capabilities.{$index['capability']}" => 1],
                "{$index['capability']} capability-flag read",
            );
        }

        return $definitions;
    }

    /**
     * @param  array<string, int>  $keys
     * @return array{id:string, owner:string, facade:string, name:string, keys:array<string, int>, projection:array<string, int>, collation:array{locale:string}, partial_filter:null, explain_scenario:string}
     */
    private function definition(
        string $id,
        string $owner,
        string $facade,
        string $name,
        array $keys,
        string $explainScenario,
    ): array {
        return [
            'id' => $id,
            'owner' => $owner,
            'facade' => $facade,
            'name' => $name,
            'keys' => $keys,
            'projection' => ['type' => 1],
            'collation' => ['locale' => 'simple'],
            'partial_filter' => null,
            'explain_scenario' => $explainScenario,
        ];
    }
}
