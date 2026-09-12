<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Application\AccountProfiles\HomeFavoritesPinnedProfileService;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Validation\ValidationException;

final class HomeFavoritesPinnedProfileSettingsPatchGuard
{
    public function __construct(private readonly HomeFavoritesPinnedProfileService $service) {}

    /** @param array<string, mixed> $payload */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {
        if ($scope !== 'tenant' || $namespace !== HomeFavoritesPinnedProfileService::NAMESPACE) {
            return;
        }

        if (array_keys($payload) !== ['account_profile_id']) {
            throw ValidationException::withMessages([
                'account_profile_id' => ['Send exactly the account_profile_id field.'],
            ]);
        }

        $profileId = $payload['account_profile_id'];
        if ($profileId === null) {
            return;
        }

        if (! is_string($profileId) || $this->service->findEligibleProfile($profileId) === null) {
            throw ValidationException::withMessages([
                'account_profile_id' => ['The selected Account Profile is not eligible.'],
            ]);
        }
    }
}
