<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileGalleryControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private static bool $bootstrapped = false;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'], tenant: ['name' => $this->tenant->name, 'subdomain' => $this->tenant->subdomain],
                role: ['name' => 'Root', 'permissions' => ['*']], user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'], pwaIcon: ['icon192_uri' => '/pwa/icon192.png'], tenantDomains: [$this->tenant->subdomain.'.'.$this->host],
            ));
            self::$bootstrapped = true;
        }
        Tenant::query()->firstOrFail()->makeCurrent();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:update', 'account-users:view']);
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();
        [$this->account] = $this->seedAccountWithRole(['account-users:view', 'account-users:create', 'account-users:update', 'account-users:delete']);
        TenantProfileType::query()->create(['type' => 'venue', 'label' => 'Venue', 'allowed_taxonomies' => [], 'capabilities' => ['is_queryable' => true, 'is_publicly_navigable' => true, 'is_favoritable' => true, 'is_publicly_discoverable' => true, 'is_poi_enabled' => false, 'has_events' => true, 'has_gallery' => true]]);
    }

    public function test_granular_photo_create_public_readback_variants_and_cleanup(): void
    {
        Storage::fake('public');
        $profile = $this->profile('gallery-media');
        $base = $this->groupsUrl($profile);
        $groupId = $this->postJson($base, ['subtitle' => 'Photos'])->assertOk()->json('data.gallery_groups.0.group_id');
        $created = $this->withHeaders(['Accept' => 'application/json'])->post("{$base}/{$groupId}/items", ['type' => 'photo', 'description' => 'Front entrance', 'image' => UploadedFile::fake()->image('front.jpg', 1800, 1200)])->assertOk();
        $item = $created->json('data.gallery_groups.0.items.0');
        $itemId = (string) $item['item_id'];
        $created->assertJsonPath('data.gallery_groups.0.items.0.type', 'photo')->assertJsonPath('data.gallery_groups.0.items.0.description', 'Front entrance')->assertJsonPath('data.gallery_capabilities.max_galleries', 6)->assertJsonPath('data.gallery_capabilities.max_items_per_gallery', 12);
        foreach (['image_url', 'thumb_url', 'card_url', 'modal_url'] as $key) {
            $this->assertSame("/api/v1/media/account-profiles/{$profile->getKey()}/gallery/{$itemId}", parse_url((string) $item[$key], PHP_URL_PATH));
        }
        parse_str((string) parse_url((string) $item['thumb_url'], PHP_URL_QUERY), $thumbQuery);
        $this->assertSame('thumb', $thumbQuery['variant'] ?? null);
        $this->get((string) $item['thumb_url'])->assertOk()->assertHeader('ETag');
        $this->getJson("{$this->base_api_tenant}account_profiles/gallery-media", $this->getHeaders())->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.item_id', $itemId);
        $this->assertVariantsExist($profile, $itemId);
        $this->deleteJson("{$base}/{$groupId}/items/{$itemId}")->assertOk()->assertJsonCount(0, 'data.gallery_groups.0.items');
        $this->assertVariantsMissing($profile, $itemId);
    }

    public function test_granular_photo_replacement_refreshes_media_and_public_media_requires_an_exposed_profile(): void
    {
        Storage::fake('public');
        $profile = $this->profile('gallery-replacement');
        $base = $this->groupsUrl($profile);
        $groupId = $this->postJson($base, ['subtitle' => 'Photos'])->json('data.gallery_groups.0.group_id');
        $first = $this->withHeaders(['Accept' => 'application/json'])->post("{$base}/{$groupId}/items", ['type' => 'photo', 'image' => UploadedFile::fake()->image('first.jpg', 1600, 1000)])->assertOk()->json('data.gallery_groups.0.items.0');
        $itemId = (string) $first['item_id'];
        $oldQuery = (string) parse_url((string) $first['modal_url'], PHP_URL_QUERY);
        $replacement = $this->withHeaders(['Accept' => 'application/json'])->post("{$base}/{$groupId}/items/{$itemId}", ['_method' => 'PATCH', 'type' => 'photo', 'image' => UploadedFile::fake()->image('replacement.jpg', 2200, 1400)])->assertOk();
        $newUrl = (string) $replacement->json('data.gallery_groups.0.items.0.modal_url');
        $this->assertNotSame($oldQuery, (string) parse_url($newUrl, PHP_URL_QUERY));
        $this->assertVariantsExist($profile, $itemId);
        $this->get($newUrl)->assertOk();
        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $profile->visibility = 'friends_only';
        $profile->save();
        $this->get($newUrl)->assertNotFound();
        $profile->visibility = 'public';
        $profile->is_active = false;
        $profile->save();
        $this->get($newUrl)->assertNotFound();
    }

    private function profile(string $slug): AccountProfile
    {
        return AccountProfile::query()->create(['account_id' => (string) $this->account->getKey(), 'profile_type' => 'venue', 'display_name' => 'Gallery '.$slug, 'slug' => $slug, 'visibility' => 'public', 'is_active' => true])->fresh();
    }

    private function groupsUrl(AccountProfile $profile): string
    {
        return $this->base_tenant_api_admin.'account_profiles/'.$profile->getKey().'/gallery/groups';
    }

    private function assertVariantsExist(AccountProfile $profile, string $itemId): void
    {
        foreach ([null, 'thumb', 'card', 'modal'] as $variant) {
            $this->assertNotNull(app(AccountProfileMediaService::class)->resolveGalleryMediaPathForBaseUrl($profile, $itemId, $variant, null));
        }
    }

    private function assertVariantsMissing(AccountProfile $profile, string $itemId): void
    {
        foreach ([null, 'thumb', 'card', 'modal'] as $variant) {
            $this->assertNull(app(AccountProfileMediaService::class)->resolveGalleryMediaPathForBaseUrl($profile, $itemId, $variant, null));
        }
    }
}
