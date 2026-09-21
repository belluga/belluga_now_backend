<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfileTypeIndexManifest
{
    /** @var array<int, array{id:string, capability:string, name:string}> */
    private const CAPABILITY_FLAG_INDEXES = [
        ['id' => 'C-03', 'capability' => 'is_publicly_discoverable', 'name' => 'idx_account_profile_types_capability_is_publicly_discoverable_v1'],
        ['id' => 'C-04', 'capability' => 'is_favoritable', 'name' => 'idx_account_profile_types_capability_is_favoritable_v1'],
        ['id' => 'C-05', 'capability' => 'is_inviteable', 'name' => 'idx_account_profile_types_capability_is_inviteable_v1'],
        ['id' => 'C-08', 'capability' => 'has_bio', 'name' => 'idx_account_profile_types_capability_has_bio_v1'],
        ['id' => 'C-10', 'capability' => 'has_taxonomies', 'name' => 'idx_account_profile_types_capability_has_taxonomies_v1'],
        ['id' => 'C-11', 'capability' => 'has_avatar', 'name' => 'idx_account_profile_types_capability_has_avatar_v1'],
        ['id' => 'C-12', 'capability' => 'has_cover', 'name' => 'idx_account_profile_types_capability_has_cover_v1'],
        ['id' => 'C-13', 'capability' => 'has_events', 'name' => 'idx_account_profile_types_capability_has_events_v1'],
        ['id' => 'C-14', 'capability' => 'has_gallery', 'name' => 'idx_account_profile_types_capability_has_gallery_v1'],
        ['id' => 'C-15', 'capability' => 'has_nested_profile_groups', 'name' => 'idx_account_profile_types_capability_has_nested_profile_groups_v1'],
        ['id' => 'C-17', 'capability' => 'has_external_links', 'name' => 'idx_account_profile_types_capability_has_external_links_v1'],
    ];

    /**
     * @return array<int, array{id:string, owner:string, facade:string, name:string, keys:array<string, int>, projection:array<string, int>, collation:array{locale:string}, partial_filter:null, explain_scenario:string}>
     */
    public function definitions(): array
    {
        $definitions = [
            $this->definition('M-01', 'U04 DDL / U06 query', 'queryable', 'idx_account_profile_types_candidate_queryable_v2', ['capabilities.is_queryable.value' => 1, 'type' => 1], 'candidate browse/search type-key read'),
            $this->definition('M-02', 'U04 DDL / U06 query', 'contactChannelsEnabled', 'idx_account_profile_types_candidate_contact_capable_v2', ['capabilities.has_contact_channels.value' => 1, 'type' => 1], 'Contact-channel type-key read'),
            $this->definition('M-03', 'U04', 'publiclyNavigable', 'idx_account_profile_types_public_navigation_v2', ['capabilities.is_publicly_navigable.value' => 1, 'type' => 1], 'Event public navigation type-key read'),
            $this->definition('M-04', 'U04 / U07', 'publiclyDiscoverable/publicCatalog', 'idx_account_profile_types_public_discovery_v2', ['capabilities.is_queryable.value' => 1, 'capabilities.is_publicly_discoverable.value' => 1, 'type' => 1], 'discoverable type-set and U07 direct catalog snapshot'),
            $this->definition('M-05', 'U04+05', 'mapPoiEnabled', 'idx_account_profile_types_map_poi_location_policy_v1', ['capabilities.is_map_poi_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1], 'Map projection type-set read'),
            $this->definition('M-06', 'U04+05', 'physicalHostEnabled', 'idx_account_profile_types_physical_host_location_policy_v1', ['capabilities.is_physical_host_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1], 'Event physical-host resolver'),
            $this->definition('M-07', 'U04+05', 'referenceLocationEnabled', 'idx_account_profile_types_reference_location_policy_v1', ['capabilities.is_reference_location_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1], 'Reference-location resolver'),
            $this->definition('M-08', 'U04+05', 'locationEnabled', 'idx_account_profile_types_location_policy_v1', ['capabilities.location_policy.value' => 1, 'type' => 1], 'Location-enabled type-set read'),
            $this->definition('M-09', 'U04+05 current consumer', 'publicPoiCatalog', 'idx_account_profile_types_public_map_poi_v1', ['capabilities.is_queryable.value' => 1, 'capabilities.is_publicly_discoverable.value' => 1, 'capabilities.is_map_poi_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1], 'Existing public Map catalog type-set read'),
            $this->definition('M-10', 'U04+05 current consumer', 'publicPhysicalHost', 'idx_account_profile_types_public_physical_host_v1', ['capabilities.is_queryable.value' => 1, 'capabilities.is_publicly_discoverable.value' => 1, 'capabilities.is_physical_host_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1], 'Existing public physical-host type-set read'),
            $this->definition('M-11', 'U04+05 current consumer', 'publiclyNavigablePhysicalHost', 'idx_account_profile_types_navigable_physical_host_v1', ['capabilities.is_publicly_navigable.value' => 1, 'capabilities.is_physical_host_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1], 'Existing navigable physical-host type-set read'),
        ];

        foreach (self::CAPABILITY_FLAG_INDEXES as $index) {
            $definitions[] = $this->definition(
                $index['id'],
                'U04',
                'capability flag',
                $index['name'],
                ["capabilities.{$index['capability']}.value" => 1, 'type' => 1],
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
