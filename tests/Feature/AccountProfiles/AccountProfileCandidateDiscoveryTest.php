<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
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

    public function test_candidate_discovery_normalizes_name_prefixes_and_escapes_regex_input(): void
    {
        $accented = $this->createProfile('venue', 'Xápuri Cultural');
        $literalBracket = $this->createProfile('venue', 'Xa[Literal] Profile');

        $accentedResponse = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=".urlencode('xá'),
            $this->getHeaders(),
        );
        $bracketResponse = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=".urlencode('xa['),
            $this->getHeaders(),
        );
        $interiorResponse = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=pur",
            $this->getHeaders(),
        );

        $accentedResponse->assertOk();
        $this->assertContains(
            (string) $accented->_id,
            collect($accentedResponse->json('data'))->pluck('id')->all(),
        );
        $bracketResponse->assertOk()->assertJsonPath('data.0.id', (string) $literalBracket->_id);
        $interiorResponse->assertExactJson([
            'data' => [],
            'page' => 1,
            'per_page' => 20,
            'has_more' => false,
            'browse_limit_reached' => false,
        ]);
    }

    public function test_candidate_discovery_rejects_closed_scope_and_non_indexable_input(): void
    {
        $invalidScope = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=all&search=xa",
            $this->getHeaders(),
        );
        $whitespaceOnly = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=".urlencode('  '),
            $this->getHeaders(),
        );
        $combiningOnly = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=".urlencode("\u{0301}\u{0301}"),
            $this->getHeaders(),
        );
        $invalidExclusion = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&exclude_account_profile_id=not-an-object-id",
            $this->getHeaders(),
        );
        $oversizedPage = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&per_page=51",
            $this->getHeaders(),
        );

        $invalidScope->assertStatus(422)->assertJsonValidationErrors('scope');
        $whitespaceOnly->assertStatus(422)->assertJsonValidationErrors('search');
        $combiningOnly->assertStatus(422)->assertJsonValidationErrors('search');
        $invalidExclusion->assertStatus(422)->assertJsonValidationErrors('exclude_account_profile_id');
        $oversizedPage->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_candidate_discovery_uses_sentinel_pagination_without_a_count_query(): void
    {
        $first = $this->createProfile('venue', 'Xapuri Alpha');
        $second = $this->createProfile('venue', 'Xapuri Bravo');
        $third = $this->createProfile('venue', 'Xapuri Charlie');

        $firstPage = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&page=1&per_page=2",
            $this->getHeaders(),
        );
        $secondPage = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&page=2&per_page=2",
            $this->getHeaders(),
        );

        $firstPage->assertExactJson([
            'data' => [
                ['id' => (string) $first->_id, 'display_name' => 'Xapuri Alpha'],
                ['id' => (string) $second->_id, 'display_name' => 'Xapuri Bravo'],
            ],
            'page' => 1,
            'per_page' => 2,
            'has_more' => true,
            'browse_limit_reached' => false,
        ]);
        $secondPage->assertExactJson([
            'data' => [
                ['id' => (string) $third->_id, 'display_name' => 'Xapuri Charlie'],
            ],
            'page' => 2,
            'per_page' => 2,
            'has_more' => false,
            'browse_limit_reached' => false,
        ]);
    }

    public function test_profile_writes_maintain_the_normalized_candidate_search_key(): void
    {
        $profile = $this->createProfile('venue', '  Xápuri   Cultural  ');

        $this->assertSame('xapuri cultural', $profile->fresh()->name_search_key);

        $profile->display_name = 'Xapuri   Updated';
        $profile->save();

        $this->assertSame('xapuri updated', $profile->fresh()->name_search_key);
    }

    public function test_candidate_discovery_reports_the_hard_browse_boundary_without_a_total(): void
    {
        $this->insertQueryableCandidateRows(2501);

        $pageFortyNine = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&page=49&per_page=50",
            $this->getHeaders(),
        );
        $pageFifty = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&page=50&per_page=50",
            $this->getHeaders(),
        );
        $smallerPageAtBoundary = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&search=xa&page=50&per_page=49",
            $this->getHeaders(),
        );

        $pageFortyNine->assertOk()->assertJsonPath('has_more', true)->assertJsonPath('browse_limit_reached', false);
        $pageFifty->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('has_more', false)->assertJsonPath('browse_limit_reached', true);
        $smallerPageAtBoundary->assertOk()->assertJsonCount(49, 'data')->assertJsonPath('has_more', false)->assertJsonPath('browse_limit_reached', true);
        $this->assertArrayNotHasKey('total', $pageFifty->json());
        $this->assertArrayNotHasKey('last_page', $pageFifty->json());
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

        return $profile;
    }

    private function insertQueryableCandidateRows(int $count): void
    {
        $documents = [];
        for ($index = 0; $index < $count; $index++) {
            $documents[] = [
                '_id' => new ObjectId,
                'account_id' => (string) new ObjectId,
                'profile_type' => 'venue',
                'display_name' => sprintf('Xapuri %04d', $index),
                'name_search_key' => sprintf('xapuri %04d', $index),
                'slug' => sprintf('xapuri-%04d', $index),
                'contact_mode' => 'own',
                'is_active' => true,
            ];
        }

        DB::connection('tenant')->getDatabase()->selectCollection('account_profiles')->insertMany($documents);
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
