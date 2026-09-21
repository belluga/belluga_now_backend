<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class HasContactChannelsCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'has_contact_channels', 'domain' => AccountProfileCapabilityDomain::ProfileContent->value, 'value_type' => 'boolean', 'default_value' => false, 'fail_closed_value' => false, 'parameters' => [], 'resources' => []];
    }
}
