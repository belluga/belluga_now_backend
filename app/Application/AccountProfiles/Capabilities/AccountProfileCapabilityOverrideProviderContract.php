<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

use App\Models\Tenants\TenantProfileType;

interface AccountProfileCapabilityOverrideProviderContract
{
    /** @return array<string, mixed>|null */
    public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array;
}
