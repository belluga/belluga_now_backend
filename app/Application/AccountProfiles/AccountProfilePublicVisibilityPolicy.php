<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountPublicationStateService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Illuminate\Database\Eloquent\Builder;

/** Owns actor-independent public row, detail, and avatar/cover admission. */
final class AccountProfilePublicVisibilityPolicy
{
    /**
     * @param  array<int, string>  $discoverableTypeKeys
     * @param  array<int, string>  $navigableTypeKeys
     * @param  array<int, string>  $nestedParentTypeKeys
     * @param  array<int, string>  $avatarEnabledTypeKeys
     * @param  array<int, string>  $coverEnabledTypeKeys
     */
    public function __construct(
        private readonly array $discoverableTypeKeys,
        private readonly array $navigableTypeKeys,
        private readonly array $nestedParentTypeKeys,
        private readonly array $avatarEnabledTypeKeys = [],
        private readonly array $coverEnabledTypeKeys = [],
    ) {}

    /** @return array<int, string> */
    public function catalogTypeKeys(): array
    {
        return $this->discoverableTypeKeys;
    }

    /** @return array<int, string> */
    public function nestedParentTypeKeys(): array
    {
        return $this->nestedParentTypeKeys;
    }

    public function canDiscoverPublicRow(AccountProfile $profile, ?Account $account): bool
    {
        return $this->isBasePubliclyVisible($profile, $account)
            && in_array(trim((string) $profile->profile_type), $this->discoverableTypeKeys, true);
    }

    public function canOpenPublicDetail(AccountProfile $profile, ?Account $account): bool
    {
        return $this->isBasePubliclyVisible($profile, $account)
            && trim((string) $profile->slug) !== ''
            && in_array(trim((string) $profile->profile_type), $this->navigableTypeKeys, true);
    }

    public function canShowInFavorites(AccountProfile $profile, ?Account $account): bool
    {
        return $this->canOpenPublicDetail($profile, $account);
    }

    public function canExposePublicMedia(AccountProfile $profile, ?Account $account, string $kind): bool
    {
        $capabilityTypes = match ($kind) {
            'avatar' => $this->avatarEnabledTypeKeys,
            'cover' => $this->coverEnabledTypeKeys,
            default => [],
        };

        return $capabilityTypes !== []
            && in_array(trim((string) $profile->profile_type), $capabilityTypes, true)
            && ($this->canDiscoverPublicRow($profile, $account) || $this->canOpenPublicDetail($profile, $account));
    }

    public function isPublicNestedParent(AccountProfile $profile, ?Account $account): bool
    {
        return $this->canOpenPublicDetail($profile, $account)
            && in_array(trim((string) $profile->profile_type), $this->nestedParentTypeKeys, true);
    }

    /** @param Builder<AccountProfile> $query */
    public function applyCatalogConstraint(Builder $query, bool $requireSlug = false): Builder
    {
        return $query->whereRaw($this->catalogMatchExpression($requireSlug));
    }

    /** @return array<string, mixed> */
    public function catalogMatchExpression(bool $requireSlug = false): array
    {
        return $this->matchExpressionForTypeKeys($this->discoverableTypeKeys, $requireSlug);
    }

    /** @return array<string, mixed> */
    public function publicDetailMatchExpression(bool $requireSlug = false): array
    {
        return $this->matchExpressionForTypeKeys($this->navigableTypeKeys, $requireSlug);
    }

    /** @return array<int, array{\$match: array<string, mixed>}> */
    public function catalogLookupPipeline(bool $requireSlug = false): array
    {
        return [['$match' => $this->catalogMatchExpression($requireSlug)]];
    }

    /** @return array<int, array{\$match: array<string, mixed>}> */
    public function nestedParentLookupPipeline(bool $requireSlug = false): array
    {
        return [['$match' => $this->matchExpressionForTypeKeys($this->nestedParentTypeKeys, $requireSlug)]];
    }

    /** @return array<string, mixed> */
    public function publishedParentAccountMatchExpression(): array
    {
        return ['publication.status' => AccountPublicationStateService::PUBLISHED, 'deleted_at' => null];
    }

    /** @param array<int, string> $typeKeys @return array<string, mixed> */
    public function matchExpressionForTypeKeys(array $typeKeys, bool $requireSlug = false): array
    {
        if ($typeKeys === []) {
            return ['_id' => ['$exists' => false]];
        }
        $clauses = [['is_active' => true], ['deleted_at' => null], ['visibility' => 'public'], ['profile_type' => ['$in' => $typeKeys]], ['profile_type' => ['$ne' => 'personal']]];
        if ($requireSlug) {
            $clauses[] = ['slug' => ['$regex' => '\\S']];
        }

        return ['$and' => $clauses];
    }

    private function isBasePubliclyVisible(AccountProfile $profile, ?Account $account): bool
    {
        return $profile->getAttribute('is_active') === true
            && $profile->getAttribute('deleted_at') === null
            && trim((string) $profile->getAttribute('visibility')) === 'public'
            && trim((string) $profile->getAttribute('profile_type')) !== 'personal'
            && $account instanceof Account
            && $account->getAttribute('deleted_at') === null
            && is_array($account->getAttribute('publication'))
            && ($account->getAttribute('publication')['status'] ?? null) === AccountPublicationStateService::PUBLISHED;
    }
}
