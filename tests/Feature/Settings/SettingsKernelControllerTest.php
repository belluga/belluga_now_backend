<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        $tenant->domains()
            ->whereIn('type', [Tenant::DOMAIN_TYPE_APP_ANDROID, Tenant::DOMAIN_TYPE_APP_IOS])
            ->delete();
        $tenant->update(['app_domains' => []]);

        $landlordSettings = LandlordSettings::current();
        if ($landlordSettings === null) {
            $landlordSettings = new LandlordSettings;
            $landlordSettings->setAttribute('_id', 'settings_root');
        }
        $landlordSettings->setAttribute('tenant_public_auth', [
            'available_methods' => ['password', 'phone_otp'],
            'allow_tenant_customization' => true,
        ]);
        $landlordSettings->save();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'radius' => [
                    'min_km' => 1,
                    'default_km' => 5,
                    'max_km' => 50,
                ],
                'poi_time_window_days' => [
                    'past' => 0,
                    'future' => 0,
                ],
                'default_origin' => [
                    'lat' => -20.671339,
                    'lng' => -40.495395,
                    'label' => 'Praia do Morro',
                ],
                'filters' => [
                    [
                        'key' => 'event',
                        'label' => 'Eventos',
                        'image_uri' => 'https://tenant-omega.test/storage/map-filters/event.png',
                    ],
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
            'tenant_public_auth' => [
                'enabled_methods' => [],
            ],
            'app_links' => [
                'android' => [
                    'sha256_cert_fingerprints' => [
                        'AA:BB:CC:DD',
                    ],
                ],
                'ios' => [
                    'team_id' => 'ABCDE12345',
                    'paths' => ['/invite*'],
                ],
            ],
            'resend_email' => [
                'token' => 're_fixture_token',
                'from' => 'Belluga <noreply@example.org>',
                'to' => ['admin@example.org'],
                'cc' => [],
                'bcc' => [],
                'reply_to' => ['reply@example.org'],
            ],
        ]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'events:read',
            'discovery-filters-settings:update',
            'map-pois-settings:update',
            'push-settings:update',
            'telemetry-settings:update',
            'tenant-public-auth-settings:update',
        ]);

        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser([
            'account-users:view',
            'events:read',
            'discovery-filters-settings:update',
            'map-pois-settings:update',
            'push-settings:update',
            'telemetry-settings:update',
            'tenant-public-auth-settings:update',
        ]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, $this->accountAbilities());
    }

    public function test_settings_schema_endpoint_returns_registered_namespaces(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
        $response->assertStatus(200);

        $response->assertJsonPath('data.schema_version', '1.0.0');
        $response->assertJsonPath('data.schema_version_policy.additive_changes', 'no_version_bump_required');
        $response->assertJsonPath('data.schema_version_policy.breaking_changes', 'version_bump_required');

        $namespaces = array_column($response->json('data.namespaces') ?? [], 'namespace');
        $this->assertContains('map_ui', $namespaces);
        $this->assertContains('events', $namespaces);
        $this->assertContains('push', $namespaces);
        $this->assertContains('telemetry', $namespaces);
        $this->assertContains('tenant_public_auth', $namespaces);
        $this->assertContains('discovery_filters', $namespaces);
        $this->assertContains('app_links', $namespaces);
        $this->assertContains('resend_email', $namespaces);
    }

    public function test_settings_values_endpoint_returns_namespace_values(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $response->assertStatus(200);

        $response->assertJsonPath('data.map_ui.radius.default_km', 5);
        $response->assertJsonPath('data.map_ui.default_origin.lat', -20.671339);
        $response->assertJsonPath('data.map_ui.default_origin.lng', -40.495395);
        $response->assertJsonPath('data.map_ui.filters.0.key', 'event');
        $response->assertJsonPath('data.map_ui.filters.0.label', 'Eventos');
        $response->assertJsonPath(
            'data.map_ui.filters.0.image_uri',
            'https://tenant-omega.test/storage/map-filters/event.png'
        );
        $response->assertJsonPath('data.events.default_duration_hours', 3);
        $response->assertJsonPath('data.push.max_ttl_days', 7);
        $response->assertJsonPath('data.telemetry.location_freshness_minutes', 5);
        $response->assertJsonPath('data.tenant_public_auth.enabled_methods', []);
        $response->assertJsonPath('data.app_links.android.sha256_cert_fingerprints.0', 'AA:BB:CC:DD');
        $response->assertJsonPath('data.app_links.ios.team_id', 'ABCDE12345');
        $response->assertJsonPath('data.app_links.ios.paths.0', '/invite*');
        $response->assertJsonPath('data.resend_email.token', 're_fixture_token');
        $response->assertJsonPath('data.resend_email.from', 'Belluga <noreply@example.org>');
        $response->assertJsonPath('data.resend_email.to.0', 'admin@example.org');
        $response->assertJsonPath('data.resend_email.reply_to.0', 'reply@example.org');
    }

    public function test_patch_discovery_filters_persists_surface_filter_order_and_delete_semantics(): void
    {
        $payload = [
            'surfaces' => [
                'public_map.primary' => [
                    'target' => 'map_poi',
                    'primary_selection_mode' => 'single',
                    'filters' => [
                        [
                            'key' => 'profiles',
                            'target' => 'map_poi',
                            'label' => 'Perfis',
                            'image_uri' => 'https://tenant-omega.test/map-filters/profiles.png',
                            'query' => [
                                'entities' => ['account_profile'],
                                'types_by_entity' => [
                                    'account_profile' => ['venue'],
                                ],
                            ],
                        ],
                        [
                            'key' => 'events',
                            'target' => 'map_poi',
                            'label' => 'Eventos',
                            'image_uri' => 'https://tenant-omega.test/map-filters/events.png',
                            'query' => [
                                'entities' => ['event'],
                                'types_by_entity' => [
                                    'event' => ['show'],
                                ],
                                'taxonomy' => [
                                    'music_genre' => ['rock'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}settings/values/discovery_filters",
            $payload
        );

        $response->assertStatus(200);
        $surface = $response->json('data.surfaces')['public_map.primary'] ?? null;
        $this->assertSame('profiles', data_get($surface, 'filters.0.key'));
        $this->assertSame(
            'https://tenant-omega.test/map-filters/profiles.png',
            data_get($surface, 'filters.0.image_uri')
        );
        $this->assertSame('events', data_get($surface, 'filters.1.key'));
        $this->assertSame('rock', data_get($surface, 'filters.1.query.taxonomy.music_genre.0'));

        $deleteByReplacement = $this->patchJson(
            "{$this->base_tenant_api_admin}settings/values/discovery_filters",
            [
                'surfaces' => [
                    'public_map.primary' => [
                        'target' => 'map_poi',
                        'primary_selection_mode' => 'single',
                        'filters' => [
                            $payload['surfaces']['public_map.primary']['filters'][1],
                        ],
                    ],
                ],
            ]
        );

        $deleteByReplacement->assertStatus(200);
        $updatedSurface = $deleteByReplacement->json('data.surfaces')['public_map.primary'] ?? null;
        $this->assertSame('events', data_get($updatedSurface, 'filters.0.key'));
        $this->assertCount(
            1,
            data_get($updatedSurface, 'filters') ?? []
        );

        $values = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $valuesSurface = $values->json('data.discovery_filters.surfaces')['public_map.primary'] ?? null;
        $this->assertSame('events', data_get($valuesSurface, 'filters.0.key'));
        $this->assertSame(
            'https://tenant-omega.test/map-filters/events.png',
            data_get($values->json('data.discovery_filters.surfaces')['public_map.primary'] ?? null, 'filters.0.image_uri')
        );
    }

    public function test_patch_discovery_filters_requires_discovery_filters_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), [
            'account-users:view',
            'events:read',
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}settings/values/discovery_filters",
            [
                'surfaces' => [],
            ]
        );

        $response->assertForbidden();
    }

    public function test_patch_resend_email_rejects_invalid_sender_format(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/resend_email", [
            'from' => 'sender invalido',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from']);
    }

    public function test_patch_resend_email_accepts_valid_delivery_envelope(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/resend_email", [
            'from' => 'Belluga <noreply@belluga.space>',
            'to' => ['owner@example.org'],
            'cc' => ['ops@example.org'],
            'reply_to' => ['reply@example.org'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.from', 'Belluga <noreply@belluga.space>');
        $response->assertJsonPath('data.to.0', 'owner@example.org');
        $response->assertJsonPath('data.cc.0', 'ops@example.org');
        $response->assertJsonPath('data.reply_to.0', 'reply@example.org');
    }

    public function test_patch_namespace_applies_partial_merge_by_field_presence(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/events", [
            'default_duration_hours' => 4,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.default_duration_hours', 4);

        $values = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.events.default_duration_hours', 4);
        $values->assertJsonPath('data.events.mode', 'basic');
    }

    public function test_patch_namespace_rejects_null_for_non_nullable_field(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/events", [
            'default_duration_hours' => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['default_duration_hours']);
    }

    public function test_patch_namespace_accepts_null_clear_for_nullable_field(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/push", [
            'throttles' => null,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.throttles', null);
        $response->assertJsonPath('data.max_ttl_days', 7);

        $values = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.push.throttles', null);
        $values->assertJsonPath('data.push.max_ttl_days', 7);
    }

    public function test_patch_namespace_applies_mixed_set_and_clear_atomically(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/push", [
            'max_ttl_days' => 12,
            'throttles' => null,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.max_ttl_days', 12);
        $response->assertJsonPath('data.throttles', null);

        $values = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.push.max_ttl_days', 12);
        $values->assertJsonPath('data.push.throttles', null);
    }

    public function test_patch_namespace_accepts_namespaced_field_path(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/events", [
            'events.default_duration_hours' => 6,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.default_duration_hours', 6);
    }

    public function test_patch_tenant_public_auth_enforces_landlord_subset_governance(): void
    {
        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $tenant->makeCurrent();

        $tenantSettings = TenantSettings::current();
        $originalTenantAuth = $tenantSettings?->getAttribute('tenant_public_auth');

        try {
            $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/tenant_public_auth", [
                'enabled_methods' => ['phone_otp'],
            ]);

            $response->assertStatus(200);
            $response->assertJsonPath('data.enabled_methods.0', 'phone_otp');

            $values = $this->getJson("{$this->base_tenant_api_admin}settings/values");
            $values->assertStatus(200);
            $values->assertJsonPath('data.tenant_public_auth.enabled_methods.0', 'phone_otp');

            $rejected = $this->patchJson("{$this->base_tenant_api_admin}settings/values/tenant_public_auth", [
                'enabled_methods' => ['phone_otp', 'magic_link'],
            ]);

            $rejected->assertStatus(422);
            $rejected->assertJsonValidationErrors(['enabled_methods']);
        } finally {
            if ($tenantSettings === null) {
                $tenantSettings = new TenantSettings;
                $tenantSettings->setAttribute('_id', \Belluga\Settings\Models\SettingsDocument::ROOT_ID);
            }

            $tenantSettings->setAttribute('tenant_public_auth', $originalTenantAuth);
            $tenantSettings->save();
        }
    }

    public function test_patch_tenant_public_auth_rejects_tenant_override_when_landlord_disables_customization(): void
    {
        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $tenant->makeCurrent();

        $landlord = \Belluga\Settings\Models\Landlord\LandlordSettings::current();
        $original = $landlord?->getAttribute('tenant_public_auth');
        if ($landlord === null) {
            $landlord = new \Belluga\Settings\Models\Landlord\LandlordSettings;
            $landlord->setAttribute('_id', \Belluga\Settings\Models\SettingsDocument::ROOT_ID);
        }

        $landlord->setAttribute('tenant_public_auth', [
            'available_methods' => ['password', 'phone_otp'],
            'allow_tenant_customization' => false,
        ]);
        $landlord->save();

        try {
            $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/tenant_public_auth", [
                'enabled_methods' => ['phone_otp'],
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['enabled_methods']);
        } finally {
            $landlord->setAttribute('tenant_public_auth', $original);
            $landlord->save();
        }
    }

    public function test_patch_namespace_rejects_envelope_payload_form(): void
    {
        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/events", [
            'events' => [
                'default_duration_hours' => 6,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['events']);
    }

    public function test_patch_app_links_rejects_android_fingerprint_without_android_identifier(): void
    {
        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $tenant->domains()->where('type', Tenant::DOMAIN_TYPE_APP_ANDROID)->delete();
        $tenant = $tenant->fresh();
        $this->assertNull($tenant?->appDomainIdentifierForPlatform(Tenant::APP_PLATFORM_ANDROID));

        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/app_links", [
            'android.sha256_cert_fingerprints' => [
                '3E:72:4C:54:E9:53:26:7D:E6:E1:9B:F8:DC:53:30:2A:08:01:8E:36:40:4D:0C:CA:98:3B:46:84:53:E7:A9:A9',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['android.sha256_cert_fingerprints']);
    }

    public function test_patch_app_links_rejects_ios_team_id_without_ios_identifier(): void
    {
        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $tenant->domains()->where('type', Tenant::DOMAIN_TYPE_APP_IOS)->delete();

        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/app_links", [
            'ios.team_id' => 'ABCDE12345',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ios.team_id']);
    }

    public function test_patch_app_links_accepts_credentials_when_typed_identifiers_exist(): void
    {
        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.tenant.omega');
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_IOS, 'com.tenant.omega');

        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/app_links", [
            'android.sha256_cert_fingerprints' => [
                '3E:72:4C:54:E9:53:26:7D:E6:E1:9B:F8:DC:53:30:2A:08:01:8E:36:40:4D:0C:CA:98:3B:46:84:53:E7:A9:A9',
            ],
            'ios.team_id' => 'ABCDE12345',
            'ios.paths' => ['/invite*', '/accounts*'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.android.sha256_cert_fingerprints.0',
            '3E:72:4C:54:E9:53:26:7D:E6:E1:9B:F8:DC:53:30:2A:08:01:8E:36:40:4D:0C:CA:98:3B:46:84:53:E7:A9:A9'
        );
        $response->assertJsonPath('data.ios.team_id', 'ABCDE12345');
        $response->assertJsonPath('data.ios.paths.1', '/accounts*');
    }

    public function test_schema_exposes_navigation_nodes_and_conditional_metadata(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
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
        $multipleOccurrencesAvailability = collect($eventsFields)
            ->firstWhere('path', 'capabilities.multiple_occurrences.allow_multiple');
        $multipleOccurrencesMax = collect($eventsFields)
            ->firstWhere('path', 'capabilities.multiple_occurrences.max_occurrences');
        $mapPoiAvailability = collect($eventsFields)->firstWhere('path', 'capabilities.map_poi.available');
        $this->assertIsArray($stock);
        $this->assertNull($multipleOccurrencesAvailability);
        $this->assertNull($multipleOccurrencesMax);
        $this->assertIsArray($mapPoiAvailability);
        $this->assertSame('settings.events.stock_enabled.label', $stock['label_i18n_key'] ?? null);
        $this->assertSame(
            'settings.events.capabilities.map_poi.available.label',
            $mapPoiAvailability['label_i18n_key'] ?? null
        );
        $this->assertIsArray($stock['visible_if'] ?? null);
    }

    public function test_schema_navigation_ordering_is_stable(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
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
            'map_ui.group.default_origin',
            'map_ui.group.filters',
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

        $originNode = collect($mapUi['nodes'] ?? [])->firstWhere('id', 'map_ui.group.default_origin');
        $originChildren = array_map(
            static fn (array $node): ?string => $node['id'] ?? null,
            $originNode['children'] ?? []
        );
        $this->assertSame([
            'map_ui.default_origin.lat',
            'map_ui.default_origin.lng',
            'map_ui.default_origin.label',
        ], $originChildren);

        $filtersNode = collect($mapUi['nodes'] ?? [])->firstWhere('id', 'map_ui.group.filters');
        $filtersChildren = array_map(
            static fn (array $node): ?string => $node['id'] ?? null,
            $filtersNode['children'] ?? []
        );
        $this->assertSame([
            'map_ui.filters',
        ], $filtersChildren);
    }

    public function test_schema_nodes_expose_every_registered_field_as_renderable_node(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
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

    public function test_conditional_metadata_remains_stable_across_label_i18n_and_order_changes(): void
    {
        $this->ensureConditionalStabilityNamespacesRegistered();

        $response = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
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

    public function test_ability_filtering_hides_namespaces_and_blocks_patch(): void
    {
        $restrictedUser = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($restrictedUser, [
            'account-users:view',
            'events:read',
            'map-pois-settings:update',
        ]);

        $schema = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
        $schema->assertStatus(200);

        $namespaces = array_column($schema->json('data.namespaces') ?? [], 'namespace');
        $this->assertContains('map_ui', $namespaces);
        $this->assertContains('events', $namespaces);
        $this->assertNotContains('push', $namespaces);

        $patch = $this->patchJson("{$this->base_tenant_api_admin}settings/values/push", [
            'enabled' => true,
        ]);

        $patch->assertStatus(403);
    }

    public function test_tenant_scope_rejects_second_settings_document(): void
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

    public function test_landlord_scope_rejects_second_settings_document(): void
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

    public function test_tenant_and_landlord_scopes_are_isolated_when_landlord_namespace_exists(): void
    {
        $this->ensureLandlordTestNamespaceRegistered();

        $this->asLandlordHost();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['*']);

        $hostApi = sprintf('http://%s/admin/api/v1/', $this->host);
        $landlordPatch = $this->patchJson($hostApi.'settings/values/landlord_test_settings', [
            'feature_flag' => true,
        ]);
        $landlordPatch->assertStatus(200);
        $landlordPatch->assertJsonPath('data.feature_flag', true);

        $landlordValues = $this->getJson($hostApi.'settings/values');
        $landlordValues->assertStatus(200);
        $landlordData = $landlordValues->json('data') ?? [];
        $this->assertTrue((bool) data_get($landlordData, 'landlord_test_settings.feature_flag'));
        $this->assertArrayNotHasKey('map_ui', $landlordData);
        $this->assertArrayNotHasKey('events', $landlordData);

        $this->asTenantHost();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), $this->accountAbilities());

        $tenantValues = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $tenantValues->assertStatus(200);
        $tenantData = $tenantValues->json('data') ?? [];
        $this->assertArrayNotHasKey('landlord_test_settings', $tenantData);
        $this->assertSame(5, data_get($tenantData, 'map_ui.radius.default_km'));
    }

    public function test_landlord_on_behalf_tenant_patch_does_not_mutate_landlord_scope_values(): void
    {
        $this->ensureLandlordTestNamespaceRegistered();
        $this->asLandlordHost();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['*']);

        $hostApi = sprintf('http://%s/admin/api/v1/', $this->host);
        $this->patchJson($hostApi.'settings/values/landlord_test_settings', [
            'feature_flag' => false,
        ])->assertStatus(200);

        $tenantPatch = $this->patchJson($hostApi."{$this->tenant->slug}/settings/values/events", [
            'default_duration_hours' => 9,
        ]);
        $tenantPatch->assertStatus(200);
        $tenantPatch->assertJsonPath('data.default_duration_hours', 9);

        $landlordValues = $this->getJson($hostApi.'settings/values');
        $landlordValues->assertStatus(200);
        $this->assertFalse((bool) data_get($landlordValues->json('data') ?? [], 'landlord_test_settings.feature_flag'));

        $this->asTenantHost();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), $this->accountAbilities());
        $tenantValues = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $tenantValues->assertStatus(200);
        $tenantValues->assertJsonPath('data.events.default_duration_hours', 9);
    }

    public function test_settings_migrations_are_configured_and_collections_carry_singleton_validator(): void
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

    public function test_tenant_scoped_settings_migration_command_succeeds_for_existing_tenants(): void
    {
        $exitCode = Artisan::call('tenants:artisan', [
            'artisanCommand' => 'migrate --database=tenant --path=packages/belluga/belluga_settings/database/migrations',
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_landlord_scoped_settings_migration_command_succeeds(): void
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
            'name' => 'Settings Role '.uniqid(),
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Settings User',
            'email' => uniqid('settings-user', true).'@example.org',
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
            'discovery-filters-settings:update',
            'map-pois-settings:update',
            'push-settings:update',
            'telemetry-settings:update',
            'tenant-public-auth-settings:update',
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

    private function upsertTypedAppDomain(Tenant $tenant, string $type, string $identifier): void
    {
        $existing = $tenant->domains()
            ->where('type', $type)
            ->first();

        if ($existing === null) {
            $tenant->domains()->create([
                'type' => $type,
                'path' => $identifier,
            ]);

            return;
        }

        $existing->path = $identifier;
        $existing->save();
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
            tenantDomains: [$this->tenant->subdomain.'.test'],
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
