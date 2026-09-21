<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class LocationPolicyCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'location_policy', 'domain' => AccountProfileCapabilityDomain::Location->value, 'value_type' => 'enum', 'default_value' => 'disabled', 'fail_closed_value' => 'disabled', 'allowed_values' => ['disabled', 'optional', 'required'], 'parameters' => [], 'resources' => []];
    }
}
