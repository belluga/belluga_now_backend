<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class HasNestedProfileGroupsCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'has_nested_profile_groups', 'domain' => AccountProfileCapabilityDomain::Relationships->value, 'value_type' => 'boolean', 'default_value' => false, 'fail_closed_value' => false, 'parameters' => [], 'resources' => []];
    }
}
