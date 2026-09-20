<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use App\Integration\Favorites\AccountProfileFavoriteDirectReadService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\SeedsTenantAccounts;

final class FavoriteAccountProfileVisibilityTest extends TestCaseTenant
{
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        FavoriteEdge::query()->delete();
        AccountProfile::query()->withTrashed()->forceDelete();
        TenantProfileType::query()->delete();
    }

    public function test_favorite_account_profile_preview_hides_dormant_media_and_restores_each_kind(): void
    {
        [$account] = $this->seedAccountWithRole(['account-users:view']);
        $type = TenantProfileType::query()->create([
            'type' => 'favorite-media',
            'label' => 'Favorite Media',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_favoritable' => ['value' => true, 'parameters' => []],
                'has_avatar' => ['value' => true, 'parameters' => []],
                'has_cover' => ['value' => true, 'parameters' => []],
            ],
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'favorite-media',
            'display_name' => 'Favorite Media',
            'slug' => 'favorite-media',
            'visibility' => 'public',
            'is_active' => true,
            'avatar_url' => 'https://cdn.example.test/favorite-avatar.jpg',
            'cover_url' => 'https://cdn.example.test/favorite-cover.jpg',
        ]);
        FavoriteEdge::query()->create([
            'owner_user_id' => 'favorite-media-owner',
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $profile->getKey(),
        ]);

        $reader = app(AccountProfileFavoriteDirectReadService::class);
        foreach ([['avatar', 'cover'], ['cover', 'avatar']] as [$kind, $otherKind]) {
            $initial = $reader->listForOwner('favorite-media-owner', 1, 10)['items'][0]['target'];
            $this->assertIsString($initial["{$kind}_url"] ?? null);
            $this->assertIsString($initial["{$otherKind}_url"] ?? null);

            $capabilities = $type->capabilities;
            $capabilities["has_{$kind}"]['value'] = false;
            $type->capabilities = $capabilities;
            $type->save();

            $disabled = $reader->listForOwner('favorite-media-owner', 1, 10)['items'][0]['target'];
            $this->assertNull($disabled["{$kind}_url"] ?? null);
            $this->assertSame($initial["{$otherKind}_url"], $disabled["{$otherKind}_url"] ?? null);

            $capabilities["has_{$kind}"]['value'] = true;
            $type->capabilities = $capabilities;
            $type->save();

            $restored = $reader->listForOwner('favorite-media-owner', 1, 10)['items'][0]['target'];
            $this->assertSame($initial["{$kind}_url"], $restored["{$kind}_url"] ?? null);
        }
    }

    public function test_favorite_keeps_nondiscoverable_navigable_target_and_hides_navigation_disabled_target_without_removing_edge(): void
    {
        [$account, $type, $profile, $edge] = $this->createFavoriteTarget(
            ownerUserId: 'favorite-navigation-owner',
            type: 'favorite-navigation',
            discoverable: false,
            navigable: true,
        );
        $reader = app(AccountProfileFavoriteDirectReadService::class);

        $initial = $reader->listForOwner('favorite-navigation-owner', 1, 10);
        $this->assertSame((string) $profile->getKey(), $initial['items'][0]['target_id']);
        $this->assertTrue($initial['items'][0]['target']['can_open_public_detail']);
        $this->assertFavoriteEdgeRetained($edge);

        $capabilities = $type->capabilities;
        $capabilities['is_publicly_navigable']['value'] = false;
        $type->capabilities = $capabilities;
        $type->save();

        $this->assertSame([], $reader->listForOwner('favorite-navigation-owner', 1, 10)['items']);
        $this->assertFavoriteEdgeRetained($edge);

        $capabilities['is_publicly_navigable']['value'] = true;
        $type->capabilities = $capabilities;
        $type->save();

        $restored = $reader->listForOwner('favorite-navigation-owner', 1, 10);
        $this->assertSame((string) $profile->getKey(), $restored['items'][0]['target_id']);
        $this->assertFavoriteEdgeRetained($edge);
    }

    public function test_favorite_hides_private_inactive_deleted_and_unpublished_parent_targets_without_removing_edge(): void
    {
        [$account, , $profile, $edge] = $this->createFavoriteTarget(
            ownerUserId: 'favorite-lifecycle-owner',
            type: 'favorite-lifecycle',
            discoverable: true,
            navigable: true,
        );
        $reader = app(AccountProfileFavoriteDirectReadService::class);

        $profile->visibility = 'private';
        $profile->save();
        $this->assertSame([], $reader->listForOwner('favorite-lifecycle-owner', 1, 10)['items']);
        $this->assertFavoriteEdgeRetained($edge);

        $profile->visibility = 'public';
        $profile->is_active = false;
        $profile->save();
        $this->assertSame([], $reader->listForOwner('favorite-lifecycle-owner', 1, 10)['items']);
        $this->assertFavoriteEdgeRetained($edge);

        $profile->is_active = true;
        $profile->save();
        $profile->delete();
        $this->assertSame([], $reader->listForOwner('favorite-lifecycle-owner', 1, 10)['items']);
        $this->assertFavoriteEdgeRetained($edge);

        AccountProfile::withTrashed()->findOrFail($profile->getKey())->restore();
        $account->publication = ['status' => 'draft', 'publish_at' => null];
        $account->save();
        $this->assertSame([], $reader->listForOwner('favorite-lifecycle-owner', 1, 10)['items']);
        $this->assertFavoriteEdgeRetained($edge);
    }

    /** @return array{0:Account,1:TenantProfileType,2:AccountProfile,3:FavoriteEdge} */
    private function createFavoriteTarget(
        string $ownerUserId,
        string $type,
        bool $discoverable,
        bool $navigable,
    ): array {
        [$account] = $this->seedAccountWithRole(['account-users:view']);
        $profileType = TenantProfileType::query()->create([
            'type' => $type,
            'label' => $type,
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => $discoverable, 'parameters' => []],
                'is_publicly_navigable' => ['value' => $navigable, 'parameters' => []],
                'is_favoritable' => ['value' => true, 'parameters' => []],
            ],
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => $type,
            'display_name' => $type,
            'slug' => $type,
            'visibility' => 'public',
            'is_active' => true,
        ]);
        $edge = FavoriteEdge::query()->create([
            'owner_user_id' => $ownerUserId,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $profile->getKey(),
        ]);

        return [$account, $profileType, $profile, $edge];
    }

    private function assertFavoriteEdgeRetained(FavoriteEdge $edge): void
    {
        $this->assertNotNull(FavoriteEdge::query()->find($edge->getKey()));
    }
}
