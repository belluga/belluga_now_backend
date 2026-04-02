<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use MongoDB\BSON\ObjectId;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfilesControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $accountRoleTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->delete();
        TaxonomyTerm::query()->delete();
        Taxonomy::query()->delete();

        [$this->account, $this->accountRoleTemplate] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_poi_enabled' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $taxonomy = Taxonomy::create([
            'slug' => 'cuisine',
            'name' => 'Cuisine',
            'applies_to' => ['account_profile', 'event', 'static_asset'],
            'icon' => 'restaurant',
            'color' => '#FFAA00',
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'italian',
            'name' => 'Italian',
        ]);
    }

    public function test_account_profile_index_accessible_for_account_user(): void
    {
        $user = $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Profile Viewer',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
        $this->assertTrue(collect($response->json('data'))->every(static fn (array $item): bool => array_key_exists('ownership_state', $item)));
    }

    public function test_public_account_profile_index_forbids_landlord_user_without_tenant_access(): void
    {
        $noAccessUser = LandlordUser::query()->create([
            'name' => 'No Access User',
            'emails' => [strtolower('no-access-'.uniqid('', true).'@example.org')],
            'password' => 'Secret!234',
        ]);

        Sanctum::actingAs($noAccessUser, []);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(403);
    }

    public function test_public_account_profile_index_allows_landlord_user_with_tenant_access(): void
    {
        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
    }

    public function test_public_account_profile_index_filters_by_profile_type(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile Personal',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Account Secondary',
            'document' => 'DOC-SECONDARY',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Profile Venue',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?filter[profile_type]=venue"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue($items->every(fn (array $item): bool => $item['profile_type'] === 'venue'));
    }

    public function test_public_account_profile_index_returns_only_favoritable_types(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Private Profile',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Favoritable Account',
            'document' => 'DOC-FAVORITABLE',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Public Venue',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('venue', $items->first()['profile_type'] ?? null);
    }

    public function test_public_account_profile_index_returns_empty_when_filter_requests_non_favoritable_type(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Personal Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?filter[profile_type]=personal"
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_index_returns_empty_when_top_level_profile_type_is_non_favoritable(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Personal Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?profile_type=personal"
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_show_by_slug_returns_public_active_profile(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Slug Detail Venue',
            'slug' => 'slug-detail-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/slug-detail-venue"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'slug-detail-venue');
        $response->assertJsonPath('data.display_name', 'Slug Detail Venue');
    }

    public function test_public_account_profile_show_by_slug_returns_not_found_for_private_profile(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Detail Venue',
            'slug' => 'private-detail-venue',
            'is_active' => true,
            'visibility' => 'friends_only',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/private-detail-venue"
        );

        $response->assertStatus(404);
    }

    public function test_public_account_profile_near_returns_distance_sorted_favoritable_profiles_only(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $secondary = Account::create([
            'name' => 'Geo Secondary',
            'document' => 'DOC-GEO-SECONDARY',
        ]);

        $tertiary = Account::create([
            'name' => 'Geo Tertiary',
            'document' => 'DOC-GEO-TERTIARY',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Near Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0002, -20.0002],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Far Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0120, -20.0120],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $tertiary->_id,
            'profile_type' => 'artist',
            'display_name' => 'Non Poi Artist',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0001, -20.0001],
            ],
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('page', 1);
        $response->assertJsonPath('page_size', 10);
        $response->assertJsonPath('has_more', false);

        $items = collect($response->json('data'));
        $this->assertCount(2, $items);
        $this->assertTrue(
            $items->every(static fn (array $item): bool => ($item['profile_type'] ?? null) === 'venue')
        );
        $this->assertSame(
            ['Near Venue', 'Far Venue'],
            $items->pluck('display_name')->values()->all()
        );
        $this->assertNotNull($items->first()['distance_meters'] ?? null);
        $this->assertIsNumeric($items->first()['distance_meters'] ?? null);
        $this->assertLessThan(
            (float) ($items->last()['distance_meters'] ?? INF),
            (float) ($items->first()['distance_meters'] ?? 0)
        );
    }

    public function test_public_account_profile_near_requires_origin_coordinates(): void
    {
        $this->createAccountUser([]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&page=1&page_size=10"
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.origin_lng'));
    }

    public function test_public_account_profile_near_excludes_private_visibility_profiles(): void
    {
        $this->createAccountUser([]);

        $publicProfile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Public Nearby',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0005, -20.0005],
            ],
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Nearby Private Account',
            'document' => 'DOC-NEARBY-PRIVATE',
        ]);
        $privateProfile = AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Nearby',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0007, -20.0007],
            ],
            'is_active' => true,
        ]);

        AccountProfile::query()
            ->where('_id', (string) $publicProfile->_id)
            ->update(['visibility' => 'public']);
        AccountProfile::query()
            ->where('_id', (string) $privateProfile->_id)
            ->update(['visibility' => 'friends_only']);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Public Nearby', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_excludes_legacy_profiles_without_visibility_field(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Explicit Public Venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $legacyAccount = Account::create([
            'name' => 'Legacy Visibility Account',
            'document' => 'DOC-LEGACY-VISIBILITY-INDEX',
        ]);

        AccountProfile::raw(static function ($collection) use ($legacyAccount): void {
            $collection->insertOne([
                '_id' => new ObjectId,
                'account_id' => (string) $legacyAccount->_id,
                'profile_type' => 'venue',
                'display_name' => 'Legacy Missing Visibility',
                'slug' => 'legacy-missing-visibility-index',
                'is_active' => true,
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0008, -20.0008],
                ],
                'taxonomy_terms' => [],
            ]);
        });

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Explicit Public Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_near_excludes_legacy_profiles_without_visibility_field(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Explicit Public Nearby',
            'is_active' => true,
            'visibility' => 'public',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0005, -20.0005],
            ],
        ]);

        $legacyAccount = Account::create([
            'name' => 'Legacy Near Visibility Account',
            'document' => 'DOC-LEGACY-VISIBILITY-NEAR',
        ]);

        AccountProfile::raw(static function ($collection) use ($legacyAccount): void {
            $collection->insertOne([
                '_id' => new ObjectId,
                'account_id' => (string) $legacyAccount->_id,
                'profile_type' => 'venue',
                'display_name' => 'Legacy Missing Visibility Nearby',
                'slug' => 'legacy-missing-visibility-near',
                'is_active' => true,
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0006, -20.0006],
                ],
                'taxonomy_terms' => [],
            ]);
        });

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Explicit Public Nearby', $items->first()['display_name'] ?? null);
    }

    public function test_account_profile_model_defaults_visibility_to_public(): void
    {
        $this->createAccountUser([]);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Default Visibility Venue',
            'is_active' => true,
        ]);

        $stored = AccountProfile::query()
            ->where('_id', (string) $profile->_id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('public', $stored?->visibility);
    }

    public function test_public_account_profile_index_excludes_inactive_profiles(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Active Venue',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Inactive Account',
            'document' => 'DOC-INACTIVE',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Inactive Venue',
            'is_active' => false,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Active Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_excludes_private_visibility_profiles(): void
    {
        $this->createAccountUser([]);

        $publicProfile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Public Venue',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Private Visibility Account',
            'document' => 'DOC-PRIVATE-VISIBILITY',
        ]);

        $privateProfile = AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Venue',
            'is_active' => true,
        ]);

        AccountProfile::query()
            ->where('_id', (string) $publicProfile->_id)
            ->update(['visibility' => 'public']);
        AccountProfile::query()
            ->where('_id', (string) $privateProfile->_id)
            ->update(['visibility' => 'friends_only']);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Public Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_returns_empty_when_none(): void
    {
        $this->createAccountUser([]);

        AccountProfile::query()->delete();

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_index_accepts_page_size_alias(): void
    {
        $this->createAccountUser([]);

        $secondAccount = Account::create([
            'name' => 'Second Account',
            'document' => 'DOC-PAGE-SIZE-2',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Page Size 1',
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Page Size 2',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles?page_size=1");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, (int) $response->json('per_page'));
    }

    public function test_public_account_profile_index_supports_search_param(): void
    {
        $this->createAccountUser([]);

        $secondAccount = Account::create([
            'name' => 'Second Search Account',
            'document' => 'DOC-SEARCH-2',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Jazz House',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'vegan'],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Classical Club',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles?search=vegan");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Jazz House', $items->first()['display_name'] ?? null);

        $partialResponse = $this->getJson("{$this->base_api_tenant}account_profiles?search=ega");
        $partialResponse->assertStatus(200);
        $partialItems = collect($partialResponse->json('data'));
        $this->assertCount(1, $partialItems);
        $this->assertSame('Jazz House', $partialItems->first()['display_name'] ?? null);
    }

    public function test_admin_account_profile_index_filters_by_ownership_state(): void
    {
        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Managed Profile',
            'is_active' => true,
        ]);

        $unmanagedAccount = Account::create([
            'name' => 'Unmanaged Account',
            'document' => 'DOC-UNMANAGED',
        ]);

        AccountProfile::create([
            'account_id' => (string) $unmanagedAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Unmanaged Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles?ownership_state=unmanaged",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue(
            $items->every(static fn (array $item): bool => ($item['ownership_state'] ?? null) === 'unmanaged')
        );
    }

    public function test_account_profile_types_returns_registry(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}account_profile_types", $this->getHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_account_profile_types_forbidden_without_ability(): void
    {
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, ['account-users:create']);

        $response = $this->getJson("{$this->base_tenant_api_admin}account_profile_types");

        $response->assertStatus(403);
    }

    public function test_account_profile_create_requires_location_when_poi_enabled(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Test Venue Missing Location',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);

        $created = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Test Venue',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $created->assertJsonPath('data.account_profile.profile_type', 'venue');
    }

    public function test_account_onboarding_projects_map_poi_with_type_visual_snapshot(): void
    {
        MapPoi::query()->delete();

        TenantProfileType::query()
            ->where('type', 'venue')
            ->update([
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'restaurant',
                    'color' => '#EB2528',
                    'icon_color' => '#101010',
                ],
            ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Venue Visual Projection',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.67134,
                    'lng' => -40.49540,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $profileId = (string) $response->json('data.account_profile.id');
        $this->assertNotSame('', $profileId);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame('icon', data_get($projection->visual, 'mode'));
        $this->assertSame('restaurant', data_get($projection->visual, 'icon'));
        $this->assertSame('#EB2528', data_get($projection->visual, 'color'));
        $this->assertSame('#101010', data_get($projection->visual, 'icon_color'));
        $this->assertSame('type_definition', data_get($projection->visual, 'source'));
    }

    public function test_account_profile_create_stores_avatar_and_cover_uploads(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Media',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $response->assertStatus(201);
        $avatarUrl = $response->json('data.account_profile.avatar_url');
        $coverUrl = $response->json('data.account_profile.cover_url');
        $this->assertNotEmpty($avatarUrl);
        $this->assertNotEmpty($coverUrl);

        $profileId = (string) $response->json('data.account_profile.id');
        $this->assertMediaUrlHealthy($avatarUrl);
        $this->assertMediaUrlHealthy($coverUrl);
        $this->assertMediaStored($profileId, 'avatar');
        $this->assertMediaStored($profileId, 'cover');
    }

    public function test_account_profile_create_rejects_unknown_taxonomy(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Venue Taxonomy',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
                'taxonomy_terms' => [
                    ['type' => 'unknown', 'value' => 'value'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_account_profile_create_rejects_disallowed_taxonomy(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Personal Taxonomy',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'italian'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_account_profile_create_accepts_allowed_taxonomy(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Venue Taxonomy',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'italian'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.type', 'cuisine');
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.value', 'italian');
    }

    public function test_account_profile_update_replaces_avatar_upload(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Replace',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $originalAvatarUrl = $createResponse->json('data.account_profile.avatar_url');
        $this->assertNotEmpty($originalAvatarUrl);
        $originalPath = $this->assertMediaStored($profileId, 'avatar');

        $updateResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                '_method' => 'PATCH',
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 220, 220),
            ],
        );

        $updateResponse->assertStatus(200);
        $newAvatarUrl = $updateResponse->json('data.avatar_url');
        $this->assertNotEmpty($newAvatarUrl);

        $this->assertMediaUrlHealthy($newAvatarUrl);
        $this->assertMediaStored($profileId, 'avatar');
        if ($originalPath) {
            Storage::disk('public')->assertMissing($originalPath);
        }
    }

    public function test_account_profile_update_replaces_cover_upload(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Replace Cover',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $originalCoverUrl = $createResponse->json('data.account_profile.cover_url');
        $this->assertNotEmpty($originalCoverUrl);
        $originalPath = $this->assertMediaStored($profileId, 'cover');

        $updateResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                '_method' => 'PATCH',
                'cover' => UploadedFile::fake()->image('cover.jpg', 1400, 700),
            ],
        );

        $updateResponse->assertStatus(200);
        $newCoverUrl = $updateResponse->json('data.cover_url');
        $this->assertNotEmpty($newCoverUrl);

        $this->assertMediaUrlHealthy($newCoverUrl);
        $this->assertMediaStored($profileId, 'cover');
        if ($originalPath) {
            Storage::disk('public')->assertMissing($originalPath);
        }
    }

    public function test_account_profile_remove_avatar_and_cover_clears_media(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Remove',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $avatarPath = $this->assertMediaStored($profileId, 'avatar');
        $coverPath = $this->assertMediaStored($profileId, 'cover');

        $removeResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'remove_avatar' => true,
                'remove_cover' => true,
            ],
            $this->getHeaders()
        );

        $removeResponse->assertStatus(200);
        $this->assertNull($removeResponse->json('data.avatar_url'));
        $this->assertNull($removeResponse->json('data.cover_url'));
        Storage::disk('public')->assertMissing($avatarPath);
        Storage::disk('public')->assertMissing($coverPath);
    }

    public function test_account_profile_create_rejects_unknown_profile_type(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Unknown Profile',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'unknown_type',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
    }

    public function test_legacy_account_profile_create_route_returns_policy_rejection(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => '605b9b3b8f1d2c6d88f4c123',
                'profile_type' => 'personal',
                'display_name' => 'Missing Account',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
        $response->assertJsonPath('meta.use_endpoint', '/admin/api/v1/account_onboardings');
    }

    public function test_account_profile_create_forbidden_without_ability(): void
    {
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, ['account-users:view']);

        $response = $this->postJson("{$this->base_tenant_api_admin}account_onboardings", [
            'name' => 'Personal',
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(403);
    }

    public function test_account_profile_update_rejects_invalid_profile_type(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'profile_type' => 'invalid_type',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
    }

    public function test_account_profile_update_allows_slug_change(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile Slug',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'slug' => 'profile-slug-custom',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'profile-slug-custom');
    }

    public function test_account_profile_update_rejects_duplicate_slug(): void
    {
        $primary = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Primary Slug',
            'is_active' => true,
        ])->fresh();

        $otherAccount = Account::create([
            'name' => 'Account Slug Other',
            'document' => 'DOC-SLUG-OTHER',
        ]);
        $secondary = AccountProfile::create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Secondary Slug',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $primary->_id,
            [
                'slug' => (string) ($secondary->slug ?? ''),
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_account_profile_index_filters_by_account(): void
    {
        $otherAccount = Account::create([
            'name' => 'Account B',
            'document' => 'DOC-B',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile B',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles?account_id=".(string) $this->account->_id,
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue($items->every(fn (array $item): bool => $item['account_id'] === (string) $this->account->_id));
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $service = $this->app->make(AccountUserService::class);
        $user = $service->create(
            $this->account,
            [
                'name' => 'Account Viewer',
                'email' => uniqid('account-viewer', true).'@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->accountRoleTemplate->_id
        );

        Sanctum::actingAs($user, $permissions);

        return $user;
    }

    private function assertMediaUrlHealthy(?string $url): void
    {
        $this->assertNotEmpty($url);
        $this->assertStringContainsString(
            "{$this->base_tenant_url}api/v1/media/account-profiles/",
            $url
        );
        $this->assertStringContainsString('v=', $url);

        $canonicalResponse = $this->get($url);
        $canonicalResponse->assertStatus(200);

        $path = parse_url((string) $url, PHP_URL_PATH);
        $this->assertTrue(is_string($path) && $path !== '');
        preg_match('#^/api/v1/media/account-profiles/([^/]+)/(avatar|cover)$#', (string) $path, $matches);
        $this->assertCount(3, $matches);

        $legacyPath = "account-profiles/{$matches[1]}/{$matches[2]}";
        $query = parse_url((string) $url, PHP_URL_QUERY);
        $legacyUrl = "{$this->base_tenant_url}{$legacyPath}";
        if (is_string($query) && trim($query) !== '') {
            $legacyUrl .= "?{$query}";
        }

        $legacyResponse = $this->get($legacyUrl);
        $legacyResponse->assertStatus(200);
    }

    private function assertMediaStored(string $profileId, string $kind): string
    {
        $tenant = Tenant::current();
        $tenantSlug = $tenant?->slug ?? $this->tenant->subdomain;
        $directory = "tenants/{$tenantSlug}/account_profiles/{$profileId}";
        $files = Storage::disk('public')->files($directory);
        $match = collect($files)->first(
            fn (string $path): bool => str_contains(basename($path), "{$kind}.")
        );
        $this->assertNotEmpty($match);

        return $match;
    }

    private function getMultipartHeaders(): array
    {
        $headers = $this->getHeaders();
        unset($headers['Content-Type']);
        $headers['Accept'] = 'application/json';

        return $headers;
    }
}
