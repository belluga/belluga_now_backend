<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Support\MongoCommandTrace;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class AccountProfileCapabilityTypeSetProviderTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Tenant Capability', 'subdomain' => 'tenant-capability'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'root-capability@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['tenant-capability.test'],
            ));
            self::$bootstrapped = true;
        }
        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_executes_one_bounded_and_query_on_typed_value_leaves(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'capabilities' => [
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
            ],
        ]);
        TenantProfileType::create([
            'type' => 'disabled_venue',
            'capabilities' => [
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
            ],
        ]);
        DB::connection('tenant')->getDatabase()->selectCollection('account_profile_types')->insertMany([
            [
                'type' => 'malformed_boolean_value',
                'capabilities' => [
                    'location_policy' => ['value' => 'optional', 'parameters' => []],
                    'is_physical_host_enabled' => ['value' => [true], 'parameters' => []],
                ],
            ],
            [
                'type' => 'malformed_envelope',
                'capabilities' => [[
                    'location_policy' => ['value' => 'optional', 'parameters' => []],
                    'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                ]],
            ],
            [
                'type' => 'malformed_enum_value',
                'capabilities' => [
                    'location_policy' => ['value' => ['optional'], 'parameters' => []],
                    'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                ],
            ],
        ]);

        $resolver = app(AccountProfileCapabilityResolverContract::class);

        $this->assertSame(['venue'], $resolver->typeIdsWhereAllEffectiveValues([
            'is_physical_host_enabled' => true,
        ]));
    }

    public function test_rejects_empty_invalid_and_fail_closed_criteria(): void
    {
        $resolver = app(AccountProfileCapabilityResolverContract::class);

        foreach ([
            [],
            ['location_policy' => []],
            ['location_policy' => 'disabled'],
            ['is_physical_host_enabled' => false],
            ['unknown' => true],
        ] as $criteria) {
            try {
                $resolver->typeIdsWhereAllEffectiveValues($criteria);
                $this->fail('Invalid criteria must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_host_and_map_type_sets_use_their_typed_compound_indexes(): void
    {
        TenantProfileType::query()->delete();
        foreach (range(1, 24) as $index) {
            TenantProfileType::create([
                'type' => sprintf('type_%02d', $index),
                'capabilities' => [
                    'location_policy' => [
                        'value' => $index % 3 === 0 ? 'disabled' : 'optional',
                        'parameters' => [],
                    ],
                    'is_map_poi_enabled' => ['value' => $index % 2 === 0, 'parameters' => []],
                    'is_physical_host_enabled' => ['value' => $index % 4 === 0, 'parameters' => []],
                ],
            ]);
        }

        $database = DB::connection('tenant')->getDatabase();
        $scenarios = [
            [
                'index' => 'idx_account_profile_types_map_poi_location_policy_v1',
                'filter' => [
                    'capabilities.is_map_poi_enabled.value' => true,
                    'capabilities.location_policy.value' => ['$in' => ['optional', 'required']],
                ],
            ],
            [
                'index' => 'idx_account_profile_types_physical_host_location_policy_v1',
                'filter' => [
                    'capabilities.is_physical_host_enabled.value' => true,
                    'capabilities.location_policy.value' => ['$in' => ['optional', 'required']],
                ],
            ],
            [
                'index' => 'idx_account_profile_types_location_policy_v1',
                'filter' => [
                    'capabilities.location_policy.value' => ['$in' => ['optional', 'required']],
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $explain = $this->native($database->command([
                'explain' => [
                    'find' => 'account_profile_types',
                    'filter' => $scenario['filter'],
                    'projection' => ['type' => 1],
                    'sort' => ['type' => 1],
                    'hint' => $scenario['index'],
                ],
                'verbosity' => 'executionStats',
            ])->toArray()[0]);

            $this->assertContains($scenario['index'], $this->valuesForKey($explain, 'indexName'));
            $this->assertLessThanOrEqual(
                24,
                (int) ($explain['executionStats']['totalDocsExamined'] ?? PHP_INT_MAX),
            );
        }
    }

    public function test_loaded_and_unloaded_profile_resolution_matches_and_bounds_type_lookup(): void
    {
        TenantProfileType::query()->delete();
        $type = TenantProfileType::create([
            'type' => 'resolver_type',
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
            ],
        ]);
        $resolver = app(AccountProfileCapabilityResolverContract::class);
        $loaded = new AccountProfile(['profile_type' => 'resolver_type']);
        $loaded->setRelation('profileType', $type);
        $loadedTrace = $this->captureMongoCommands(
            fn (): array => $resolver->resolveForProfile($loaded, 'is_queryable'),
        );
        $this->assertSame(0, $loadedTrace->countForCollection('account_profile_types', 'find'));

        $unloaded = new AccountProfile(['profile_type' => 'resolver_type']);
        $resolved = null;
        $unloadedTrace = $this->captureMongoCommands(function () use ($resolver, $unloaded, &$resolved): void {
            $resolved = $resolver->resolveForProfile($unloaded, 'is_queryable');
        });
        $this->assertTrue($resolved['effective']['value'] ?? false);
        $this->assertSame(1, $unloadedTrace->countForCollection('account_profile_types', 'find'));
    }

    public function test_every_current_indexed_shape_executes_one_hinted_find_without_aggregation(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profile_types');
        $collection->deleteMany([]);
        $documents = [];
        foreach (range(1, 400) as $index) {
            $eligible = $index <= 4;
            $boolean = static fn (bool $value): BSONDocument => new BSONDocument([
                'value' => $value,
                'parameters' => new BSONDocument([]),
            ]);
            $documents[] = [
                'type' => sprintf('%s_%03d', $eligible ? 'eligible' : 'closed', $index),
                'capabilities' => new BSONDocument([
                    'is_queryable' => $boolean($eligible),
                    'is_publicly_discoverable' => $boolean($eligible),
                    'is_publicly_navigable' => $boolean($eligible),
                    'has_gallery' => $boolean($eligible),
                    'has_contact_channels' => $boolean($eligible),
                    'is_map_poi_enabled' => $boolean($eligible),
                    'is_physical_host_enabled' => $boolean($eligible),
                    'is_reference_location_enabled' => $boolean($eligible),
                    'location_policy' => new BSONDocument([
                        'value' => $eligible ? 'optional' : 'disabled',
                        'parameters' => new BSONDocument([]),
                    ]),
                ]),
            ];
        }
        $collection->insertMany($documents);

        $scenarios = [
            [['is_queryable' => true], 'idx_account_profile_types_candidate_queryable_v2'],
            [['is_queryable' => true, 'is_publicly_discoverable' => true], 'idx_account_profile_types_public_discovery_v2'],
            [['is_publicly_navigable' => true], 'idx_account_profile_types_public_navigation_v2'],
            [['has_gallery' => true], 'idx_account_profile_types_capability_has_gallery_v1'],
            [['has_contact_channels' => true], 'idx_account_profile_types_candidate_contact_capable_v2'],
            [['is_map_poi_enabled' => true], 'idx_account_profile_types_map_poi_location_policy_v1'],
            [['is_physical_host_enabled' => true], 'idx_account_profile_types_physical_host_location_policy_v1'],
            [['is_reference_location_enabled' => true], 'idx_account_profile_types_reference_location_policy_v1'],
            [['location_policy' => ['optional', 'required']], 'idx_account_profile_types_location_policy_v1'],
            [[
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_map_poi_enabled' => true,
            ], 'idx_account_profile_types_public_map_poi_v1'],
            [[
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_physical_host_enabled' => true,
            ], 'idx_account_profile_types_public_physical_host_v1'],
            [[
                'is_publicly_navigable' => true,
                'is_physical_host_enabled' => true,
            ], 'idx_account_profile_types_navigable_physical_host_v1'],
        ];
        $resolver = app(AccountProfileCapabilityResolverContract::class);

        foreach ($scenarios as [$criteria, $expectedHint]) {
            $resolved = [];
            $trace = $this->captureMongoCommands(function () use ($resolver, $criteria, &$resolved): void {
                $resolved = $resolver->typeIdsWhereAllEffectiveValues($criteria);
            });
            $this->assertCount(4, $resolved, json_encode($criteria, JSON_THROW_ON_ERROR));
            $commands = $trace->commandsForCollection('account_profile_types');
            $this->assertCount(1, $commands, json_encode($criteria, JSON_THROW_ON_ERROR));
            $command = $commands[0];
            $this->assertSame($expectedHint, (string) ($command['hint'] ?? ''));
            $this->assertSame(0, $trace->countForCollection('account_profile_types', 'aggregate'));
            $filter = $this->native($command['filter'] ?? []);
            $explain = $this->native(DB::connection('tenant')->getDatabase()->command([
                'explain' => [
                    'find' => 'account_profile_types',
                    'filter' => $filter,
                    'projection' => ['type' => 1],
                    'sort' => ['type' => 1],
                    'hint' => $expectedHint,
                ],
                'verbosity' => 'executionStats',
            ])->toArray()[0]);

            $this->assertNotContains('COLLSCAN', $this->valuesForKey($explain, 'stage'));
            $this->assertNotSame([], $this->valuesForKey($explain, 'indexName'));
            $this->assertLessThanOrEqual(
                40,
                (int) ($explain['executionStats']['totalDocsExamined'] ?? PHP_INT_MAX),
                json_encode($criteria, JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @param callable():mixed $operation */
    private function captureMongoCommands(callable $operation): MongoCommandTrace
    {
        $client = DB::connection('tenant')->getClient();
        $trace = new MongoCommandTrace;
        $client->addSubscriber($trace);
        try {
            $operation();
        } finally {
            $client->removeSubscriber($trace);
        }

        return $trace;
    }

    private function native(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->native($item);
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private function valuesForKey(mixed $value, string $expectedKey): array
    {
        if (! is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $key => $item) {
            if ($key === $expectedKey) {
                $values[] = $item;
            }
            array_push($values, ...$this->valuesForKey($item, $expectedKey));
        }

        return $values;
    }
}
