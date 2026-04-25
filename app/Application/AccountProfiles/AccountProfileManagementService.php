<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Taxonomies\TaxonomyValidationService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountProfileManagementService
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
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
        UpsertMapPoiFromAccountProfileJob::dispatch((string) $profile->_id);

        return $profile;
    }

    public function delete(AccountProfile $profile): void
    {
        $profile->delete();
        DeleteMapPoiByRefJob::dispatch('account_profile', (string) $profile->_id);
    }

    public function restore(AccountProfile $profile): AccountProfile
    {
        $profile->restore();

        $profile = $profile->fresh();
        $this->queueMapPoiSyncAfterCommit($profile);

        return $profile;
    }

    public function forceDelete(AccountProfile $profile): void
    {
        $profile->forceDelete();
        DeleteMapPoiByRefJob::dispatch('account_profile', (string) $profile->_id);
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
        DB::connection('tenant')->afterCommit(
            static fn () => UpsertMapPoiFromAccountProfileJob::dispatch((string) $profile->_id)
        );
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
