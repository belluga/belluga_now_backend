<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileVisibilityPolicyTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrFail()->makeCurrent();
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();
        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
    }

    public function test_public_catalog_enumerates_a_discoverable_profile_even_when_its_type_is_not_queryable(): void
    {
        $this->createType('discoverable-not-queryable', [
            'is_publicly_discoverable' => true,
            'is_publicly_navigable' => false,
            'is_queryable' => false,
            'is_map_poi_enabled' => false,
        ]);
        AccountProfile::query()->create([
            'account_id' => (string) $this->account->getKey(),
            'profile_type' => 'discoverable-not-queryable',
            'display_name' => 'Discoverable Only',
            'slug' => 'discoverable-only',
            'visibility' => 'public',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertOk();
        $this->assertContains(
            'discoverable-only',
            collect($response->json('data'))->pluck('slug')->all(),
        );
    }

    public function test_public_catalog_and_detail_apply_the_finite_visibility_denial_matrix(): void
    {
        $rows = [
            ['label' => 'V03 private', 'visibility' => 'private', 'active' => true, 'discoverable' => true, 'navigable' => true, 'blank_slug' => false, 'deleted' => false, 'parent_deleted' => false, 'profile_type' => null, 'catalog' => false, 'detail_status' => 404],
            ['label' => 'V04 inactive', 'visibility' => 'public', 'active' => false, 'discoverable' => true, 'navigable' => true, 'blank_slug' => false, 'deleted' => false, 'parent_deleted' => false, 'profile_type' => null, 'catalog' => false, 'detail_status' => 404],
            ['label' => 'V05 soft-deleted', 'visibility' => 'public', 'active' => true, 'discoverable' => true, 'navigable' => true, 'blank_slug' => false, 'deleted' => true, 'parent_deleted' => false, 'profile_type' => null, 'catalog' => false, 'detail_status' => 404],
            ['label' => 'V07 parent-deleted', 'visibility' => 'public', 'active' => true, 'discoverable' => true, 'navigable' => true, 'blank_slug' => false, 'deleted' => false, 'parent_deleted' => true, 'profile_type' => null, 'catalog' => false, 'detail_status' => 404],
            ['label' => 'V08 personal', 'visibility' => 'public', 'active' => true, 'discoverable' => true, 'navigable' => true, 'blank_slug' => false, 'deleted' => false, 'parent_deleted' => false, 'profile_type' => 'personal', 'catalog' => false, 'detail_status' => 404],
            ['label' => 'V09 nondiscoverable-navigable', 'visibility' => 'public', 'active' => true, 'discoverable' => false, 'navigable' => true, 'blank_slug' => false, 'deleted' => false, 'parent_deleted' => false, 'profile_type' => null, 'catalog' => false, 'detail_status' => 200],
            ['label' => 'V10 nonnavigable', 'visibility' => 'public', 'active' => true, 'discoverable' => true, 'navigable' => false, 'blank_slug' => false, 'deleted' => false, 'parent_deleted' => false, 'profile_type' => null, 'catalog' => true, 'detail_status' => 404],
            ['label' => 'V10 blank-slug', 'visibility' => 'public', 'active' => true, 'discoverable' => true, 'navigable' => true, 'blank_slug' => true, 'deleted' => false, 'parent_deleted' => false, 'profile_type' => null, 'catalog' => true, 'detail_status' => null],
        ];

        $expectedCatalogNames = [];
        $deniedCatalogNames = [];
        $detailRows = [];
        foreach ($rows as $row) {
            $type = $row['profile_type'] ?? 'visibility-matrix-'.str($row['label'])->slug();
            if ($row['profile_type'] === null) {
                $this->createType($type, [
                    'is_publicly_discoverable' => $row['discoverable'],
                    'is_publicly_navigable' => $row['navigable'],
                ]);
            } else {
                $this->createType($type, [
                    'is_publicly_discoverable' => true,
                    'is_publicly_navigable' => true,
                ]);
            }
            $parent = Account::query()->create([
                'name' => $row['label'].' parent',
                'document' => strtoupper((string) str($row['label'])->replace(' ', '-')).'-'.uniqid(),
                'publication' => ['status' => 'published', 'publish_at' => null],
            ]);
            $slug = $row['blank_slug'] ? '' : 'visibility-'.str($row['label'])->slug();
            $profile = AccountProfile::query()->create([
                'account_id' => (string) $parent->getKey(),
                'profile_type' => $type,
                'display_name' => $row['label'],
                'slug' => $slug,
                'visibility' => $row['visibility'],
                'is_active' => $row['active'],
            ]);
            if ($row['blank_slug']) {
                $profile->slug = '';
                $profile->save();
            }
            if ($row['deleted']) {
                $profile->delete();
            }
            if ($row['parent_deleted']) {
                $parent->delete();
            }
            if ($row['catalog']) {
                $expectedCatalogNames[] = $row['label'];
            } else {
                $deniedCatalogNames[] = $row['label'];
            }
            if ($row['detail_status'] !== null) {
                $detailRows[] = [
                    'slug' => $slug,
                    'status' => $row['detail_status'],
                ];
            }
        }

        foreach ($detailRows as $detailRow) {
            $this->withHeaders($this->tenantPublicAuthHeaders())
                ->getJson("{$this->base_api_tenant}account_profiles/{$detailRow['slug']}")
                ->assertStatus($detailRow['status']);
        }

        $catalogNames = collect($this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles")
            ->assertOk()
            ->json('data'))
            ->pluck('display_name')
            ->all();

        foreach ($expectedCatalogNames as $name) {
            $this->assertContains($name, $catalogNames);
        }
        foreach ($deniedCatalogNames as $name) {
            $this->assertNotContains($name, $catalogNames);
        }
        $blankSlugRow = collect($this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles")
            ->assertOk()
            ->json('data'))
            ->firstWhere('display_name', 'V10 blank-slug');
        $this->assertSame(false, $blankSlugRow['can_open_public_detail'] ?? null);
        $this->assertNull($blankSlugRow['public_detail_path'] ?? null);
        $this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles/absent-visibility-profile")
            ->assertNotFound();
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    private function createType(string $type, array $overrides): void
    {
        $capabilities = [
            'is_publicly_discoverable' => false,
            'is_publicly_navigable' => false,
            'is_queryable' => false,
            'is_favoritable' => false,
            'is_map_poi_enabled' => false,
            'location_policy' => 'disabled',
            'is_physical_host_enabled' => false,
            'is_reference_location_enabled' => false,
            'has_events' => false,
            'has_avatar' => false,
            'has_cover' => false,
        ];
        foreach ($overrides as $key => $value) {
            $capabilities[$key] = $value;
        }

        TenantProfileType::query()->create([
            'type' => $type,
            'label' => ucwords(str_replace('-', ' ', $type)),
            'allowed_taxonomies' => [],
            'capabilities' => collect($capabilities)->map(
                static fn (bool|string $value): array => ['value' => $value, 'parameters' => []],
            )->all(),
        ]);
    }

    /** @return array<string, string> */
    private function tenantPublicAuthHeaders(): array
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => 'visibility-policy-test-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'visibility-policy-test-device'),
                'user_agent' => 'AccountProfileVisibilityPolicyTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => ['source' => 'feature-test'],
        ]);
        $response->assertCreated();

        return ['Authorization' => 'Bearer '.(string) $response->json('data.token')];
    }
}
