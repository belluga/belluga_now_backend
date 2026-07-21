<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, account_profile: AccountProfile, role: AccountRoleTemplate}
     */
    public function create(array $payload, Request $request): array
    {
        $this->registrySeeder->ensureDefaults();
        $commandId = 'account-onboarding:'.Str::uuid()->toString();
        $fingerprint = hash(
            'sha256',
            json_encode([
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR),
        );

        try {
            $result = $this->transactionRunner->run(function (
                AccountProfileTransactionContext $context,
            ) use ($payload, $request, $commandId, $fingerprint): array {
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

                $profilePayload = [
                    'account_id' => (string) $account->_id,
                    'profile_type' => $payload['profile_type'],
                    'display_name' => $payload['name'],
                    'location' => $payload['location'] ?? null,
                    'taxonomy_terms' => $payload['taxonomy_terms'] ?? [],
                    'bio' => $payload['bio'] ?? null,
                    'content' => $payload['content'] ?? null,
                    'nested_profile_groups' => $payload['nested_profile_groups'] ?? [],
                    'created_by' => $payload['created_by'] ?? null,
                    'created_by_type' => $payload['created_by_type'] ?? null,
                    'updated_by' => $payload['updated_by'] ?? null,
                    'updated_by_type' => $payload['updated_by_type'] ?? null,
                ];

                foreach ([
                    'contact_mode',
                    'contact_source_account_profile_id',
                    'contact_channels',
                    'contact_bubble_channel_id',
                    'contact_bubble_channel_draft_key',
                ] as $contactKey) {
                    if (array_key_exists($contactKey, $payload)) {
                        $profilePayload[$contactKey] = $payload[$contactKey];
                    }
                }

                $profileResult = $this->profileService->createWithinTransactionContext(
                    $profilePayload,
                    $context,
                    $commandId,
                    $fingerprint,
                );
                $profile = $profileResult['profile'];

                $this->mediaService->applyUploads($request, $profile);

                return [
                    'account' => $account->fresh(),
                    'account_profile' => $profile->fresh(),
                    'role' => $role->fresh(),
                    'outbox_event_id' => $profileResult['outbox_event_id'],
                ];
            });

            if (($result['outbox_event_id'] ?? null) !== null) {
                $this->outboxDispatcher->dispatchEvent($result['outbox_event_id']);
            }

            unset($result['outbox_event_id']);

            return $result;
        } catch (ValidationException $exception) {
            throw $this->normalizeValidationException($exception);
        } catch (Throwable $exception) {
            report($exception);
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
