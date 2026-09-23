<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class MapPoiCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'is_map_poi_enabled', 'domain' => AccountProfileCapabilityDomain::Location->value, 'value_type' => 'boolean', 'default_value' => false, 'fail_closed_value' => false, 'dependencies' => [['capability_key' => 'location_policy', 'accepted_values' => ['optional', 'required']]], 'parameters' => [], 'resources' => []];
    }
}
