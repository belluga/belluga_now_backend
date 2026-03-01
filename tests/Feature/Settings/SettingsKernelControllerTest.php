<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;
use MongoDB\Driver\Exception\Exception as MongoDriverException;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class SettingsKernelControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;
    private static bool $landlordNamespaceRegistered = false;
    private static bool $conditionalStabilityNamespacesRegistered = false;

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

        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'radius' => [
                    'min_km' => 1,
                    'default_km' => 5,
                    'max_km' => 50,
                ],
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
            ],
            'events' => [
                'default_duration_hours' => 3,
                'mode' => 'basic',
            ],
            'push' => [
                'enabled' => false,
                'throttles' => ['daily' => 100],
                'max_ttl_days' => 7,
            ],
            'telemetry' => [
                'location_freshness_minutes' => 5,
                'trackers' => [],
            ],
        ]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'events:read',
            'push-settings:update',
            'telemetry-settings:update',
        ]);

        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser([
            'account-users:view',
            'events:read',
            'push-settings:update',
            'telemetry-settings:update',
        ]);

        Sanctum::actingAs($this->user, [
            'account-users:view',
            'events:read',
            'push-settings:update',
            'telemetry-settings:update',
        ]);
    }

    public function testSettingsSchemaEndpointReturnsRegisteredNamespaces(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/schema");
        $response->assertStatus(200);

        $response->assertJsonPath('data.schema_version', '1.0.0');
        $response->assertJsonPath('data.schema_version_policy.additive_changes', 'no_version_bump_required');
        $response->assertJsonPath('data.schema_version_policy.breaking_changes', 'version_bump_required');

        $namespaces = array_column($response->json('data.namespaces') ?? [], 'namespace');
        $this->assertContains('map_ui', $namespaces);
        $this->assertContains('events', $namespaces);
        $this->assertContains('push', $namespaces);
        $this->assertContains('telemetry', $namespaces);
    }

    public function testSettingsValuesEndpointReturnsNamespaceValues(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/values");
        $response->assertStatus(200);

        $response->assertJsonPath('data.map_ui.radius.default_km', 5);
        $response->assertJsonPath('data.events.default_duration_hours', 3);
        $response->assertJsonPath('data.push.max_ttl_days', 7);
        $response->assertJsonPath('data.telemetry.location_freshness_minutes', 5);
    }

    public function testPatchNamespaceAppliesPartialMergeByFieldPresence(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'default_duration_hours' => 4,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.default_duration_hours', 4);

        $values = $this->getJson("{$this->base_api_tenant}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.events.default_duration_hours', 4);
        $values->assertJsonPath('data.events.mode', 'basic');
    }

    public function testPatchNamespaceRejectsNullForNonNullableField(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'default_duration_hours' => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['default_duration_hours']);
    }

    public function testPatchNamespaceAcceptsNullClearForNullableField(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/push", [
            'throttles' => null,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.throttles', null);
        $response->assertJsonPath('data.max_ttl_days', 7);

        $values = $this->getJson("{$this->base_api_tenant}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.push.throttles', null);
        $values->assertJsonPath('data.push.max_ttl_days', 7);
    }

    public function testPatchNamespaceAppliesMixedSetAndClearAtomically(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/push", [
            'max_ttl_days' => 12,
            'throttles' => null,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.max_ttl_days', 12);
        $response->assertJsonPath('data.throttles', null);

        $values = $this->getJson("{$this->base_api_tenant}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.push.max_ttl_days', 12);
        $values->assertJsonPath('data.push.throttles', null);
    }

    public function testPatchNamespaceAcceptsNamespacedFieldPath(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'events.default_duration_hours' => 6,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.default_duration_hours', 6);
    }

    public function testPatchNamespaceRejectsEnvelopePayloadForm(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'events' => [
                'default_duration_hours' => 6,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['events']);
    }

    public function testSchemaExposesNavigationNodesAndConditionalMetadata(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/schema");
        $response->assertStatus(200);

        $namespaces = $response->json('data.namespaces') ?? [];
        $events = collect($namespaces)->firstWhere('namespace', 'events');
        $mapUi = collect($namespaces)->firstWhere('namespace', 'map_ui');

        $this->assertIsArray($events);
        $this->assertIsArray($mapUi);
        $this->assertNotEmpty($events['nodes'] ?? []);
        $this->assertNotEmpty($mapUi['nodes'] ?? []);

        $eventsFields = $events['fields'] ?? [];
        $stock = collect($eventsFields)->firstWhere('path', 'stock_enabled');
        $mapPoiAvailability = collect($eventsFields)->firstWhere('path', 'capabilities.map_poi.available');
        $this->assertIsArray($stock);
        $this->assertIsArray($mapPoiAvailability);
        $this->assertSame('settings.events.stock_enabled.label', $stock['label_i18n_key'] ?? null);
        $this->assertSame(
            'settings.events.capabilities.map_poi.available.label',
            $mapPoiAvailability['label_i18n_key'] ?? null
        );
        $this->assertIsArray($stock['visible_if'] ?? null);
    }

    public function testSchemaNavigationOrderingIsStable(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/schema");
        $response->assertStatus(200);

        $namespaces = $response->json('data.namespaces') ?? [];
        $mapUi = collect($namespaces)->firstWhere('namespace', 'map_ui');
        $this->assertIsArray($mapUi);

        $rootNodeIds = array_map(
            static fn (array $node): ?string => $node['id'] ?? null,
            $mapUi['nodes'] ?? []
        );
        $this->assertSame([
            'map_ui.group.radius',
            'map_ui.group.poi_time_window_days',
        ], $rootNodeIds);

        $radiusNode = collect($mapUi['nodes'] ?? [])->firstWhere('id', 'map_ui.group.radius');
        $radiusChildren = array_map(
            static fn (array $node): ?string => $node['id'] ?? null,
            $radiusNode['children'] ?? []
        );
        $this->assertSame([
            'map_ui.radius.min_km',
            'map_ui.radius.default_km',
            'map_ui.radius.max_km',
        ], $radiusChildren);
    }

    public function testSchemaNodesExposeEveryRegisteredFieldAsRenderableNode(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/schema");
        $response->assertStatus(200);

        $namespaces = $response->json('data.namespaces') ?? [];
        foreach ($namespaces as $namespace) {
            $schemaFields = $namespace['fields'] ?? [];
            $expectedFieldIds = array_map(
                static fn (array $field): ?string => $field['id'] ?? null,
                $schemaFields
            );
            sort($expectedFieldIds);

            $actualFieldIds = [];
            $walker = function (array $nodes) use (&$walker, &$actualFieldIds): void {
                foreach ($nodes as $node) {
                    if (($node['type'] ?? null) === 'field') {
                        $actualFieldIds[] = $node['id'] ?? null;
                    }

                    if (($node['type'] ?? null) === 'group') {
                        $walker($node['children'] ?? []);
                    }
                }
            };
            $walker($namespace['nodes'] ?? []);
            sort($actualFieldIds);

            $this->assertSame($expectedFieldIds, $actualFieldIds);
        }
    }

    public function testConditionalMetadataRemainsStableAcrossLabelI18nAndOrderChanges(): void
    {
        $this->ensureConditionalStabilityNamespacesRegistered();

        $response = $this->getJson("{$this->base_api_tenant}settings/schema");
        $response->assertStatus(200);

        $namespaces = collect($response->json('data.namespaces') ?? []);
        $v1 = $namespaces->firstWhere('namespace', 'settings_stability_v1');
        $v2 = $namespaces->firstWhere('namespace', 'settings_stability_v2');

        $this->assertIsArray($v1);
        $this->assertIsArray($v2);

        $v1Field = collect($v1['fields'] ?? [])->firstWhere('path', 'feature_flag');
        $v2Field = collect($v2['fields'] ?? [])->firstWhere('path', 'feature_flag');
        $this->assertIsArray($v1Field);
        $this->assertIsArray($v2Field);

        $this->assertNotSame($v1Field['label'] ?? null, $v2Field['label'] ?? null);
        $this->assertNotSame($v1Field['label_i18n_key'] ?? null, $v2Field['label_i18n_key'] ?? null);
        $this->assertNotSame($v1Field['order'] ?? null, $v2Field['order'] ?? null);

        $this->assertSame('settings.stability.feature_flag', $v1Field['id'] ?? null);
        $this->assertSame('settings.stability.feature_flag', $v2Field['id'] ?? null);
        $this->assertSame($v1Field['visible_if'] ?? null, $v2Field['visible_if'] ?? null);
        $this->assertSame($v1Field['enabled_if'] ?? null, $v2Field['enabled_if'] ?? null);
    }

    public function testAbilityFilteringHidesNamespacesAndBlocksPatch(): void
    {
        $restrictedUser = $this->createAccountUser([
            'account-users:view',
            'events:read',
        ]);

        Sanctum::actingAs($restrictedUser, [
            'account-users:view',
            'events:read',
        ]);

        $schema = $this->getJson("{$this->base_api_tenant}settings/schema");
        $schema->assertStatus(200);

        $namespaces = array_column($schema->json('data.namespaces') ?? [], 'namespace');
        $this->assertContains('map_ui', $namespaces);
        $this->assertContains('events', $namespaces);
        $this->assertNotContains('push', $namespaces);

        $patch = $this->patchJson("{$this->base_api_tenant}settings/values/push", [
            'enabled' => true,
        ]);

        $patch->assertStatus(403);
    }

    public function testTenantScopeRejectsSecondSettingsDocument(): void
    {
        $thrown = null;

        try {
            TenantSettings::query()->create([
                '_id' => 'settings_secondary',
                'events' => [
                    'mode' => 'legacy',
                ],
            ]);
        } catch (QueryException|MongoDriverException $throwable) {
            $thrown = $throwable;
        }

        $this->assertNotNull($thrown, 'Expected second tenant settings document insertion to fail.');
        $this->assertSame(1, TenantSettings::query()->count());
    }

    public function testLandlordScopeRejectsSecondSettingsDocument(): void
    {
        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'events' => [
                'mode' => 'basic',
            ],
        ]);

        $thrown = null;

        try {
            LandlordSettings::query()->create([
                '_id' => 'settings_secondary',
                'events' => [
                    'mode' => 'legacy',
                ],
            ]);
        } catch (QueryException|MongoDriverException $throwable) {
            $thrown = $throwable;
        }

        $this->assertNotNull($thrown, 'Expected second landlord settings document insertion to fail.');
        $this->assertSame(1, LandlordSettings::query()->count());
    }

    public function testTenantAndLandlordScopesAreIsolatedWhenLandlordNamespaceExists(): void
    {
        $this->ensureLandlordTestNamespaceRegistered();

        $this->asLandlordHost();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['*']);

        $hostApi = sprintf('http://%s/admin/api/v1/', $this->host);
        $landlordPatch = $this->patchJson($hostApi . 'settings/values/landlord_test_settings', [
            'feature_flag' => true,
        ]);
        $landlordPatch->assertStatus(200);
        $landlordPatch->assertJsonPath('data.feature_flag', true);

        $landlordValues = $this->getJson($hostApi . 'settings/values');
        $landlordValues->assertStatus(200);
        $landlordData = $landlordValues->json('data') ?? [];
        $this->assertTrue((bool) data_get($landlordData, 'landlord_test_settings.feature_flag'));
        $this->assertArrayNotHasKey('map_ui', $landlordData);
        $this->assertArrayNotHasKey('events', $landlordData);

        $this->asTenantHost();
        Sanctum::actingAs($this->user, $this->accountAbilities());

        $tenantValues = $this->getJson("{$this->base_api_tenant}settings/values");
        $tenantValues->assertStatus(200);
        $tenantData = $tenantValues->json('data') ?? [];
        $this->assertArrayNotHasKey('landlord_test_settings', $tenantData);
        $this->assertSame(5, data_get($tenantData, 'map_ui.radius.default_km'));
    }

    public function testLandlordOnBehalfTenantPatchDoesNotMutateLandlordScopeValues(): void
    {
        $this->ensureLandlordTestNamespaceRegistered();
        $this->asLandlordHost();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['*']);

        $hostApi = sprintf('http://%s/admin/api/v1/', $this->host);
        $this->patchJson($hostApi . 'settings/values/landlord_test_settings', [
            'feature_flag' => false,
        ])->assertStatus(200);

        $tenantPatch = $this->patchJson($hostApi . "{$this->tenant->slug}/settings/values/events", [
            'default_duration_hours' => 9,
        ]);
        $tenantPatch->assertStatus(200);
        $tenantPatch->assertJsonPath('data.default_duration_hours', 9);

        $landlordValues = $this->getJson($hostApi . 'settings/values');
        $landlordValues->assertStatus(200);
        $this->assertFalse((bool) data_get($landlordValues->json('data') ?? [], 'landlord_test_settings.feature_flag'));

        $this->asTenantHost();
        Sanctum::actingAs($this->user, $this->accountAbilities());
        $tenantValues = $this->getJson("{$this->base_api_tenant}settings/values");
        $tenantValues->assertStatus(200);
        $tenantValues->assertJsonPath('data.events.default_duration_hours', 9);
    }

    public function testSettingsMigrationsAreConfiguredAndCollectionsCarrySingletonValidator(): void
    {
        $paths = (array) config('multitenancy.tenant_migration_paths', []);
        $this->assertContains('packages/belluga/belluga_settings/database/migrations', $paths);

        $tenantExitCode = Artisan::call('tenants:artisan', [
            'artisanCommand' => 'migrate --database=tenant --path=packages/belluga/belluga_settings/database/migrations',
        ]);
        $this->assertSame(0, $tenantExitCode, Artisan::output());

        $landlordExitCode = Artisan::call('migrate', [
            '--database' => 'landlord',
            '--path' => 'packages/belluga/belluga_settings/database/migrations_landlord',
        ]);
        $this->assertSame(0, $landlordExitCode, Artisan::output());

        $tenantCollection = iterator_to_array(DB::connection('tenant')->getMongoDB()->listCollections([
            'filter' => ['name' => 'settings'],
        ]))[0] ?? null;
        $this->assertNotNull($tenantCollection);
        $tenantOptions = json_decode(json_encode($tenantCollection->getOptions()), true);
        $this->assertSame('settings_root', data_get($tenantOptions, 'validator.$expr.$eq.1'));

        $landlordCollection = iterator_to_array(DB::connection('landlord')->getMongoDB()->listCollections([
            'filter' => ['name' => 'settings'],
        ]))[0] ?? null;
        $this->assertNotNull($landlordCollection);
        $landlordOptions = json_decode(json_encode($landlordCollection->getOptions()), true);
        $this->assertSame('settings_root', data_get($landlordOptions, 'validator.$expr.$eq.1'));
    }

    public function testTenantScopedSettingsMigrationCommandSucceedsForExistingTenants(): void
    {
        $exitCode = Artisan::call('tenants:artisan', [
            'artisanCommand' => 'migrate --database=tenant --path=packages/belluga/belluga_settings/database/migrations',
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function testLandlordScopedSettingsMigrationCommandSucceeds(): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => 'landlord',
            '--path' => 'packages/belluga/belluga_settings/database/migrations_landlord',
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Settings Role ' . uniqid(),
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Settings User',
            'email' => uniqid('settings-user', true) . '@example.org',
            'password' => 'Secret!234',
            'timezone' => 'America/Sao_Paulo',
        ], (string) $role->_id);
    }

    /**
     * @return array<int, string>
     */
    private function accountAbilities(): array
    {
        return [
            'account-users:view',
            'events:read',
            'push-settings:update',
        ];
    }

    private function asLandlordHost(): void
    {
        $_SERVER['HTTP_HOST'] = $this->host;
        $_SERVER['SERVER_NAME'] = $this->host;
        $this->withServerVariables([
            'HTTP_HOST' => $this->host,
            'SERVER_NAME' => $this->host,
        ]);
    }

    private function asTenantHost(): void
    {
        $tenantHost = "{$this->tenant->subdomain}.{$this->host}";
        $_SERVER['HTTP_HOST'] = $tenantHost;
        $_SERVER['SERVER_NAME'] = $tenantHost;
        $this->withServerVariables([
            'HTTP_HOST' => $tenantHost,
            'SERVER_NAME' => $tenantHost,
        ]);
    }

    private function ensureLandlordTestNamespaceRegistered(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        if ($registry->find('landlord_test_settings', 'landlord') !== null) {
            self::$landlordNamespaceRegistered = true;
            return;
        }

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'landlord_test_settings',
            scope: 'landlord',
            label: 'Landlord Test Settings',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'feature_flag' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Feature Flag',
                ],
            ],
        ));

        self::$landlordNamespaceRegistered = true;
    }

    private function ensureConditionalStabilityNamespacesRegistered(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        if (
            $registry->find('settings_stability_v1', 'tenant') !== null &&
            $registry->find('settings_stability_v2', 'tenant') !== null
        ) {
            self::$conditionalStabilityNamespacesRegistered = true;
            return;
        }

        $baseFields = [
            'mode' => [
                'id' => 'settings.stability.mode',
                'type' => 'string',
                'nullable' => false,
                'options' => [
                    ['value' => 'basic', 'label' => 'Basic'],
                    ['value' => 'advanced', 'label' => 'Advanced'],
                ],
            ],
            'feature_flag' => [
                'id' => 'settings.stability.feature_flag',
                'type' => 'boolean',
                'nullable' => false,
                'visible_if' => [
                    'groups' => [
                        [
                            'rules' => [
                                ['field_id' => 'settings.stability.mode', 'operator' => 'equals', 'value' => 'advanced'],
                            ],
                        ],
                    ],
                ],
                'enabled_if' => [
                    'groups' => [
                        [
                            'rules' => [
                                ['field_id' => 'settings.stability.mode', 'operator' => 'not_equals', 'value' => 'locked'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'settings_stability_v1',
            scope: 'tenant',
            label: 'Settings Stability V1',
            groupLabel: 'Core',
            ability: 'events:read',
            fields: [
                'mode' => array_merge($baseFields['mode'], [
                    'label' => 'Mode v1',
                    'label_i18n_key' => 'settings.stability_v1.mode.label',
                    'order' => 10,
                ]),
                'feature_flag' => array_merge($baseFields['feature_flag'], [
                    'label' => 'Feature Flag v1',
                    'label_i18n_key' => 'settings.stability_v1.feature_flag.label',
                    'order' => 20,
                ]),
            ],
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'settings_stability_v2',
            scope: 'tenant',
            label: 'Settings Stability V2',
            groupLabel: 'Core',
            ability: 'events:read',
            fields: [
                'mode' => array_merge($baseFields['mode'], [
                    'label' => 'Modo v2',
                    'label_i18n_key' => 'settings.stability_v2.mode.label',
                    'order' => 100,
                ]),
                'feature_flag' => array_merge($baseFields['feature_flag'], [
                    'label' => 'Ativar Recurso v2',
                    'label_i18n_key' => 'settings.stability_v2.feature_flag.label',
                    'order' => 5,
                ]),
            ],
        ));

        self::$conditionalStabilityNamespacesRegistered = true;
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);
        $payload = new InitializationPayload(
            landlord: [
                'name' => 'Landlord HQ',
            ],
            tenant: [
                'name' => $this->tenant->name,
                'subdomain' => $this->tenant->subdomain,
            ],
            role: [
                'name' => 'Root',
                'permissions' => ['*'],
            ],
            user: [
                'name' => 'Root User',
                'email' => 'root@example.org',
                'password' => 'Secret!234',
            ],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: [
                'light_logo_uri' => '/logos/light.png',
            ],
            pwaIcon: [
                'icon192_uri' => '/pwa/icon192.png',
            ],
            tenantDomains: [$this->tenant->subdomain . '.test'],
        );

        $service->initialize($payload);

        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
