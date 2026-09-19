<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class HasTaxonomiesCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return ['key' => 'has_taxonomies', 'domain' => AccountProfileCapabilityDomain::ProfileContent->value, 'value_type' => 'boolean', 'default_value' => false, 'fail_closed_value' => false, 'parameters' => [], 'resources' => []];
    }
}
