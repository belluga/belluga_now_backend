<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MongoDB\BSON\UTCDateTime;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class FavoritesControllerTest extends TestCaseTenant
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

    private AccountUserService $userService;

    private AccountUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        FavoriteEdge::query()->delete();
        AccountProfile::query()->delete();
        DB::connection('tenant')->getDatabase()->selectCollection('favoritable_account_profile_snapshots')->deleteMany([]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['account-users:view']);

        Sanctum::actingAs($this->user, ['account-users:view']);
    }

    public function test_favorites_orders_by_next_then_last_then_favorited_at(): void
    {
        $profileNext = $this->createProfile('Profile Next', 'profile-next');
        $profileLast = $this->createProfile('Profile Last', 'profile-last');
        $profileFallback = $this->createProfile('Profile Fallback', 'profile-fallback');

        $this->insertSnapshot((string) $profileNext->_id, [
            'next_event_occurrence_id' => 'occ-next',
            'next_event_occurrence_at' => Carbon::parse('2026-03-25T12:00:00Z'),
            'last_event_occurrence_at' => null,
        ], [
            'display_name' => 'Profile Next',
            'slug' => 'profile-next',
        ]);

        $this->insertSnapshot((string) $profileLast->_id, [
            'next_event_occurrence_id' => null,
            'next_event_occurrence_at' => null,
            'last_event_occurrence_at' => Carbon::parse('2026-03-18T12:00:00Z'),
        ], [
            'display_name' => 'Profile Last',
            'slug' => 'profile-last',
        ]);

        $this->insertSnapshot((string) $profileFallback->_id, [
            'next_event_occurrence_id' => null,
            'next_event_occurrence_at' => null,
            'last_event_occurrence_at' => null,
        ], [
            'display_name' => 'Profile Fallback',
            'slug' => 'profile-fallback',
        ]);

        $this->createEdge((string) $profileNext->_id, Carbon::parse('2026-03-10T12:00:00Z'));
        $this->createEdge((string) $profileLast->_id, Carbon::parse('2026-03-19T12:00:00Z'));
        $this->createEdge((string) $profileFallback->_id, Carbon::parse('2026-03-17T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10&registry_key=account_profile&target_type=account_profile");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);

        $items = $response->json('items');
        $this->assertCount(3, $items);

        $this->assertSame((string) $profileNext->_id, (string) ($items[0]['target_id'] ?? ''));
        $this->assertSame((string) $profileLast->_id, (string) ($items[1]['target_id'] ?? ''));
        $this->assertSame((string) $profileFallback->_id, (string) ($items[2]['target_id'] ?? ''));
    }

    public function test_favorites_returns_empty_payload_when_user_has_no_edges(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $this->assertSame([], $response->json('items'));
    }

    public function test_favorites_uses_default_registry_when_registry_filter_is_omitted(): void
    {
        $profile = $this->createProfile('Profile Default Registry', 'profile-default-registry');

        $this->insertSnapshot((string) $profile->_id, [
            'next_event_occurrence_id' => null,
            'next_event_occurrence_at' => null,
            'last_event_occurrence_at' => null,
        ], [
            'display_name' => 'Profile Default Registry',
            'slug' => 'profile-default-registry',
        ]);

        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((string) $profile->_id, (string) ($items[0]['target_id'] ?? ''));
        $this->assertSame('account_profile', (string) ($items[0]['registry_key'] ?? ''));
    }

    public function test_favorites_returns_empty_payload_for_anonymous_identity(): void
    {
        $profile = $this->createProfile('Profile Anonymous', 'profile-anonymous');
        $this->insertSnapshot((string) $profile->_id, [
            'next_event_occurrence_id' => null,
            'next_event_occurrence_at' => null,
            'last_event_occurrence_at' => null,
        ], [
            'display_name' => 'Profile Anonymous',
            'slug' => 'profile-anonymous',
        ]);
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $this->user->setAttribute('identity_state', 'anonymous');
        $this->user->save();
        Sanctum::actingAs($this->user, ['account-users:view']);

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $this->assertSame([], $response->json('items'));
    }

    public function test_favorites_store_creates_edge_for_authenticated_identity(): void
    {
        $profile = $this->createProfile('Profile Store', 'profile-store');

        $response = $this->postJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_favorite', true);
        $response->assertJsonPath('target_id', (string) $profile->_id);
        $response->assertJsonPath('registry_key', 'account_profile');
        $response->assertJsonPath('target_type', 'account_profile');

        $this->assertTrue(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    public function test_favorites_destroy_removes_existing_edge(): void
    {
        $profile = $this->createProfile('Profile Destroy', 'profile-destroy');
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->deleteJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_favorite', false);
        $response->assertJsonPath('target_id', (string) $profile->_id);

        $this->assertFalse(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    public function test_favorites_store_forbidden_for_anonymous_identity(): void
    {
        $profile = $this->createProfile('Profile Anonymous Store', 'profile-anonymous-store');

        $this->user->setAttribute('identity_state', 'anonymous');
        $this->user->save();
        Sanctum::actingAs($this->user, ['account-users:view']);

        $response = $this->postJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(403);

        $this->assertFalse(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    public function test_favorites_destroy_forbidden_for_anonymous_identity(): void
    {
        $profile = $this->createProfile('Profile Anonymous Destroy', 'profile-anonymous-destroy');
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $this->user->setAttribute('identity_state', 'anonymous');
        $this->user->save();
        Sanctum::actingAs($this->user, ['account-users:view']);

        $response = $this->deleteJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(403);

        $this->assertTrue(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Favorites Role',
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Favorites User',
            'email' => uniqid('favorites-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createProfile(string $displayName, string $slug): AccountProfile
    {
        [$account] = $this->seedAccountWithRole([
            'account-users:view',
        ]);

        return AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => $displayName,
            'slug' => $slug,
            'is_active' => true,
            'is_verified' => false,
        ]);
    }

    /**
     * @param  array{next_event_occurrence_id:?string,next_event_occurrence_at:mixed,last_event_occurrence_at:mixed}  $snapshot
     * @param  array{display_name:string,slug:string}  $target
     */
    private function insertSnapshot(string $profileId, array $snapshot, array $target): void
    {
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('favoritable_account_profile_snapshots');

        $toUtcDateTime = static function (mixed $value): ?UTCDateTime {
            if (! $value instanceof \DateTimeInterface) {
                return null;
            }

            return new UTCDateTime($value);
        };

        $nextOccurrenceAt = $toUtcDateTime($snapshot['next_event_occurrence_at'] ?? null);
        $lastOccurrenceAt = $toUtcDateTime($snapshot['last_event_occurrence_at'] ?? null);

        $selector = [
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => $profileId,
        ];

        $collection->updateOne(
            $selector,
            [
                '$set' => [
                    ...$selector,
                    'target' => [
                        'id' => $profileId,
                        'display_name' => $target['display_name'],
                        'slug' => $target['slug'],
                        'avatar_url' => null,
                    ],
                    'snapshot' => [
                        ...$snapshot,
                        'next_event_occurrence_at' => $nextOccurrenceAt,
                        'last_event_occurrence_at' => $lastOccurrenceAt,
                    ],
                    'next_event_occurrence_id' => $snapshot['next_event_occurrence_id'],
                    'next_event_occurrence_at' => $nextOccurrenceAt,
                    'last_event_occurrence_at' => $lastOccurrenceAt,
                    'navigation' => [
                        'kind' => 'account_profile',
                        'target_slug' => $target['slug'],
                    ],
                    'updated_at' => Carbon::now(),
                ],
            ],
            ['upsert' => true]
        );
    }

    private function createEdge(string $targetId, Carbon $favoritedAt): void
    {
        FavoriteEdge::query()->create([
            'owner_user_id' => (string) $this->user->getAuthIdentifier(),
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => $targetId,
            'favorited_at' => $favoritedAt,
        ]);
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

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
