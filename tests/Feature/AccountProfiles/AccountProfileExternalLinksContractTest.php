<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileExternalLinkRegistry;
use App\Application\AccountProfiles\AccountProfileExternalLinkService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Exceptions\AccountProfileExternalLinksCapabilityDisabledException;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Http\Api\v1\Requests\AccountProfileStoreRequest;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileExternalLinksContractTest extends TestCaseTenant
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
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), [
            'account-users:view',
            'account-users:create',
            'account-users:update',
        ]);
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();
        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
        ]);
        TenantProfileType::query()->create([
            'type' => 'partner',
            'label' => 'Partner',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'has_external_links' => true,
            ],
        ]);
    }

    public function test_item_crud_returns_authoritative_profile_with_stable_identity_registry_order_and_revision(): void
    {
        $profile = $this->profile();

        $website = $this->postJson($this->linksUrl($profile), [
            'type' => 'website',
            'url' => '  https://example.org/about?from=profile  ',
            'label' => '  Official site  ',
        ]);
        $website->assertCreated()
            ->assertJsonPath('data.external_links_limit', 3)
            ->assertJsonPath('data.external_links.0.type', 'website')
            ->assertJsonPath('data.external_links.0.url', 'https://example.org/about?from=profile')
            ->assertJsonPath('data.external_links.0.label', 'Official site');
        $websiteId = (string) $website->json('data.external_links.0.id');
        $this->assertNotSame('', $websiteId);
        $firstRevision = (int) $website->json('data.aggregate_revision');

        $instagram = $this->postJson($this->linksUrl($profile), [
            'type' => 'instagram',
            'url' => 'https://www.instagram.com/belluga.now/',
        ]);
        $instagram->assertCreated()
            ->assertJsonPath('data.external_links.0.type', 'instagram')
            ->assertJsonPath('data.external_links.1.id', $websiteId);
        $instagramId = (string) $instagram->json('data.external_links.0.id');
        $this->assertGreaterThan($firstRevision, (int) $instagram->json('data.aggregate_revision'));

        $updated = $this->patchJson($this->linksUrl($profile).'/'.$instagramId, [
            'url' => 'https://instagram.com/belluga.updated',
        ]);
        $updated->assertOk()
            ->assertJsonPath('data.external_links.0.id', $instagramId)
            ->assertJsonPath('data.external_links.0.type', 'instagram')
            ->assertJsonPath('data.external_links.0.url', 'https://instagram.com/belluga.updated');

        $this->deleteJson($this->linksUrl($profile).'/'.$websiteId)
            ->assertOk()
            ->assertJsonCount(1, 'data.external_links')
            ->assertJsonPath('data.external_links.0.id', $instagramId);

        $this->getJson($this->profileUrl($profile))
            ->assertOk()
            ->assertJsonPath('data.external_links_limit', 3)
            ->assertJsonPath('data.external_links.0.id', $instagramId);

        $this->getJson($this->base_api_tenant.'account_profiles/'.$profile->slug)
            ->assertOk()
            ->assertJsonPath('data.external_links.0.id', $instagramId)
            ->assertJsonMissingPath('data.external_links_limit');
    }

    public function test_registry_validation_enforces_supported_unique_bounded_https_destinations_and_label_policy(): void
    {
        $profile = $this->profile();

        foreach ([
            ['type' => 'unknown', 'url' => 'https://example.org/x'],
            ['type' => 'facebook', 'url' => 'http://facebook.com/belluga'],
            ['type' => 'facebook', 'url' => 'https://facebook.com.evil.test/belluga'],
            ['type' => 'facebook', 'url' => 'https://user@facebook.com/belluga'],
            ['type' => 'youtube', 'url' => 'https://youtube.com/'],
            ['type' => 'website', 'url' => 'https://example.org', 'label' => ''],
            ['type' => 'instagram', 'url' => 'https://instagram.com/belluga', 'label' => 'Forbidden'],
            ['type' => 'website', 'url' => str_repeat('x', 2049), 'label' => 'Too long'],
        ] as $payload) {
            $this->postJson($this->linksUrl($profile), $payload)->assertUnprocessable();
        }

        $this->postJson($this->linksUrl($profile), [
            'type' => 'instagram',
            'url' => 'https://instagram.com/belluga',
        ])->assertCreated();
        $this->postJson($this->linksUrl($profile), [
            'type' => 'instagram',
            'url' => 'https://instagram.com/duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->postJson($this->linksUrl($profile), [
            'type' => 'facebook',
            'url' => 'https://facebook.com/belluga',
        ])->assertCreated();
        $this->postJson($this->linksUrl($profile), [
            'type' => 'spotify',
            'url' => 'https://open.spotify.com/artist/abc',
        ])->assertCreated();
        $this->postJson($this->linksUrl($profile), [
            'type' => 'tiktok',
            'url' => 'https://www.tiktok.com/@belluga',
        ])->assertUnprocessable()->assertJsonValidationErrors(['external_links_limit']);
    }

    public function test_patch_prohibits_identity_and_type_and_unknown_or_foreign_items_are_not_found(): void
    {
        $profile = $this->profile();
        [$otherAccount] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
        ]);
        $otherProfile = $this->profile('Other Partner', 'other-partner', $otherAccount);
        $created = $this->postJson($this->linksUrl($profile), [
            'type' => 'facebook',
            'url' => 'https://facebook.com/belluga',
        ]);
        $id = (string) $created->json('data.external_links.0.id');

        $this->patchJson($this->linksUrl($profile).'/'.$id, [
            'id' => 'replacement',
            'type' => 'spotify',
            'url' => 'https://open.spotify.com/artist/abc',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id', 'type']);
        $this->patchJson($this->linksUrl($profile).'/missing', [
            'url' => 'https://facebook.com/changed',
        ])->assertNotFound();
        $this->deleteJson($this->linksUrl($otherProfile).'/'.$id)->assertNotFound();
    }

    public function test_disabled_capability_omits_surfaces_rejects_with_exact_code_and_restores_dormant_links(): void
    {
        $profile = $this->profile();
        $created = $this->postJson($this->linksUrl($profile), [
            'type' => 'youtube',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertCreated();
        $id = (string) $created->json('data.external_links.0.id');

        $this->setCapability(false);

        $adminRead = $this->getJson($this->profileUrl($profile))->assertOk();
        $adminData = $adminRead->json('data');
        $this->assertIsArray($adminData);
        $this->assertArrayNotHasKey('external_links', $adminData, json_encode($adminData, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('external_links_limit', $adminData, json_encode($adminData, JSON_THROW_ON_ERROR));
        $this->getJson($this->base_api_tenant.'account_profiles/'.$profile->slug)
            ->assertOk()
            ->assertJsonMissingPath('data.external_links')
            ->assertJsonMissingPath('data.external_links_limit');

        $blocked = $this->patchJson($this->linksUrl($profile).'/'.$id, [
            'url' => 'https://youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
        $blocked->assertUnprocessable()
            ->assertJsonPath('code', 'account_profile_external_links_capability_disabled')
            ->assertJsonValidationErrors(['external_links']);

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $stored = AccountProfile::query()->findOrFail($profile->getKey());
        $this->assertSame($id, $stored->external_links[0]['id']);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $stored->external_links[0]['url']);

        $this->setCapability(true);
        $this->getJson($this->profileUrl($profile))
            ->assertOk()
            ->assertJsonPath('data.external_links.0.id', $id)
            ->assertJsonPath('data.external_links.0.url', 'https://youtu.be/dQw4w9WgXcQ');
    }

    public function test_missing_null_and_malformed_capabilities_fail_closed_without_erasing_storage(): void
    {
        $profile = $this->profile('Capability Probe', 'capability-probe');

        foreach ([[], ['has_external_links' => null], ['has_external_links' => 'true']] as $capabilities) {
            $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
            $profile->external_links = [[
                'id' => 'dormant',
                'type' => 'website',
                'url' => 'https://example.org',
                'label' => 'Dormant',
            ]];
            $profile->save();
            TenantProfileType::query()->where('type', 'partner')->update(['capabilities' => $capabilities]);

            $this->postJson($this->linksUrl($profile), [
                'type' => 'facebook',
                'url' => 'https://facebook.com/belluga',
            ])->assertUnprocessable()->assertJsonPath('code', 'account_profile_external_links_capability_disabled');
            $this->getJson($this->profileUrl($profile))->assertJsonMissingPath('data.external_links');
            $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
            $this->assertSame('dormant', AccountProfile::query()->findOrFail($profile->getKey())->external_links[0]['id']);

            $this->setCapability(true);
        }
    }

    public function test_generic_profile_create_and_patch_reject_external_link_mutation_fields(): void
    {
        $storeRules = (new AccountProfileStoreRequest)->rules();
        $this->assertTrue(Validator::make([
            'external_links' => [],
        ], [
            'external_links' => $storeRules['external_links'],
        ])->fails());

        $profile = $this->profile();
        $this->patchJson($this->profileUrl($profile), [
            'external_links' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['external_links']);
    }

    public function test_external_link_mutations_require_update_ability(): void
    {
        $profile = $this->profile();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $this->postJson($this->linksUrl($profile), [
            'type' => 'website',
            'url' => 'https://example.org',
            'label' => 'Forbidden',
        ])->assertForbidden();
        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $this->assertSame([], AccountProfile::query()->findOrFail($profile->getKey())->external_links ?? []);
    }

    public function test_request_id_replays_post_and_delete_without_duplicate_or_missing_item_failures(): void
    {
        $profile = $this->profile();
        $createHeaders = ['X-Request-Id' => 'external-links-create-replay'];
        $payload = [
            'type' => 'website',
            'url' => 'https://example.org/replay',
            'label' => 'Replay',
        ];

        $first = $this->withHeaders($createHeaders)->postJson($this->linksUrl($profile), $payload)->assertCreated();
        $second = $this->withHeaders($createHeaders)->postJson($this->linksUrl($profile), $payload)->assertCreated();
        $id = (string) $first->json('data.external_links.0.id');
        $this->assertSame($id, $second->json('data.external_links.0.id'));
        $this->assertSame($first->json('data.aggregate_revision'), $second->json('data.aggregate_revision'));
        $second->assertJsonCount(1, 'data.external_links');

        $deleteHeaders = ['X-Request-Id' => 'external-links-delete-replay'];
        $this->withHeaders($deleteHeaders)->deleteJson($this->linksUrl($profile).'/'.$id)
            ->assertOk()->assertJsonCount(0, 'data.external_links');
        $this->withHeaders($deleteHeaders)->deleteJson($this->linksUrl($profile).'/'.$id)
            ->assertOk()->assertJsonCount(0, 'data.external_links');
    }

    public function test_deletion_fence_returns_conflict_without_writing_links(): void
    {
        $profile = $this->profile();
        $this->account->setAttribute('account_profile_deletion_gate', [
            'attempt_id' => 'external-links-deletion-attempt',
            'attempt_generation' => 1,
        ]);
        $this->account->save();

        $this->withHeaders(['X-Request-Id' => 'external-links-deletion-fence'])
            ->postJson($this->linksUrl($profile), [
                'type' => 'instagram',
                'url' => 'https://instagram.com/blocked',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'A concurrency conflict occurred. Please try again.');

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $this->assertSame([], AccountProfile::query()->findOrFail($profile->getKey())->external_links ?? []);
    }

    public function test_enabled_observation_before_disable_commits_to_dormant_snapshot_and_restores_exactly(): void
    {
        $profile = $this->profile();
        $service = $this->externalLinksWithCapabilityReadHook(function (bool $observed): void {
            $this->assertTrue($observed);
            $this->setCapability(false);
        });

        $updated = $service->create($profile, [
            'type' => 'spotify',
            'url' => 'https://open.spotify.com/artist/schedule-a',
        ], 'external-links-schedule-a');
        $dormant = $updated->external_links;
        $this->assertCount(1, $dormant);

        $this->getJson($this->profileUrl($profile))->assertOk()->assertJsonMissingPath('data.external_links');
        $this->setCapability(true);
        $restored = $this->getJson($this->profileUrl($profile))->assertOk();
        $this->assertSame($dormant, $restored->json('data.external_links'));
    }

    public function test_disable_before_authoritative_read_rejects_without_revision_or_array_write(): void
    {
        $profile = $this->profile();
        $initialRevision = (int) $profile->aggregate_revision;
        $this->setCapability(false);
        $service = $this->externalLinksWithCapabilityReadHook(function (bool $observed): void {
            $this->assertFalse($observed);
        });

        try {
            $service->create($profile, [
                'type' => 'facebook',
                'url' => 'https://facebook.com/schedule-b',
            ], 'external-links-schedule-b');
            $this->fail('Disabled capability must reject the mutation.');
        } catch (AccountProfileExternalLinksCapabilityDisabledException $exception) {
            $this->assertSame(
                AccountProfileExternalLinksCapabilityDisabledException::ERROR_CODE,
                'account_profile_external_links_capability_disabled',
            );
            $this->assertArrayHasKey('external_links', $exception->errors());
        }

        $stored = AccountProfile::query()->findOrFail($profile->getKey());
        $this->assertSame($initialRevision, (int) $stored->aggregate_revision);
        $this->assertSame([], $stored->external_links ?? []);
    }

    public function test_concurrent_snapshot_change_is_rejected_by_existing_revision_cas_without_recomposition(): void
    {
        $profile = $this->profile();
        $service = $this->externalLinksWithCapabilityReadHook(function (bool $observed) use ($profile): void {
            $this->assertTrue($observed);
            $concurrent = AccountProfile::query()->findOrFail($profile->getKey());
            $concurrent->external_links = [[
                'id' => 'concurrent-link',
                'type' => 'website',
                'url' => 'https://concurrent.example.org',
                'label' => 'Concurrent',
            ]];
            $concurrent->aggregate_revision = (int) $concurrent->aggregate_revision + 1;
            $concurrent->save();
        });

        try {
            $service->create($profile, [
                'type' => 'instagram',
                'url' => 'https://instagram.com/stale-composition',
            ], 'external-links-stale-cas');
            $this->fail('A stale external-links composition must be rejected.');
        } catch (ConcurrencyConflictException) {
            $this->addToAssertionCount(1);
        }

        $stored = AccountProfile::query()->findOrFail($profile->getKey());
        $this->assertSame('concurrent-link', $stored->external_links[0]['id']);
        $this->assertCount(1, $stored->external_links);
    }

    public function test_high_profile_overlapping_writes_preserve_the_competing_snapshot_in_every_batch(): void
    {
        $profile = $this->profile();

        for ($batch = 0; $batch < 5; $batch++) {
            for ($operation = 0; $operation < 20; $operation++) {
                $snapshot = AccountProfile::query()->findOrFail($profile->getKey());
                $competingId = "concurrent-{$batch}-{$operation}";
                $service = $this->externalLinksWithCapabilityReadHook(
                    function (bool $observed) use ($snapshot, $competingId): void {
                        $this->assertTrue($observed);
                        $concurrent = AccountProfile::query()->findOrFail($snapshot->getKey());
                        $concurrent->external_links = [[
                            'id' => $competingId,
                            'type' => 'website',
                            'url' => 'https://concurrent.example.org/'.$competingId,
                            'label' => 'Concurrent',
                        ]];
                        $concurrent->aggregate_revision = (int) $concurrent->aggregate_revision + 1;
                        $concurrent->save();
                    },
                );

                try {
                    $service->create($snapshot, [
                        'type' => 'instagram',
                        'url' => 'https://instagram.com/stale-'.$batch.'-'.$operation,
                    ], 'external-links-high-profile-'.$batch.'-'.$operation);
                    $this->fail('Every stale composition must be rejected.');
                } catch (ConcurrencyConflictException) {
                    $this->addToAssertionCount(1);
                }

                $stored = AccountProfile::query()->findOrFail($profile->getKey());
                $this->assertSame($competingId, $stored->external_links[0]['id']);
                $this->assertCount(1, $stored->external_links);
            }
        }
    }

    public function test_primary_tenant_endpoint_cannot_mutate_a_secondary_tenant_profile_id(): void
    {
        $primary = $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $secondary = Tenant::create([
            'name' => 'External Links Isolation Secondary',
            'subdomain' => 'external-links-isolation-secondary',
        ]);

        try {
            $secondary->makeCurrent();
            $secondaryAccount = Account::query()->create([
                'name' => 'Secondary Account',
                'ownership_state' => 'unmanaged',
            ]);
            TenantProfileType::query()->create([
                'type' => 'partner',
                'label' => 'Partner',
                'allowed_taxonomies' => [],
                'capabilities' => ['has_external_links' => true],
            ]);
            $secondaryProfile = AccountProfile::query()->create([
                'account_id' => (string) $secondaryAccount->getKey(),
                'profile_type' => 'partner',
                'display_name' => 'Secondary Partner',
                'slug' => 'secondary-partner',
                'visibility' => 'public',
                'is_active' => true,
                'aggregate_revision' => 1,
            ]);
            $secondaryProfileId = (string) $secondaryProfile->getKey();

            $primary->makeCurrent();
            $this->postJson(
                $this->base_tenant_api_admin.'account_profiles/'.$secondaryProfileId.'/external_links',
                [
                    'type' => 'website',
                    'url' => 'https://cross-tenant.example.org',
                    'label' => 'Must Not Persist',
                ],
            )->assertNotFound();

            $secondary->makeCurrent();
            $this->assertSame(
                [],
                AccountProfile::query()->findOrFail($secondaryProfileId)->external_links ?? [],
            );
        } finally {
            $primary->makeCurrent();
            $secondary->forceDelete();
        }
    }

    private function profile(
        string $name = 'External Links Partner',
        string $slug = 'external-links-partner',
        ?Account $account = null,
    ): AccountProfile {
        return AccountProfile::query()->create([
            'account_id' => (string) ($account ?? $this->account)->getKey(),
            'profile_type' => 'partner',
            'display_name' => $name,
            'slug' => $slug,
            'visibility' => 'public',
            'is_active' => true,
            'aggregate_revision' => 1,
        ])->fresh();
    }

    private function externalLinksWithCapabilityReadHook(\Closure $afterRead): AccountProfileExternalLinkService
    {
        $profileTypes = new class(app(PoiVisualNormalizer::class), app(AccountProfileTypeMediaService::class), app(AccountProfileTypeCapabilityCatalog::class), $afterRead) extends AccountProfileRegistryService
        {
            public function __construct(
                PoiVisualNormalizer $poiVisualNormalizer,
                AccountProfileTypeMediaService $mediaService,
                AccountProfileTypeCapabilityCatalog $capabilityCatalog,
                private readonly \Closure $afterRead,
            ) {
                parent::__construct($poiVisualNormalizer, $mediaService, $capabilityCatalog);
            }

            public function hasExternalLinksAuthoritatively(string $profileType): bool
            {
                $observed = parent::hasExternalLinksAuthoritatively($profileType);
                ($this->afterRead)($observed);

                return $observed;
            }
        };

        return new AccountProfileExternalLinkService(
            app(AccountProfileExternalLinkRegistry::class),
            $profileTypes,
            app(AccountProfileManagementService::class),
        );
    }

    private function profileUrl(AccountProfile $profile): string
    {
        return $this->base_tenant_api_admin.'account_profiles/'.$profile->getKey();
    }

    private function linksUrl(AccountProfile $profile): string
    {
        return $this->profileUrl($profile).'/external_links';
    }

    private function setCapability(bool $enabled): void
    {
        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        TenantProfileType::query()->where('type', 'partner')->update([
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'has_external_links' => $enabled,
            ],
        ]);
    }

    private function initializeSystem(): void
    {
        app(SystemInitializationService::class)->initialize(new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => $this->tenant->name, 'subdomain' => $this->tenant->subdomain],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: [$this->tenant->subdomain.'.'.$this->host],
        ));
    }
}
