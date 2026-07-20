<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\AccountProfile;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Serializes cross-Profile references with lifecycle deletion. Target fences
 * are synchronization metadata only, so they never create outbox events.
 */
final class AccountProfileRelationAdmissionService
{
    public function __construct(
        private readonly AccountProfileLifecycleService $lifecycleService,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, AccountProfile>
     */
    public function admit(
        AccountProfileTransactionContext $context,
        ?string $parentProfileId,
        array $attributes,
        bool $touchTargets = true,
    ): array {
        $requirements = $this->requirements($parentProfileId, $attributes);
        $admitted = [];

        foreach ($requirements as $profileId => $requirement) {
            $profile = AccountProfile::withTrashed()->find($profileId);
            if (! $profile instanceof AccountProfile || $profile->deleted_at !== null || ! (bool) $profile->is_active) {
                throw $this->validationFailure($requirement);
            }

            $this->assertEligibility($profile, $requirement);
            $this->lifecycleService->assertProfileMutationAllowed($profile, $context);
            if ($touchTargets) {
                $this->touchTarget($context, $profile);
            }
            $admitted[$profileId] = $profile;
        }

        return $admitted;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{queryable:bool,contact_capable:bool}>
     */
    private function requirements(?string $parentProfileId, array $attributes): array
    {
        $requirements = [];
        $register = static function (string $profileId, string $scope) use (&$requirements): void {
            $profileId = trim($profileId);
            if ($profileId === '') {
                return;
            }
            $requirements[$profileId] ??= ['queryable' => false, 'contact_capable' => false];
            $requirements[$profileId][$scope] = true;
        };

        $contactSourceId = trim((string) ($attributes['contact_source_account_profile_id'] ?? ''));
        if ($contactSourceId !== '') {
            $register($contactSourceId, 'contact_capable');
        }

        foreach ((array) ($attributes['nested_profile_groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ((array) ($group['account_profile_ids'] ?? []) as $profileId) {
                $register((string) $profileId, 'queryable');
            }
        }

        if ($parentProfileId !== null && trim($parentProfileId) !== '') {
            unset($requirements[trim($parentProfileId)]);
        }
        ksort($requirements, SORT_STRING);

        return $requirements;
    }

    /** @param array{queryable:bool,contact_capable:bool} $requirement */
    private function assertEligibility(AccountProfile $profile, array $requirement): void
    {
        $profileType = trim((string) $profile->profile_type);
        if ($requirement['queryable'] && ! $this->typeSetProvider->isQueryable($profileType)) {
            throw $this->validationFailure($requirement);
        }

        if (
            $requirement['contact_capable']
            && (! $this->typeSetProvider->hasContactChannelsEnabled($profileType)
                || trim((string) $profile->contact_mode) !== AccountProfileContactChannelsService::CONTACT_MODE_OWN)
        ) {
            throw $this->validationFailure($requirement);
        }
    }

    private function touchTarget(AccountProfileTransactionContext $context, AccountProfile $profile): void
    {
        $profileId = trim((string) $profile->getKey());
        try {
            $objectId = new ObjectId($profileId);
        } catch (\Throwable) {
            throw new ConcurrencyConflictException('Account Profile relation target id is invalid.');
        }

        $expectedFenceRevision = max(0, (int) $profile->getAttribute('lifecycle_fence_revision'));
        $fenceFilter = ['lifecycle_fence_revision' => $expectedFenceRevision];
        if ($expectedFenceRevision === 0) {
            $fenceFilter = [
                '$or' => [
                    ['lifecycle_fence_revision' => 0],
                    ['lifecycle_fence_revision' => ['$exists' => false]],
                ],
            ];
        }

        $touched = $context->collection('account_profiles')->findOneAndUpdate(
            [
                '_id' => $objectId,
                'is_active' => true,
                'deleted_at' => null,
                ...$fenceFilter,
            ],
            [
                '$inc' => ['lifecycle_fence_revision' => 1],
            ],
            [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($touched === null) {
            throw new ConcurrencyConflictException('Account Profile relation target changed during admission.');
        }
    }

    /** @param array{queryable:bool,contact_capable:bool} $requirement */
    private function validationFailure(array $requirement): ValidationException
    {
        if ($requirement['contact_capable']) {
            return ValidationException::withMessages([
                'contact_source_account_profile_id' => ['Mirrored contact source must be an available own-mode profile in this tenant.'],
            ]);
        }

        return ValidationException::withMessages([
            'nested_profile_groups' => ['Nested profile group includes unavailable or non-queryable profiles.'],
        ]);
    }
}
