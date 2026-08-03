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
use Illuminate\Http\UploadedFile;
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
    public function create(
        array $payload,
        Request $request,
        ?string $commandId = null,
    ): array {
        if ((string) ($payload['profile_type'] ?? '') === 'personal') {
            $this->registrySeeder->ensurePersonalDefault();
        }
        $commandId = $this->normalizeCommandId($commandId);
        $mediaFingerprint = $this->mediaService->mutationFingerprint($request);
        $fingerprint = $this->fingerprint($payload, $mediaFingerprint);
        $uploadedProfile = null;

        try {
            $result = $this->transactionRunner->run(function (
                AccountProfileTransactionContext $context,
            ) use ($payload, $request, $commandId, $fingerprint, $mediaFingerprint, &$uploadedProfile): array {
                $existing = $this->profileService->resultForCommand(
                    $context,
                    $commandId,
                    $fingerprint,
                );
                if ($existing !== null) {
                    return $this->replayResult(
                        $existing['profile'],
                        $existing['outbox_event_id'],
                    );
                }

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
                    $mediaFingerprint === []
                        ? null
                        : function (AccountProfile $persistedProfile) use ($request, &$uploadedProfile): void {
                            $uploadedProfile = $persistedProfile;
                            $this->mediaService->applyUploads($request, $persistedProfile);
                        },
                );
                $profile = $profileResult['profile'];

                return [
                    'account' => $account->fresh(),
                    'account_profile' => $profile->fresh(),
                    'role' => $role->fresh(),
                    'outbox_event_id' => $profileResult['outbox_event_id'],
                ];
            }, fn (): ?array => $this->reconcileCommittedResult($commandId, $fingerprint));
            $uploadedProfile = null;

            if (($result['outbox_event_id'] ?? null) !== null) {
                $this->outboxDispatcher->dispatchEvent($result['outbox_event_id']);
            }

            unset($result['outbox_event_id']);

            return $result;
        } catch (ValidationException $exception) {
            $this->cleanupUploadedProfileMedia($uploadedProfile);
            throw $this->normalizeValidationException($exception);
        } catch (Throwable $exception) {
            $this->cleanupUploadedProfileMedia($uploadedProfile);
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

    private function normalizeFingerprintValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'uploaded_file' => true,
                'original_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
                'error' => $value->getError(),
            ];
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeFingerprintValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $mediaFingerprint
     */
    private function fingerprint(array $payload, array $mediaFingerprint): string
    {
        return hash(
            'sha256',
            json_encode([
                'payload' => $this->normalizeFingerprintValue($payload),
                'media' => $this->normalizeFingerprintValue($mediaFingerprint),
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function normalizeCommandId(?string $commandId): string
    {
        $normalized = trim((string) $commandId);

        return $normalized === ''
            ? 'account-onboarding:'.Str::uuid()->toString()
            : $normalized;
    }

    /**
     * @return array{account: Account, account_profile: AccountProfile, role: AccountRoleTemplate, outbox_event_id:?string}
     */
    private function replayResult(AccountProfile $profile, ?string $outboxEventId): array
    {
        $account = Account::query()->findOrFail((string) $profile->account_id);
        $role = $account->roleTemplates()->orderBy('created_at')->firstOrFail();

        return [
            'account' => $account->fresh(),
            'account_profile' => $profile->fresh(),
            'role' => $role->fresh(),
            'outbox_event_id' => $outboxEventId,
        ];
    }

    /** @return array{account: Account, account_profile: AccountProfile, role: AccountRoleTemplate, outbox_event_id:?string}|null */
    private function reconcileCommittedResult(string $commandId, string $fingerprint): ?array
    {
        $existing = $this->profileService->resultForCommittedCommand($commandId, $fingerprint);

        return $existing === null
            ? null
            : $this->replayResult($existing['profile'], $existing['outbox_event_id']);
    }

    private function cleanupUploadedProfileMedia(?AccountProfile $profile): void
    {
        if (! $profile instanceof AccountProfile) {
            return;
        }

        try {
            $this->mediaService->removeAllUploads($profile);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
