<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class IsQueryableCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'is_queryable', 'domain' => AccountProfileCapabilityDomain::Relationships->value, 'value_type' => 'boolean', 'default_value' => true, 'fail_closed_value' => false, 'parameters' => [], 'resources' => []];
    }
}
