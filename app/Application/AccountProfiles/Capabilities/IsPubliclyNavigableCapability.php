<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class IsPubliclyNavigableCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'is_publicly_navigable', 'domain' => AccountProfileCapabilityDomain::Visibility->value, 'value_type' => 'boolean', 'default_value' => true, 'fail_closed_value' => false, 'parameters' => [], 'resources' => []];
    }
}
