<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

use App\Models\Tenants\TenantProfileType;

final class NullAccountProfileCapabilityOverrideProvider implements AccountProfileCapabilityOverrideProviderContract
{
    public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array
    {
        return null;
    }
}
