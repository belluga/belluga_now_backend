<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class IsFavoritableCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'is_favoritable', 'domain' => AccountProfileCapabilityDomain::Relationships->value, 'value_type' => 'boolean', 'default_value' => false, 'fail_closed_value' => false, 'parameters' => [], 'resources' => []];
    }
}
