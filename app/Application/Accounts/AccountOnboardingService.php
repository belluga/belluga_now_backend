<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileCommandIndeterminateException;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileOutboxPublisher;
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
        private readonly AccountProfileOutboxPublisher $outboxPublisher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, account_profile: AccountProfile, role: AccountRoleTemplate}
     */
    public function create(array $payload, Request $request): array
    {
        $this->registrySeeder->ensureDefaults();
        $commandId = trim((string) $request->header('X-Request-Id'));
        if ($commandId === '') {
            $commandId = (string) Str::uuid();
        }
        $fingerprint = $this->outboxPublisher->fingerprintForCreate(
            $this->fingerprintPayload($payload),
        );
        $mediaBackup = null;

        try {
            /** @var array{account:Account,account_profile:AccountProfile,role:AccountRoleTemplate,outbox_event_id:?string} $result */
            $result = $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use (
                $payload,
                $request,
                $commandId,
                $fingerprint,
                &$mediaBackup,
            ): array {
                $existing = $this->profileService->resultForCommand($context, $commandId, $fingerprint);
                if ($existing !== null) {
                    return $this->resultForRecordedProfile(
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

                $profile = $this->profileService->createWithinCurrentTransaction(
                    [...$profilePayload, 'aggregate_revision' => 1],
                    $context,
                );

                $mediaBackup ??= $this->mediaService->captureMutationBackup($request, $profile);
                $this->mediaService->applyUploads($request, $profile);
                $profile = $profile->fresh();
                $outboxEventId = $this->profileService->recordCreatedProfile(
                    $context,
                    $profile,
                    $commandId,
                    $fingerprint,
                );

                return [
                    'account' => $account->fresh(),
                    'account_profile' => $profile->fresh(),
                    'role' => $role->fresh(),
                    'outbox_event_id' => $outboxEventId,
                ];
            }, function () use ($commandId, $fingerprint): ?array {
                $existing = $this->profileService->resultForCommittedCommand($commandId, $fingerprint);

                return $existing === null
                    ? null
                    : $this->resultForRecordedProfile($existing['profile'], $existing['outbox_event_id']);
            });
            $this->profileService->dispatchOutboxEvent($result['outbox_event_id']);

            return [
                'account' => $result['account'],
                'account_profile' => $result['account_profile'],
                'role' => $result['role'],
            ];
        } catch (AccountProfileCommandIndeterminateException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->restoreKnownRollbackMedia($mediaBackup);

            throw $this->normalizeValidationException($exception);
        } catch (Throwable $exception) {
            $this->restoreKnownRollbackMedia($mediaBackup);
            report($exception);

            throw ValidationException::withMessages([
                'account' => ['Account onboarding could not be completed.'],
            ]);
        }
    }

    /**
     * @return array{account:Account,account_profile:AccountProfile,role:AccountRoleTemplate,outbox_event_id:?string}
     */
    private function resultForRecordedProfile(AccountProfile $profile, ?string $outboxEventId): array
    {
        $account = Account::query()->findOrFail((string) $profile->account_id);
        $role = $account->roleTemplates()->orderBy('_id')->firstOrFail();

        return [
            'account' => $account->fresh(),
            'account_profile' => $profile->fresh(),
            'role' => $role->fresh(),
            'outbox_event_id' => $outboxEventId,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function fingerprintPayload(array $payload): array
    {
        foreach (['avatar', 'cover'] as $key) {
            $file = $payload[$key] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->getRealPath();
            $payload[$key] = [
                'sha256' => is_string($path) && $path !== '' ? hash_file('sha256', $path) : null,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];
        }

        return $payload;
    }

    private function restoreKnownRollbackMedia(?\App\Application\AccountProfiles\AccountProfileMediaMutationBackup $backup): void
    {
        if ($backup === null) {
            return;
        }

        try {
            $backup->restore();
        } catch (Throwable $exception) {
            report($exception);
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
