<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Taxonomies\TaxonomyValidationService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountProfileManagementService
{
    private const LAST_PROFILE_ERROR_KEY = 'account_profile_lifecycle';

    private const LAST_PROFILE_ERROR_MESSAGE = 'A live account must keep at least one active account profile. Delete the account aggregate instead.';

    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly MapPoiProjectionService $mapPoiProjectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): AccountProfile
    {
        return DB::connection('tenant')->transaction(
            fn (): AccountProfile => $this->createWithinCurrentTransaction($payload)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createWithinCurrentTransaction(array $payload): AccountProfile
    {
        $payload = AccountProfileRichTextSanitizer::sanitizePayload($payload);

        $profileType = (string) $payload['profile_type'];

        if (! $this->registryService->typeDefinition($profileType)) {
            throw ValidationException::withMessages([
                'profile_type' => ['Profile type is not supported for this tenant.'],
            ]);
        }

        $accountId = (string) $payload['account_id'];
        if (! Account::query()->where('_id', $accountId)->exists()) {
            throw ValidationException::withMessages([
                'account_id' => ['Account not found.'],
            ]);
        }

        if ($this->registryService->isPoiEnabled($profileType)) {
            $location = $payload['location'] ?? null;
            if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                throw ValidationException::withMessages([
                    'location' => ['Location is required for POI-enabled profiles.'],
                ]);
            }
        }

        $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
        if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
            $this->taxonomyValidationService->assertTermsAllowedForAccountProfile(
                $profileType,
                $taxonomyTerms
            );
            $payload['taxonomy_terms'] = $this->taxonomyTermSummaryResolver->resolve($taxonomyTerms);
            $payload['taxonomy_terms_flat'] = $this->flattenTaxonomyTerms($payload['taxonomy_terms']);
        } elseif (array_key_exists('taxonomy_terms', $payload)) {
            $payload['taxonomy_terms'] = [];
            $payload['taxonomy_terms_flat'] = [];
        }

        if (array_key_exists('nested_profile_groups', $payload)) {
            $this->assertNestedProfileGroupsAllowed(
                $profileType,
                $payload['nested_profile_groups']
            );
            $payload['nested_profile_groups'] = $this->nestedGroupService->normalizeForWrite(
                $payload['nested_profile_groups']
            );
        }

        try {
            if (! array_key_exists('is_active', $payload)) {
                $payload['is_active'] = true;
            }
            $payload['account_id'] = (string) $payload['account_id'];
            $payload['location'] = $this->formatLocation($payload['location'] ?? null);

            $profile = AccountProfile::create($payload)->fresh();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'account_profile' => ['Account profile already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account_profile' => ['Something went wrong when trying to create the account profile.'],
            ]);
        }

        $this->queueMapPoiSyncAfterCommit($profile);

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AccountProfile $profile, array $attributes): AccountProfile
    {
        $attributes = AccountProfileRichTextSanitizer::sanitizePayload($attributes);

        $profileType = $profile->profile_type;
        if (array_key_exists('profile_type', $attributes)) {
            $profileType = (string) $attributes['profile_type'];
        }

        if ($profileType && ! $this->registryService->typeDefinition($profileType)) {
            throw ValidationException::withMessages([
                'profile_type' => ['Profile type is not supported for this tenant.'],
            ]);
        }

        if ($profileType && $this->registryService->isPoiEnabled($profileType)) {
            if (array_key_exists('location', $attributes)) {
                $location = $attributes['location'] ?? null;
                if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                    throw ValidationException::withMessages([
                        'location' => ['Location is required for POI-enabled profiles.'],
                    ]);
                }
            }
        }

        if (array_key_exists('taxonomy_terms', $attributes)) {
            $taxonomyTerms = $attributes['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForAccountProfile(
                    $profileType,
                    $taxonomyTerms
                );
                $attributes['taxonomy_terms'] = $this->taxonomyTermSummaryResolver->resolve($taxonomyTerms);
                $attributes['taxonomy_terms_flat'] = $this->flattenTaxonomyTerms($attributes['taxonomy_terms']);
            } else {
                $attributes['taxonomy_terms'] = [];
                $attributes['taxonomy_terms_flat'] = [];
            }
        }

        if (array_key_exists('location', $attributes)) {
            $attributes['location'] = $this->formatLocation($attributes['location']);
        }

        if (array_key_exists('nested_profile_groups', $attributes)) {
            $this->assertNestedProfileGroupsAllowed(
                $profileType,
                $attributes['nested_profile_groups']
            );
            $attributes['nested_profile_groups'] = $this->nestedGroupService->normalizeForWrite(
                $attributes['nested_profile_groups'],
                (string) $profile->getKey()
            );
        }

        try {
            $profile->fill($attributes);
            $profile->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Account profile slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account_profile' => ['Something went wrong when trying to update the account profile.'],
            ]);
        }

        $profile = $profile->fresh();
        $this->syncMapPoiProjectionById((string) $profile->_id);

        return $profile;
    }

    public function delete(AccountProfile $profile): void
    {
        $profileId = (string) $profile->_id;

        DB::connection('tenant')->transaction(function () use ($profile): void {
            $this->assertProfileMayBeSoftDeleted($profile);
            $profile->delete();
        });

        $this->queueMapPoiDeleteAfterCommit($profileId);
    }

    public function restore(AccountProfile $profile): AccountProfile
    {
        $profile->restore();

        $profile = $profile->fresh();
        $this->syncMapPoiProjectionById((string) $profile->_id);

        return $profile;
    }

    public function forceDelete(AccountProfile $profile): void
    {
        $profileId = (string) $profile->_id;

        DB::connection('tenant')->transaction(function () use ($profile): void {
            $this->assertProfileMayBeForceDeleted($profile);
            $profile->forceDelete();
        });

        $this->queueMapPoiDeleteAfterCommit($profileId);
    }

    private function assertNestedProfileGroupsAllowed(string $profileType, mixed $rawGroups): void
    {
        if ($this->registryService->hasNestedProfileGroups($profileType)) {
            return;
        }

        if ($this->nestedProfileGroupsPayloadIsEmpty($rawGroups)) {
            return;
        }

        throw ValidationException::withMessages([
            'nested_profile_groups' => ['Nested profile groups are not enabled for this profile type.'],
        ]);
    }

    private function nestedProfileGroupsPayloadIsEmpty(mixed $rawGroups): bool
    {
        if (! is_array($rawGroups)) {
            return true;
        }

        foreach ($rawGroups as $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }
            $label = trim((string) ($rawGroup['label'] ?? ''));
            $memberIds = $rawGroup['account_profile_ids'] ?? $rawGroup['profile_ids'] ?? [];
            if ($label !== '' || (is_array($memberIds) && $memberIds !== [])) {
                return false;
            }
        }

        return true;
    }

    private function assertProfileMayBeSoftDeleted(AccountProfile $profile): void
    {
        if (! $this->isActiveProfile($profile)) {
            return;
        }

        $account = $this->liveAccountForProfile($profile);
        if (! $account) {
            return;
        }

        $this->touchAccountLifecycleGuard($account);

        if ($this->activeProfileCount((string) $account->_id) <= 1) {
            $this->throwLastProfileValidationException();
        }
    }

    private function assertProfileMayBeForceDeleted(AccountProfile $profile): void
    {
        $account = $this->liveAccountForProfile($profile);
        if (! $account) {
            return;
        }

        $this->touchAccountLifecycleGuard($account);

        $accountId = (string) $account->_id;
        $activeCount = $this->activeProfileCount($accountId);

        if ($this->isActiveProfile($profile) && $activeCount <= 1) {
            $this->throwLastProfileValidationException();
        }

        if ($this->isTrashedProfile($profile) && $activeCount === 0 && $this->restorableProfileCount($accountId) <= 1) {
            $this->throwLastProfileValidationException();
        }
    }

    private function liveAccountForProfile(AccountProfile $profile): ?Account
    {
        $accountId = trim((string) $profile->account_id);
        if ($accountId === '') {
            return null;
        }

        return Account::query()->where('_id', $accountId)->first();
    }

    private function touchAccountLifecycleGuard(Account $account): void
    {
        $account->setAttribute('profile_lifecycle_guarded_at', now()->toJSON());
        $account->save();
    }

    private function activeProfileCount(string $accountId): int
    {
        return AccountProfile::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->count();
    }

    private function restorableProfileCount(string $accountId): int
    {
        return AccountProfile::onlyTrashed()
            ->where('account_id', $accountId)
            ->count();
    }

    private function isActiveProfile(AccountProfile $profile): bool
    {
        return ! $this->isTrashedProfile($profile) && (bool) $profile->is_active;
    }

    private function isTrashedProfile(AccountProfile $profile): bool
    {
        return $profile->deleted_at !== null;
    }

    private function throwLastProfileValidationException(): void
    {
        throw ValidationException::withMessages([
            self::LAST_PROFILE_ERROR_KEY => [self::LAST_PROFILE_ERROR_MESSAGE],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'type' => 'Point',
            'coordinates' => [(float) $lng, (float) $lat],
        ];
    }

    private function queueMapPoiSyncAfterCommit(AccountProfile $profile): void
    {
        $profileId = (string) $profile->_id;
        DB::connection('tenant')->afterCommit(
            fn () => $this->syncMapPoiProjectionById($profileId)
        );
    }

    private function queueMapPoiDeleteAfterCommit(string $profileId): void
    {
        DB::connection('tenant')->afterCommit(
            static fn () => DeleteMapPoiByRefJob::dispatch('account_profile', $profileId)
        );
    }

    private function syncMapPoiProjectionById(string $profileId): void
    {
        $profile = AccountProfile::query()->find($profileId);
        if (! $profile) {
            $this->mapPoiProjectionService->deleteByRef('account_profile', $profileId);

            return;
        }

        $this->mapPoiProjectionService->upsertFromAccountProfile($profile);
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    private function flattenTaxonomyTerms(array $terms): array
    {
        $flat = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type !== '' && $value !== '') {
                $flat[] = "{$type}:{$value}";
            }
        }

        return array_values(array_unique($flat));
    }
}
