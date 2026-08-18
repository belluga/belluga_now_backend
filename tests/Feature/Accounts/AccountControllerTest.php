<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Accounts\AccountPublicationStateService;
use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\AccountProfiles\AccountProfileNestedPublicMembersProjectionService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $role;

    private AccountManagementService $accountService;

    private AccountUserService $userService;

    private string $tenantAccountsAdminUrl;

    private string $tenantAccountOnboardingsAdminUrl;

    private string $baseUrl;

    private string $baseAdminUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->role] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        $this->account->makeCurrent();

        $this->accountService = $this->app->make(AccountManagementService::class);
        $this->userService = $this->app->make(AccountUserService::class);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, [
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenant->makeCurrent();
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'venue'],
            [
                'label' => 'Venue',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                ],
            ],
        );
        $tenantHost = "{$tenant->subdomain}.{$this->host}";
        $this->tenantAccountsAdminUrl = "http://{$tenantHost}/admin/api/v1/accounts";
        $this->tenantAccountOnboardingsAdminUrl = "http://{$tenantHost}/admin/api/v1/account_onboardings";
        $this->baseUrl = "http://{$tenantHost}/api/v1/accounts/{$this->account->slug}";
        $this->baseAdminUrl = "http://{$tenantHost}/admin/api/v1/accounts/{$this->account->slug}";
    }

    public function test_store_creates_account(): void
    {
        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'tenant_owned');

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_store_allows_duplicate_document_across_accounts(): void
    {
        $firstName = fake()->unique()->company();
        $secondName = fake()->unique()->company();
        $firstResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $firstName,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $firstResponse->assertCreated();
        $firstResponse->assertJsonPath('data.account.name', $firstName);

        $secondResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $secondName,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $secondResponse->assertCreated();
        $secondResponse->assertJsonPath('data.account.name', $secondName);

        Account::where('name', $firstName)->first()?->forceDelete();
        Account::where('name', $secondName)->first()?->forceDelete();
    }

    public function test_store_creates_unmanaged_account_without_organization(): void
    {
        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'unmanaged');
        $this->assertNull($response->json('data.account.organization_id'));

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_store_creates_tenant_owned_account_without_tenant_organization_context(): void
    {
        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenant->organization_id = null;
        $tenant->save();

        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'tenant_owned');

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_unmanaged_account_with_operator_is_returned_as_user_owned(): void
    {
        $name = fake()->unique()->company();
        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);

        $createResponse->assertCreated();
        $accountSlug = $createResponse->json('data.account.slug');
        $account = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $role = $account->roleTemplates()->firstOrFail();

        $account->makeCurrent();
        $this->userService->create(
            $account,
            [
                'name' => 'Managed User',
                'email' => fake()->unique()->safeEmail(),
                'password' => 'Secret!234',
            ],
            (string) $role->_id
        );

        $showResponse = $this->getJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.ownership_state', 'user_owned');
    }

    public function test_store_allows_missing_document(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_store_rejects_invalid_ownership_state(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => 'Account Invalid Ownership',
            'ownership_state' => 'user_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_forbidden_without_create_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => 'Account Forbidden',
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(403);
    }

    public function test_index_filters_by_current_user(): void
    {
        $response = $this->getJson($this->tenantAccountsAdminUrl);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains((string) $this->account->_id));
        $this->assertTrue(
            collect($response->json('data'))->every(
                static fn (array $item): bool => array_key_exists('ownership_state', $item)
            )
        );
    }

    public function test_index_normalizes_legacy_publish_scheduled_accounts_to_draft(): void
    {
        $legacyAccount = Account::query()->create([
            'name' => fake()->unique()->company(),
            'document' => strtoupper(fake()->bothify('LEGACY########')),
            'ownership_state' => 'tenant_owned',
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => '2026-08-16T12:00:00Z',
            ],
        ]);

        $response = $this->getJson($this->tenantAccountsAdminUrl);

        $response->assertOk();
        $items = collect($response->json('data'));
        $legacyItem = $items->firstWhere('id', (string) $legacyAccount->getAttribute('_id'));

        $this->assertIsArray($legacyItem);
        $this->assertSame(
            AccountPublicationStateService::DRAFT,
            data_get($legacyItem, 'publication.status'),
        );
        $this->assertNull(data_get($legacyItem, 'publication.publish_at'));
    }

    public function test_index_filters_by_unmanaged_ownership_state(): void
    {
        $unmanagedName = fake()->unique()->company();
        $tenantOwnedName = fake()->unique()->company();
        $userOwnedName = fake()->unique()->company();

        $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $unmanagedName,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ])->assertCreated();

        $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $tenantOwnedName,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ])->assertCreated();

        $userOwnedCreateResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $userOwnedName,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);
        $userOwnedCreateResponse->assertCreated();

        $userOwnedSlug = $userOwnedCreateResponse->json('data.account.slug');
        $userOwnedAccount = Account::query()->where('slug', $userOwnedSlug)->firstOrFail();
        $userOwnedRole = $userOwnedAccount->roleTemplates()->firstOrFail();

        $userOwnedAccount->makeCurrent();
        $this->userService->create(
            $userOwnedAccount,
            [
                'name' => 'Managed User',
                'email' => fake()->unique()->safeEmail(),
                'password' => 'Secret!234',
            ],
            (string) $userOwnedRole->_id
        );

        $response = $this->getJson(
            "{$this->tenantAccountsAdminUrl}?ownership_state=unmanaged"
        );

        $response->assertOk();
        $items = collect($response->json('data'));
        $this->assertGreaterThanOrEqual(1, $items->count());
        $this->assertTrue(
            $items->contains(
                static fn (array $item): bool => ($item['name'] ?? null) === $unmanagedName
            )
        );
        $this->assertFalse(
            $items->contains(
                static fn (array $item): bool => ($item['name'] ?? null) === $tenantOwnedName
            )
        );
        $this->assertFalse(
            $items->contains(
                static fn (array $item): bool => ($item['name'] ?? null) === $userOwnedName
            )
        );
        $this->assertTrue(
            $items->every(
                static fn (array $item): bool => ($item['ownership_state'] ?? null) === 'unmanaged'
            )
        );
    }

    public function test_index_forbidden_without_view_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $response = $this->getJson($this->tenantAccountsAdminUrl);

        $response->assertStatus(403);
    }

    public function test_index_accepts_page_size_alias(): void
    {
        $response = $this->getJson("{$this->tenantAccountsAdminUrl}?page_size=1");

        $response->assertOk();
        $response->assertJsonPath('per_page', 1);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_search_filters_accounts_by_name_slug_and_document(): void
    {
        $suffix = fake()->unique()->numerify('####');
        $otherSuffix = fake()->unique()->numerify('####');
        $searchToken = "DOCSEARCHMATCH{$suffix}";
        $matching = Account::create([
            'name' => "Search Match Account {$suffix}",
            'slug' => "search-match-account-{$suffix}",
            'document' => [
                'type' => 'cpf',
                'number' => $searchToken,
            ],
            'ownership_state' => 'tenant_owned',
        ]);
        $matching->roleTemplates()->create([
            'name' => 'Search Match Admin',
            'permissions' => ['*'],
        ]);

        $other = Account::create([
            'name' => "Other Account {$suffix}",
            'slug' => "other-account-{$suffix}",
            'document' => [
                'type' => 'cpf',
                'number' => "DOCOTHER{$otherSuffix}",
            ],
            'ownership_state' => 'tenant_owned',
        ]);
        $other->roleTemplates()->create([
            'name' => 'Other Admin',
            'permissions' => ['*'],
        ]);

        $response = $this->getJson(
            "{$this->tenantAccountsAdminUrl}?search={$searchToken}&per_page=50"
        );

        $response->assertOk();
        $items = collect($response->json('data'));
        $this->assertTrue(
            $items->contains(
                static fn (array $item): bool => ($item['id'] ?? null) === (string) $matching->_id
            )
        );
        $this->assertFalse(
            $items->contains(
                static fn (array $item): bool => ($item['id'] ?? null) === (string) $other->_id
            )
        );

        $partialToken = substr($searchToken, 0, -1);
        $partialResponse = $this->getJson(
            "{$this->tenantAccountsAdminUrl}?search={$partialToken}&per_page=50"
        );

        $partialResponse->assertOk();
        $partialItems = collect($partialResponse->json('data'));
        $this->assertTrue(
            $partialItems->contains(
                static fn (array $item): bool => ($item['id'] ?? null) === (string) $matching->_id
            )
        );
        $this->assertFalse(
            $partialItems->contains(
                static fn (array $item): bool => ($item['id'] ?? null) === (string) $other->_id
            )
        );

        $containsToken = substr($searchToken, 3, 6);
        $containsResponse = $this->getJson(
            "{$this->tenantAccountsAdminUrl}?search={$containsToken}&per_page=50"
        );

        $containsResponse->assertOk();
        $containsItems = collect($containsResponse->json('data'));
        $this->assertTrue(
            $containsItems->contains(
                static fn (array $item): bool => ($item['id'] ?? null) === (string) $matching->_id
            )
        );
        $this->assertFalse(
            $containsItems->contains(
                static fn (array $item): bool => ($item['id'] ?? null) === (string) $other->_id
            )
        );
    }

    public function test_update_accepts_ownership_state_transition_to_unmanaged(): void
    {
        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => fake()->unique()->company(),
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);
        $createResponse->assertCreated();
        $accountSlug = $createResponse->json('data.account.slug');

        $response = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'ownership_state' => 'unmanaged',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.ownership_state', 'unmanaged');

        $updated = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $this->assertSame('unmanaged', $updated->ownership_state);
        $this->assertNull($updated->organization_id);
    }

    public function test_update_accepts_publication_transition_to_published(): void
    {
        [$account] = $this->createDraftPersonalAccountAggregate();
        $accountSlug = (string) $account->slug;

        $response = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [
                'status' => AccountPublicationStateService::PUBLISHED,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'data.publication.status',
            AccountPublicationStateService::PUBLISHED,
        );

        $updated = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $this->assertSame(
            AccountPublicationStateService::PUBLISHED,
            data_get($updated->getAttribute('publication'), 'status'),
        );
    }

    public function test_update_rejects_publish_scheduled_account_publication_status(): void
    {
        [$account] = $this->createDraftPersonalAccountAggregate();
        $accountSlug = (string) $account->slug;

        $response = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => '2026-08-16T12:00:00Z',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['publication.status']);
    }

    public function test_update_rejects_publication_payload_without_status(): void
    {
        [$account] = $this->createDraftPersonalAccountAggregate();
        $accountSlug = (string) $account->slug;

        $response = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['publication.status']);

        $partialResponse = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [
                'publish_at' => '2026-08-16T12:00:00Z',
            ],
        ]);

        $partialResponse->assertStatus(422);
        $partialResponse->assertJsonValidationErrors(['publication.status']);

        $updated = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $this->assertSame(
            AccountPublicationStateService::DRAFT,
            data_get($updated->getAttribute('publication'), 'status'),
        );
        $this->assertNull(data_get($updated->getAttribute('publication'), 'publish_at'));

        $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [
                'status' => AccountPublicationStateService::PUBLISHED,
            ],
        ])->assertOk();

        $publishedResponse = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [],
        ]);
        $publishedResponse->assertStatus(422);
        $publishedResponse->assertJsonValidationErrors(['publication.status']);

        $publishedPartialResponse = $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [
                'publish_at' => '2026-08-16T12:00:00Z',
            ],
        ]);
        $publishedPartialResponse->assertStatus(422);
        $publishedPartialResponse->assertJsonValidationErrors(['publication.status']);

        $publishedAccount = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $this->assertSame(
            AccountPublicationStateService::PUBLISHED,
            data_get($publishedAccount->getAttribute('publication'), 'status'),
        );
        $this->assertNull(data_get($publishedAccount->getAttribute('publication'), 'publish_at'));
    }

    public function test_update_rolls_back_publication_when_nested_public_projection_rebuild_fails(): void
    {
        [$account, $parentProfile] = $this->createDraftPersonalAccountAggregate();
        $accountSlug = (string) $account->slug;

        $this->patchJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}", [
            'publication' => [
                'status' => AccountPublicationStateService::PUBLISHED,
            ],
        ])->assertOk();

        $projectionCollection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION);
        $baselineUpdatedAt = new \MongoDB\BSON\UTCDateTime((int) now()->getTimestampMs());
        $baselineMemberId = (string) new \MongoDB\BSON\ObjectId();
        $projectionCollection->insertMany([
            [
                '_id' => 'head:'.(string) $parentProfile->_id.':parceiros',
                'tenant_id' => (string) Tenant::current()?->getKey(),
                'parent_profile_id' => (string) $parentProfile->_id,
                'parent_slug' => (string) $parentProfile->slug,
                'parent_profile_type' => (string) $parentProfile->profile_type,
                'group_id' => 'parceiros',
                'group_label' => 'Parceiros',
                'group_order' => 0,
                'parent_aggregate_revision' => (int) ($parentProfile->aggregate_revision ?? 0),
                'projection_revision' => 'baseline-revision',
                'doc_type' => 'group_head',
                'doc_type_rank' => 0,
                'updated_at' => $baselineUpdatedAt,
            ],
            [
                '_id' => 'edge:'.(string) $parentProfile->_id.':parceiros:'.$baselineMemberId,
                'tenant_id' => (string) Tenant::current()?->getKey(),
                'parent_profile_id' => (string) $parentProfile->_id,
                'parent_slug' => (string) $parentProfile->slug,
                'parent_profile_type' => (string) $parentProfile->profile_type,
                'group_id' => 'parceiros',
                'member_profile_id' => $baselineMemberId,
                'member_profile_type' => 'artist',
                'raw_position' => 0,
                'parent_aggregate_revision' => (int) ($parentProfile->aggregate_revision ?? 0),
                'projection_revision' => 'baseline-revision',
                'doc_type' => 'member_edge',
                'doc_type_rank' => 1,
                'profile_type' => 'artist',
                'display_name' => 'Baseline Projection Member',
                'slug' => 'baseline-projection-member',
                'avatar_url' => null,
                'cover_url' => null,
                'taxonomy_terms' => [],
                'updated_at' => $baselineUpdatedAt,
            ],
        ]);
        $projectionFilter = ['parent_profile_id' => (string) $parentProfile->_id];
        $beforeDocs = iterator_to_array($projectionCollection->find(
            $projectionFilter,
            ['sort' => ['_id' => 1]],
        ));

        $this->assertNotEmpty($beforeDocs);
        $beforeProjectionIds = array_map(
            static fn (object|array $document): string => trim((string) data_get($document, '_id')),
            $beforeDocs,
        );
        $beforeProjectionRevisions = array_values(array_unique(array_filter(array_map(
            static fn (object|array $document): string => trim((string) data_get($document, 'projection_revision')),
            $beforeDocs,
        ))));

        $this->partialMock(AccountProfileQueryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isPublicNestedParent')
                ->once()
                ->andThrow(new RuntimeException('forced nested public projection rebuild failure'));
        });
        $this->app->forgetInstance(AccountManagementService::class);
        $this->app->forgetInstance(AccountProfileNestedPublicMembersProjectionService::class);

        try {
            $this->app->make(AccountManagementService::class)->update($account->fresh(), [
                'publication' => [
                    'status' => AccountPublicationStateService::DRAFT,
                ],
            ]);
            $this->fail('Expected the publication update boundary to raise after save.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'forced nested public projection rebuild failure',
                $exception->getMessage(),
            );
        }

        $reloadedAccount = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $this->assertSame(
            AccountPublicationStateService::PUBLISHED,
            data_get($reloadedAccount->getAttribute('publication'), 'status'),
        );

        $afterDocs = iterator_to_array($projectionCollection->find(
            $projectionFilter,
            ['sort' => ['_id' => 1]],
        ));
        $afterProjectionIds = array_map(
            static fn (object|array $document): string => trim((string) data_get($document, '_id')),
            $afterDocs,
        );
        $afterProjectionRevisions = array_values(array_unique(array_filter(array_map(
            static fn (object|array $document): string => trim((string) data_get($document, 'projection_revision')),
            $afterDocs,
        ))));

        $this->assertSame($beforeProjectionIds, $afterProjectionIds);
        $this->assertSame($beforeProjectionRevisions, $afterProjectionRevisions);
    }

    public function test_delete_rejects_non_unmanaged_account(): void
    {
        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => fake()->unique()->company(),
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);
        $createResponse->assertCreated();
        $accountSlug = $createResponse->json('data.account.slug');

        $response = $this->deleteJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['account']);
    }

    public function test_delete_unmanaged_account_soft_deletes_account_and_profile(): void
    {
        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => fake()->unique()->company(),
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);
        $createResponse->assertCreated();
        $accountSlug = $createResponse->json('data.account.slug');
        $accountId = (string) $createResponse->json('data.account.id');

        $response = $this->deleteJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}");

        $response->assertOk();
        $this->assertNotNull(
            Account::onlyTrashed()->where('slug', $accountSlug)->first()
        );
        $this->assertNotNull(
            AccountProfile::onlyTrashed()->where('account_id', $accountId)->first()
        );
    }

    public function test_delete_unmanaged_account_removes_related_account_profile_map_pois(): void
    {
        MapPoi::query()->delete();

        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => fake()->unique()->company(),
            'ownership_state' => 'unmanaged',
            'profile_type' => 'venue',
            'location' => [
                'lat' => -20.673067,
                'lng' => -40.498383,
            ],
        ]);
        $createResponse->assertCreated();

        $accountSlug = (string) $createResponse->json('data.account.slug');
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $controlAccount = Account::create([
            'name' => 'Control Account',
            'document' => 'CTRL'.uniqid(),
            'ownership_state' => 'unmanaged',
        ]);
        $controlProfile = AccountProfile::create([
            'account_id' => (string) $controlAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Control Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.497, -20.672],
            ],
            'is_active' => true,
        ]);

        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->exists()
        );
        MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $controlProfile->_id,
            'name' => 'Control Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.497, -20.672],
            ],
            'is_active' => true,
        ]);

        $response = $this->deleteJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}");

        $response->assertOk();
        $this->assertFalse(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->exists()
        );
        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', (string) $controlProfile->_id)
                ->exists()
        );
    }

    public function test_delete_unmanaged_account_removes_previously_trashed_account_profile_map_pois(): void
    {
        MapPoi::query()->delete();

        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => fake()->unique()->company(),
            'ownership_state' => 'unmanaged',
            'profile_type' => 'venue',
            'location' => [
                'lat' => -20.673067,
                'lng' => -40.498383,
            ],
        ]);
        $createResponse->assertCreated();

        $accountSlug = (string) $createResponse->json('data.account.slug');
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $trashedProfile = AccountProfile::query()->findOrFail($profileId);
        $trashedProfile->delete();

        $controlAccount = Account::create([
            'name' => 'Trashed Control Account',
            'document' => 'CTRL'.uniqid(),
            'ownership_state' => 'unmanaged',
        ]);
        $controlProfile = AccountProfile::create([
            'account_id' => (string) $controlAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Trashed Control Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.497, -20.672],
            ],
            'is_active' => true,
        ]);
        MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $controlProfile->_id,
            'name' => 'Trashed Control Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.497, -20.672],
            ],
            'is_active' => true,
        ]);

        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->exists()
        );

        $response = $this->deleteJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}");

        $response->assertOk();
        $this->assertFalse(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->exists()
        );
        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', (string) $controlProfile->_id)
                ->exists()
        );
    }

    public function test_force_delete_unmanaged_account_removes_related_account_profile_map_pois(): void
    {
        MapPoi::query()->delete();

        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => fake()->unique()->company(),
            'ownership_state' => 'unmanaged',
            'profile_type' => 'venue',
            'location' => [
                'lat' => -20.673067,
                'lng' => -40.498383,
            ],
        ]);
        $createResponse->assertCreated();

        $accountSlug = (string) $createResponse->json('data.account.slug');
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $controlAccount = Account::create([
            'name' => 'Force Delete Control Account',
            'document' => 'CTRL'.uniqid(),
            'ownership_state' => 'unmanaged',
        ]);
        $controlProfile = AccountProfile::create([
            'account_id' => (string) $controlAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Force Delete Control Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.497, -20.672],
            ],
            'is_active' => true,
        ]);
        MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $controlProfile->_id,
            'name' => 'Force Delete Control Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.497, -20.672],
            ],
            'is_active' => true,
        ]);

        $this->deleteJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}")
            ->assertOk();

        MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => $profileId,
            'name' => 'Force Delete Target Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.498, -20.673],
            ],
            'is_active' => true,
        ]);
        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->exists()
        );

        $response = $this->postJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}/force_delete");

        $response->assertOk();
        $this->assertFalse(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->exists()
        );
        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', (string) $controlProfile->_id)
                ->exists()
        );
    }

    public function test_update_forbidden_without_update_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->patchJson($this->baseAdminUrl, [
            'name' => 'Forbidden Update',
        ]);

        $response->assertStatus(403);
    }

    public function test_delete_forbidden_without_delete_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->deleteJson($this->baseAdminUrl);

        $response->assertStatus(403);
    }

    public function test_account_user_manage_attaches_and_detaches(): void
    {
        $user = $this->userService->create($this->account, [
            'name' => 'Member',
            'email' => 'member@example.org',
            'password' => 'Secret!234',
        ], (string) $this->role->_id);

        $role = $this->account->roleTemplates()->create([
            'name' => 'Viewer',
            'permissions' => ['account-users:view'],
        ]);

        $attachResponse = $this->postJson(
            sprintf('%s/users/%s/roles/%s', $this->baseAdminUrl, $user->_id, $role->_id)
        );

        $attachResponse->assertOk();

        $detachResponse = $this->deleteJson(
            sprintf('%s/users/%s/roles/%s', $this->baseAdminUrl, $user->_id, $role->_id)
        );

        $detachResponse->assertOk();
    }

    public function test_legacy_accounts_create_route_returns_policy_rejection(): void
    {
        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => fake()->company(),
            'ownership_state' => 'tenant_owned',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
        $response->assertJsonPath('meta.use_endpoint', '/admin/api/v1/account_onboardings');
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

    /**
     * @return array{Account, AccountProfile}
     */
    private function createDraftPersonalAccountAggregate(): array
    {
        $name = fake()->unique()->company();
        $account = Account::query()->create([
            'name' => $name,
            'document' => 'DOC-PUBLICATION-'.uniqid('', true),
            'ownership_state' => 'tenant_owned',
            'publication' => [
                'status' => AccountPublicationStateService::DRAFT,
                'publish_at' => null,
            ],
        ]);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => $name,
        ]);

        return [$account->fresh(), $profile->fresh()];
    }
}
