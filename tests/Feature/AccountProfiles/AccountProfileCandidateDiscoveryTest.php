<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileCandidateDiscoveryTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'has_contact_channels' => true,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'has_contact_channels' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'contact_source',
            'label' => 'Contact source',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => false,
                'has_contact_channels' => true,
            ],
        ]);
    }

    public function test_queryable_candidates_use_the_closed_server_owned_prefix_contract(): void
    {
        $expected = $this->createProfile('venue', 'Xapuri Cultural Center');
        $this->createProfile('personal', 'Xapuri Personal');
        $this->createProfile('venue', 'Xapuri Inactive', isActive: false);
        $this->createProfile('venue', 'Acre Xapuri Interior Match');

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&page=1&per_page=20",
            $this->getHeaders(),
        );

        $response->assertOk();
        $response->assertExactJson([
            'data' => [[
                'id' => (string) $expected->_id,
                'display_name' => 'Xapuri Cultural Center',
            ]],
            'page' => 1,
            'per_page' => 20,
            'has_more' => false,
            'browse_limit_reached' => false,
        ]);
    }

    public function test_contact_capable_candidates_allow_only_active_own_mode_profiles(): void
    {
        $expected = $this->createProfile('contact_source', 'Xapuri Contact', contactMode: 'own');
        $this->createProfile('contact_source', 'Xapuri Mirrored', contactMode: 'mirrored_account_profile');
        $this->createProfile('contact_source', 'Xapuri Inactive Contact', isActive: false, contactMode: 'own');

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=contact_capable&search=xa&page=1&per_page=20",
            $this->getHeaders(),
        );

        $response->assertOk();
        $response->assertExactJson([
            'data' => [[
                'id' => (string) $expected->_id,
                'display_name' => 'Xapuri Contact',
            ]],
            'page' => 1,
            'per_page' => 20,
            'has_more' => false,
            'browse_limit_reached' => false,
        ]);
    }

    public function test_candidate_discovery_rejects_missing_scope_and_short_search(): void
    {
        $missingScope = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?search=xa",
            $this->getHeaders(),
        );
        $shortSearch = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=x",
            $this->getHeaders(),
        );

        $missingScope->assertStatus(422)->assertJsonValidationErrors('scope');
        $shortSearch->assertStatus(422)->assertJsonValidationErrors('search');
    }

    private function createProfile(
        string $profileType,
        string $displayName,
        bool $isActive = true,
        string $contactMode = 'own',
    ): AccountProfile {
        $account = Account::create([
            'name' => 'Candidate Profile Account '.uniqid('', true),
            'document' => strtoupper(str_replace('.', '', uniqid('candidate', true))),
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => $profileType,
            'display_name' => $displayName,
            'contact_mode' => $contactMode,
            'is_active' => $isActive,
        ]);
        $profile->forceFill([
            'name_search_key' => strtolower($displayName),
        ])->save();

        return $profile;
    }

    private function initializeSystem(): void
    {
        $this->app->make(SystemInitializationService::class)->initialize(
            new InitializationPayload(
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
                tenantDomains: ['tenant-zeta.test'],
            ),
        );
    }

}
