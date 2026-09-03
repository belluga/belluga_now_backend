<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;

/**
 * Resolves the current authoring capacity for one Account Profile.
 *
 * Configuration is the temporary source for this delivery. The resolver is
 * intentionally profile-aware so the future plan-capacity provider can be
 * substituted without changing the external-links API or persistence code.
 */
final class AccountProfileExternalLinkLimitResolver
{
    public function resolve(?AccountProfile $profile = null): int
    {
        unset($profile);

        return max(0, (int) config('external_links.max_per_profile'));
    }
}
