<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfilePublicCatalogEligibilityPolicy;
use App\Models\Tenants\AccountProfile;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

class AccountProfilePublicCatalogEligibilityPolicyTest extends TestCase
{
    public function test_it_centrally_evaluates_public_exposure_and_detail_navigation(): void
    {
        $policy = new AccountProfilePublicCatalogEligibilityPolicy(
            catalogTypeKeys: ['venue'],
            nestedParentTypeKeys: ['venue'],
        );

        $eligible = $this->profile([
            'profile_type' => 'venue',
            'is_active' => true,
            'visibility' => 'public',
            'slug' => 'public-venue',
        ]);

        $this->assertTrue($policy->isPubliclyExposed($eligible));
        $this->assertTrue($policy->canOpenPublicDetail($eligible));
        $this->assertTrue($policy->isPublicNestedParent($eligible));

        $this->assertFalse($policy->isPubliclyExposed($this->profile([
            'profile_type' => 'venue',
            'is_active' => false,
            'visibility' => 'public',
            'slug' => 'inactive-venue',
        ])));
        $this->assertFalse($policy->isPubliclyExposed($this->profile([
            'profile_type' => 'venue',
            'is_active' => true,
            'visibility' => 'private',
            'slug' => 'private-venue',
        ])));
        $this->assertFalse($policy->isPubliclyExposed($this->profile([
            'profile_type' => 'venue',
            'is_active' => true,
            'visibility' => 'public',
            'slug' => 'deleted-venue',
            'deleted_at' => new UTCDateTime,
        ])));
        $this->assertFalse($policy->isPubliclyExposed($this->profile([
            'profile_type' => 'artist',
            'is_active' => true,
            'visibility' => 'public',
            'slug' => 'ineligible-type',
        ])));
        $this->assertFalse($policy->canOpenPublicDetail($this->profile([
            'profile_type' => 'venue',
            'is_active' => true,
            'visibility' => 'public',
            'slug' => '   ',
        ])));
        $this->assertFalse($policy->isPublicNestedParent($this->profile([
            'profile_type' => 'artist',
            'is_active' => true,
            'visibility' => 'public',
            'slug' => 'public-artist',
        ])));
    }

    public function test_it_emits_complete_catalog_and_nested_parent_match_expressions(): void
    {
        $policy = new AccountProfilePublicCatalogEligibilityPolicy(
            catalogTypeKeys: ['artist', 'venue'],
            nestedParentTypeKeys: ['venue'],
        );

        $this->assertSame([
            '$and' => [
                ['is_active' => true],
                ['deleted_at' => null],
                ['visibility' => 'public'],
                ['profile_type' => ['$in' => ['artist', 'venue']]],
                ['slug' => ['$regex' => '\\S']],
            ],
        ], $policy->catalogMatchExpression(requireSlug: true));
        $this->assertSame([
            '$and' => [
                ['is_active' => true],
                ['deleted_at' => null],
                ['visibility' => 'public'],
                ['profile_type' => ['$in' => ['venue']]],
            ],
        ], $policy->nestedParentMatchExpression());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(array $attributes): AccountProfile
    {
        $deletedAt = $attributes['deleted_at'] ?? null;
        unset($attributes['deleted_at']);

        $profile = new AccountProfile($attributes);
        if ($deletedAt !== null) {
            $profile->setAttribute('deleted_at', $deletedAt);
        }

        return $profile;
    }
}
