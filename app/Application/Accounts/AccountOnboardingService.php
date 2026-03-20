<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountOnboardingService
{
    public function __construct(
        private readonly AccountManagementService $accountService,
        private readonly AccountProfileManagementService $profileService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileRegistrySeeder $registrySeeder,
        private readonly AccountProfileRegistryService $registryService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, account_profile: AccountProfile, role: AccountRoleTemplate}
     */
    public function create(array $payload, Request $request): array
    {
        $this->registrySeeder->ensureDefaults();

        try {
            return DB::connection('tenant')->transaction(function () use ($payload, $request): array {
                $accountResult = $this->accountService->createWithinCurrentTransaction([
                    'name' => $payload['name'],
                    'ownership_state' => $payload['ownership_state'],
                    'created_by' => $payload['created_by'] ?? null,
                    'created_by_type' => $payload['created_by_type'] ?? null,
                    'updated_by' => $payload['updated_by'] ?? null,
                    'updated_by_type' => $payload['updated_by_type'] ?? null,
                ]);

                $account = $accountResult['account'];
                $role = $accountResult['role'];

                $this->assertLocationKeysForPoiProfile($payload);

                $profile = $this->profileService->createWithinCurrentTransaction([
                    'account_id' => (string) $account->_id,
                    'profile_type' => $payload['profile_type'],
                    'display_name' => $payload['name'],
                    'location' => $payload['location'] ?? null,
                    'taxonomy_terms' => $payload['taxonomy_terms'] ?? [],
                    'bio' => $payload['bio'] ?? null,
                    'content' => $payload['content'] ?? null,
                    'created_by' => $payload['created_by'] ?? null,
                    'created_by_type' => $payload['created_by_type'] ?? null,
                    'updated_by' => $payload['updated_by'] ?? null,
                    'updated_by_type' => $payload['updated_by_type'] ?? null,
                ]);

                $this->mediaService->applyUploads($request, $profile);

                return [
                    'account' => $account->fresh(),
                    'account_profile' => $profile->fresh(),
                    'role' => $role->fresh(),
                ];
            });
        } catch (ValidationException $exception) {
            throw $this->normalizeValidationException($exception);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'account' => ['Account onboarding could not be completed.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertLocationKeysForPoiProfile(array $payload): void
    {
        $profileType = (string) ($payload['profile_type'] ?? '');
        if ($profileType === '' || ! $this->registryService->isPoiEnabled($profileType)) {
            return;
        }

        $location = $payload['location'] ?? null;
        $messages = [];
        if (! is_array($location)) {
            $messages[] = 'Location is required for POI-enabled profiles.';
        } else {
            if (! array_key_exists('lat', $location) || $location['lat'] === null || $location['lat'] === '') {
                $messages[] = 'Latitude is required for POI-enabled profiles.';
            }
            if (! array_key_exists('lng', $location) || $location['lng'] === null || $location['lng'] === '') {
                $messages[] = 'Longitude is required for POI-enabled profiles.';
            }
        }

        if ($messages === []) {
            return;
        }

        throw ValidationException::withMessages([
            'location' => $messages,
            'location.lat' => $messages,
            'location.lng' => $messages,
        ]);
    }

    private function normalizeValidationException(
        ValidationException $exception,
    ): ValidationException {
        $errors = $exception->errors();
        if (! array_key_exists('location', $errors)) {
            return $exception;
        }

        if (
            array_key_exists('location.lat', $errors) &&
            array_key_exists('location.lng', $errors)
        ) {
            return $exception;
        }

        $messages = $errors['location'];
        $errors['location.lat'] = $errors['location.lat'] ?? $messages;
        $errors['location.lng'] = $errors['location.lng'] ?? $messages;

        return ValidationException::withMessages($errors);
    }
}
