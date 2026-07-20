<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owns the public Profile instance predicate for one request-local type snapshot.
 */
final class AccountProfilePublicCatalogEligibilityPolicy
{
    /** @var array<int, string> */
    private readonly array $catalogTypeKeys;

    /** @var array<int, string> */
    private readonly array $nestedParentTypeKeys;

    /**
     * @param  array<int, string>  $catalogTypeKeys
     * @param  array<int, string>  $nestedParentTypeKeys
     */
    public function __construct(array $catalogTypeKeys, array $nestedParentTypeKeys)
    {
        $this->catalogTypeKeys = $this->normalizeTypeKeys($catalogTypeKeys);
        $this->nestedParentTypeKeys = $this->normalizeTypeKeys($nestedParentTypeKeys);
    }

    /**
     * @return array<int, string>
     */
    public function catalogTypeKeys(): array
    {
        return $this->catalogTypeKeys;
    }

    /**
     * @return array<int, string>
     */
    public function nestedParentTypeKeys(): array
    {
        return $this->nestedParentTypeKeys;
    }

    public function isPubliclyExposed(AccountProfile $profile): bool
    {
        return $profile->getAttribute('is_active') === true
            && $profile->getAttribute('deleted_at') === null
            && trim((string) $profile->getAttribute('visibility')) === 'public'
            && $this->hasCatalogType((string) $profile->getAttribute('profile_type'));
    }

    public function canOpenPublicDetail(AccountProfile $profile): bool
    {
        return $this->isPubliclyExposed($profile)
            && trim((string) $profile->getAttribute('slug')) !== '';
    }

    public function isPublicNestedParent(AccountProfile $profile): bool
    {
        return $this->isPubliclyExposed($profile)
            && in_array(
                trim((string) $profile->getAttribute('profile_type')),
                $this->nestedParentTypeKeys,
                true,
            );
    }

    /**
     * @param  Builder<AccountProfile>  $query
     * @return Builder<AccountProfile>
     */
    public function applyCatalogConstraint(Builder $query, bool $requireSlug = false): Builder
    {
        return $query->whereRaw($this->catalogMatchExpression($requireSlug));
    }

    /**
     * @param  Builder<AccountProfile>  $query
     * @return Builder<AccountProfile>
     */
    public function applyNestedParentConstraint(Builder $query, bool $requireSlug = false): Builder
    {
        return $query->whereRaw($this->nestedParentMatchExpression($requireSlug));
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogMatchExpression(bool $requireSlug = false): array
    {
        return $this->matchExpressionForTypeKeys($this->catalogTypeKeys, $requireSlug);
    }

    /**
     * @return array<string, mixed>
     */
    public function nestedParentMatchExpression(bool $requireSlug = false): array
    {
        return $this->matchExpressionForTypeKeys($this->nestedParentTypeKeys, $requireSlug);
    }

    /**
     * @param  array<int, string>  $typeKeys
     * @return array<string, mixed>
     */
    public function matchExpressionForTypeKeys(array $typeKeys, bool $requireSlug = false): array
    {
        $normalizedTypeKeys = $this->normalizeTypeKeys($typeKeys);
        if ($normalizedTypeKeys === []) {
            return ['_id' => ['$exists' => false]];
        }

        $clauses = [
            ['is_active' => true],
            ['deleted_at' => null],
            ['visibility' => 'public'],
            ['profile_type' => ['$in' => $normalizedTypeKeys]],
        ];
        if ($requireSlug) {
            // A whitespace-only slug is not a public-detail identifier.
            $clauses[] = ['slug' => ['$regex' => '\\S']];
        }

        return ['$and' => $clauses];
    }

    /**
     * @return array<int, array{\$match: array<string, mixed>}>
     */
    public function catalogLookupPipeline(bool $requireSlug = false): array
    {
        return [['$match' => $this->catalogMatchExpression($requireSlug)]];
    }

    /**
     * @return array<int, array{\$match: array<string, mixed>}>
     */
    public function nestedParentLookupPipeline(bool $requireSlug = false): array
    {
        return [['$match' => $this->nestedParentMatchExpression($requireSlug)]];
    }

    private function hasCatalogType(string $profileType): bool
    {
        return in_array(trim($profileType), $this->catalogTypeKeys, true);
    }

    /**
     * @param  array<int, string>  $typeKeys
     * @return array<int, string>
     */
    private function normalizeTypeKeys(array $typeKeys): array
    {
        $normalized = [];
        foreach ($typeKeys as $typeKey) {
            $value = trim($typeKey);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }
}
