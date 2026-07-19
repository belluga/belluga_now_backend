<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileRegistryDefaultUpserter;
use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\AccountProfiles\AccountProfileRegistrySyncIndexPrecondition;
use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\UpdateResult;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileRegistrySyncCommandTest extends TestCaseTenant
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
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreCompatibleTypeIndex();
        } finally {
            Tenant::forgetCurrent();
        }

        parent::tearDown();
    }

    public function test_sync_preserves_a_custom_referenced_profile_type(): void
    {
        $profile = $this->createCustomReferencedProfile();

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $this->assertTrue(TenantProfileType::query()->where('type', 'custom-referenced')->exists());
        $this->assertSame(
            'custom-referenced',
            (string) (AccountProfile::query()->findOrFail($profile->getKey())->profile_type ?? ''),
        );
    }

    public function test_sync_help_describes_additive_repair(): void
    {
        $command = Artisan::all()['tenant:profile-registry:sync-v1'];

        $this->assertStringContainsString('additive', strtolower((string) $command->getDescription()));
        $this->assertStringNotContainsString('overwrite', strtolower((string) $command->getDescription()));
    }

    public function test_sync_clears_tenant_context_when_repair_fails(): void
    {
        app(AccountProfileRegistrySyncIndexPrecondition::class)->assertCompatibleTypeIndex();

        $failingSeeder = new class extends AccountProfileRegistrySeeder
        {
            public bool $called = false;

            public function ensureDefaults(): void
            {
                $this->called = true;

                throw new RuntimeException('forced registry repair failure');
            }
        };
        app()->instance(AccountProfileRegistrySeeder::class, $failingSeeder);

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        try {
            $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
                'tenant_slug' => $tenantSlug,
            ]);

            $this->assertSame(1, $exitCode, Artisan::output());
            $this->assertTrue($failingSeeder->called);
            $this->assertNull(Tenant::current());
        } finally {
            app()->forgetInstance(AccountProfileRegistrySeeder::class);
        }
    }

    #[DataProvider('incompatibleTypeIndexOptions')]
    public function test_sync_rejects_an_incompatible_type_index_before_mutating_registry_data(
        string $indexName,
        array $options,
    ): void {
        $profile = $this->createCustomReferencedProfile();
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        $collection->dropIndex('type_1');
        $collection->createIndex(
            ['type' => 1],
            array_merge(['name' => $indexName, 'unique' => true], $options),
        );

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $this->assertTrue(TenantProfileType::query()->where('type', 'custom-referenced')->exists());
        $this->assertSame(
            'custom-referenced',
            (string) (AccountProfile::query()->findOrFail($profile->getKey())->profile_type ?? ''),
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function incompatibleTypeIndexOptions(): array
    {
        return [
            'partial' => [
                'registry_type_partial',
                ['partialFilterExpression' => ['type' => ['$exists' => true]]],
            ],
            'sparse' => [
                'registry_type_sparse',
                ['sparse' => true],
            ],
            'custom collation' => [
                'registry_type_collated',
                ['collation' => ['locale' => 'en', 'strength' => 2]],
            ],
        ];
    }

    public function test_sync_accepts_a_compatible_type_index_with_a_different_name(): void
    {
        $profile = $this->createCustomReferencedProfile();
        $collection = $this->profileTypesCollection();
        $collection->dropIndex('type_1');
        $collection->createIndex(['type' => 1], [
            'name' => 'tenant_registry_type_unique',
            'unique' => true,
            'collation' => ['locale' => 'simple'],
        ]);

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $this->assertTrue(TenantProfileType::query()->where('type', 'custom-referenced')->exists());
        $this->assertSame(
            'custom-referenced',
            (string) (AccountProfile::query()->findOrFail($profile->getKey())->profile_type ?? ''),
        );
    }

    public function test_sync_rejects_a_missing_type_index_before_mutating_registry_data(): void
    {
        $profile = $this->createCustomReferencedProfile();
        $this->profileTypesCollection()->dropIndex('type_1');

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $this->assertTrue(TenantProfileType::query()->where('type', 'custom-referenced')->exists());
        $this->assertSame(
            'custom-referenced',
            (string) (AccountProfile::query()->findOrFail($profile->getKey())->profile_type ?? ''),
        );
    }

    public function test_sync_inserts_defaults_with_timestamps_and_invalidates_a_warmed_type_set(): void
    {
        TenantProfileType::query()->delete();
        $provider = new AccountProfileTypeSetProvider;

        $this->assertSame([], $provider->queryableTypes());

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();

        $artist = TenantProfileType::query()->where('type', 'artist')->firstOrFail();
        $this->assertNotNull($artist->created_at);
        $this->assertNotNull($artist->updated_at);
        $this->assertContains('artist', $provider->queryableTypes());
    }

    public function test_sync_is_idempotent_after_the_first_additive_repair(): void
    {
        TenantProfileType::query()->delete();
        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $firstExitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);
        $this->assertSame(0, $firstExitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $firstCreatedAt = $this->timestampForType('artist', 'created_at');
        $firstUpdatedAt = $this->timestampForType('artist', 'updated_at');

        Tenant::forgetCurrent();
        $secondExitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(0, $secondExitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $this->assertSame(1, TenantProfileType::query()->where('type', 'artist')->count());
        $this->assertSame($firstCreatedAt, $this->timestampForType('artist', 'created_at'));
        $this->assertSame($firstUpdatedAt, $this->timestampForType('artist', 'updated_at'));
    }

    public function test_expected_duplicate_loser_converges_and_invalidates_a_warmed_type_set(): void
    {
        TenantProfileType::query()->delete();
        $defaults = collect((new AccountProfileRegistrySeeder)->defaults())->keyBy('type');
        $now = new UTCDateTime((int) (microtime(true) * 1000));
        $collection = $this->profileTypesCollection();

        foreach (['personal', 'venue'] as $type) {
            $entry = $defaults->get($type);
            $this->assertIsArray($entry);
            $collection->insertOne(array_merge($entry, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $provider = new AccountProfileTypeSetProvider;
        $this->assertNotContains('artist', $provider->queryableTypes());

        $upserter = new class extends AccountProfileRegistryDefaultUpserter
        {
            protected function performUpsert(
                Collection $collection,
                string $type,
                array $entry,
                UTCDateTime $now,
            ): UpdateResult {
                if ($type !== 'artist') {
                    return parent::performUpsert($collection, $type, $entry, $now);
                }

                $collection->insertOne(array_merge($entry, [
                    'type' => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                throw new BulkWriteException(
                    'E11000 duplicate key error collection: tenant.account_profile_types index: arbitrary_name dup key: { type: "artist" }',
                    11000,
                );
            }
        };

        (new AccountProfileRegistrySeeder(defaultUpserter: $upserter))->ensureDefaults();

        $this->assertTrue(TenantProfileType::query()->where('type', 'artist')->exists());
        $this->assertContains('artist', $provider->queryableTypes());
    }

    public function test_duplicate_key_classifier_rejects_unrelated_or_wrong_type_collisions(): void
    {
        $upserter = new class extends AccountProfileRegistryDefaultUpserter
        {
            public function matches(BulkWriteException $exception, string $type): bool
            {
                return $this->isExpectedTypeDuplicate($exception, $type);
            }
        };

        $this->assertTrue($upserter->matches(new BulkWriteException(
            'E11000 duplicate key error collection: tenant.account_profile_types index: any_name dup key: { type: "artist" }',
            11000,
        ), 'artist'));
        $this->assertFalse($upserter->matches(new BulkWriteException(
            'E11000 duplicate key error collection: tenant.account_profile_types index: slug_1 dup key: { slug: "artist" }',
            11000,
        ), 'artist'));
        $this->assertFalse($upserter->matches(new BulkWriteException(
            'E11000 duplicate key error collection: tenant.account_profile_types index: any_name dup key: { type: "venue" }',
            11000,
        ), 'artist'));
    }

    public function test_unrelated_duplicate_key_error_is_rethrown_without_convergence(): void
    {
        TenantProfileType::query()->delete();
        $entry = collect((new AccountProfileRegistrySeeder)->defaults())->firstWhere('type', 'artist');
        $this->assertIsArray($entry);

        $upserter = new class extends AccountProfileRegistryDefaultUpserter
        {
            protected function performUpsert(
                Collection $collection,
                string $type,
                array $entry,
                UTCDateTime $now,
            ): UpdateResult {
                throw new BulkWriteException(
                    'E11000 duplicate key error collection: tenant.account_profile_types index: slug_1 dup key: { slug: "artist" }',
                    11000,
                );
            }
        };

        $this->expectException(BulkWriteException::class);
        $upserter->ensureDefault($entry, new UTCDateTime((int) (microtime(true) * 1000)));
    }

    public function test_expected_duplicate_without_a_converged_type_is_rethrown(): void
    {
        TenantProfileType::query()->delete();
        $entry = collect((new AccountProfileRegistrySeeder)->defaults())->firstWhere('type', 'artist');
        $this->assertIsArray($entry);

        $upserter = new class extends AccountProfileRegistryDefaultUpserter
        {
            protected function performUpsert(
                Collection $collection,
                string $type,
                array $entry,
                UTCDateTime $now,
            ): UpdateResult {
                throw new BulkWriteException(
                    'E11000 duplicate key error collection: tenant.account_profile_types index: type_1 dup key: { type: "artist" }',
                    11000,
                );
            }
        };

        $this->expectException(BulkWriteException::class);
        $upserter->ensureDefault($entry, new UTCDateTime((int) (microtime(true) * 1000)));
    }

    public function test_sync_repairs_missing_default_capabilities_without_overwriting_tenant_owned_fields(): void
    {
        TenantProfileType::query()->delete();
        $this->profileTypesCollection()->insertOne([
            'type' => 'artist',
            'label' => 'Tenant Artist Label',
            'allowed_taxonomies' => ['genre'],
            'visual' => ['mode' => 'icon', 'icon' => 'tenant_owned'],
            'tenant_extension' => ['source' => 'manual'],
            'capabilities' => [
                'is_favoritable' => false,
                'tenant_extension' => 'preserve',
            ],
        ]);

        $tenantSlug = (string) Tenant::current()?->slug;
        Tenant::forgetCurrent();

        $exitCode = Artisan::call('tenant:profile-registry:sync-v1', [
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->makePrimaryTenantCurrent();
        $artist = $this->profileTypesCollection()->findOne(['type' => 'artist']);
        $this->assertNotNull($artist);
        $this->assertSame('Tenant Artist Label', $artist['label']);
        $this->assertSame(['genre'], iterator_to_array($artist['allowed_taxonomies']));
        $this->assertSame(['mode' => 'icon', 'icon' => 'tenant_owned'], iterator_to_array($artist['visual']));
        $this->assertSame(['source' => 'manual'], iterator_to_array($artist['tenant_extension']));
        $this->assertFalse($artist['capabilities']['is_favoritable']);
        $this->assertSame('preserve', $artist['capabilities']['tenant_extension']);
        $this->assertArrayHasKey('has_gallery', $artist['capabilities']);
    }

    #[DataProvider('syncConcurrencyLevels')]
    public function test_real_synchronized_sync_processes_converge_without_duplicate_defaults(
        int $concurrency,
        int $batches,
    ): void {
        $tenantSlug = (string) Tenant::current()?->slug;

        for ($batch = 1; $batch <= $batches; $batch++) {
            TenantProfileType::query()->delete();
            $barrier = sys_get_temp_dir().'/registry-sync-barrier-'.bin2hex(random_bytes(8));

            try {
                $processes = array_map(
                    fn (): Process => $this->synchronizedSyncProcess($tenantSlug, $barrier, $concurrency),
                    range(1, $concurrency),
                );

                foreach ($processes as $process) {
                    $process->start();
                }

                $results = [];
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                    $results[] = $this->lastJsonLine($process);
                }

                $this->assertSame(
                    array_fill(0, $concurrency, 0),
                    array_column($results, 'exit_code'),
                    "batch {$batch}: ".json_encode($results, JSON_THROW_ON_ERROR),
                );
                $this->makePrimaryTenantCurrent();
                foreach (['personal', 'artist', 'venue'] as $type) {
                    $this->assertSame(1, TenantProfileType::query()->where('type', $type)->count());
                }
            } finally {
                foreach (glob($barrier.'.ready.*') ?: [] as $path) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function syncConcurrencyLevels(): array
    {
        return [
            'five concurrent commands across two batches' => [5, 2],
            'ten concurrent commands across three batches' => [10, 3],
            'twenty concurrent commands across five batches' => [20, 5],
        ];
    }

    private function createCustomReferencedProfile(): AccountProfile
    {
        TenantProfileType::query()->delete();
        AccountProfile::withTrashed()->forceDelete();

        TenantProfileType::create([
            'type' => 'custom-referenced',
            'label' => 'Custom Referenced',
            'allowed_taxonomies' => [],
            'capabilities' => [],
        ]);

        return AccountProfile::create([
            'account_id' => 'registry-sync-custom-reference',
            'profile_type' => 'custom-referenced',
            'display_name' => 'Registry Sync Custom Reference',
            'is_active' => true,
        ]);
    }

    private function restoreCompatibleTypeIndex(): void
    {
        $tenant = Tenant::query()->first();
        if (! $tenant instanceof Tenant) {
            return;
        }

        $tenant->makeCurrent();
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        foreach ($collection->listIndexes() as $index) {
            if ($index->getKey() === ['type' => 1]) {
                $collection->dropIndex($index->getName());
            }
        }

        $collection->createIndex(['type' => 1], [
            'name' => 'type_1',
            'unique' => true,
        ]);
    }

    private function makePrimaryTenantCurrent(): void
    {
        Tenant::query()->firstOrFail()->makeCurrent();
    }

    private function profileTypesCollection(): Collection
    {
        return DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');
    }

    private function timestampForType(string $type, string $field): string
    {
        $document = $this->profileTypesCollection()->findOne(['type' => $type]);
        $this->assertNotNull($document);
        $timestamp = $document[$field] ?? null;
        $this->assertInstanceOf(UTCDateTime::class, $timestamp);

        return $timestamp->toDateTime()->format('U.u');
    }

    private function synchronizedSyncProcess(string $tenantSlug, string $barrier, int $concurrency): Process
    {
        $tenantSlugValue = var_export($tenantSlug, true);
        $barrierValue = var_export($barrier, true);
        $concurrencyValue = var_export($concurrency, true);
        $code = <<<PHP
try {
    \$barrier = {$barrierValue};
    \$concurrency = {$concurrencyValue};
    file_put_contents(\$barrier.'.ready.'.getmypid(), 'ready');
    \$deadline = microtime(true) + 10;
    while (count(glob(\$barrier.'.ready.*')) < \$concurrency) {
        if (microtime(true) >= \$deadline) {
            throw new \\RuntimeException('registry sync barrier timed out');
        }
        usleep(10_000);
    }
    \$exitCode = \\Illuminate\\Support\\Facades\\Artisan::call(
        'tenant:profile-registry:sync-v1',
        ['tenant_slug' => {$tenantSlugValue}],
    );
    echo json_encode([
        'exit_code' => \$exitCode,
        'command_output' => \\Illuminate\\Support\\Facades\\Artisan::output(),
    ], JSON_THROW_ON_ERROR);
} catch (\\Throwable \$exception) {
    echo json_encode([
        'exit_code' => 1,
        'exception' => \$exception::class,
        'message' => \$exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), null, null, 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastJsonLine(Process $process): array
    {
        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split('/\\R+/', $process->getOutput()) ?: [],
        )));
        $jsonLine = end($lines);
        $this->assertIsString($jsonLine, $process->getOutput());

        return json_decode($jsonLine, true, flags: JSON_THROW_ON_ERROR);
    }

    private function initializeSystem(): void
    {
        app(SystemInitializationService::class)->initialize(new InitializationPayload(
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
        ));
    }
}
