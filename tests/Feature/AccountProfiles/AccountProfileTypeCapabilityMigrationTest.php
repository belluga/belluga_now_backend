<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityRepairer;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileTypeCapabilityMigrationTest extends TestCaseTenant
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
        $this->profileTypesCollection()->deleteMany([]);
    }

    public function test_migration_completes_malformed_capabilities_without_rewriting_explicit_booleans(): void
    {
        $collection = $this->profileTypesCollection();
        $collection->insertMany([
            [
                'type' => 'personal',
                'capabilities' => [
                    'is_favoritable' => false,
                    'has_bio' => 'invalid',
                ],
            ],
            [
                'type' => 'custom',
                'capabilities' => [
                    'is_queryable' => false,
                    'is_favoritable' => null,
                    'is_reference_location_enabled' => true,
                ],
            ],
        ]);

        $this->runCapabilityCanonicalizationMigration();

        $catalog = new AccountProfileTypeCapabilityCatalog;
        $personal = $this->capabilitiesFor('personal');
        $custom = $this->capabilitiesFor('custom');

        $this->assertCompleteBooleanMap($catalog, $personal);
        $this->assertCompleteBooleanMap($catalog, $custom);
        $this->assertFalse($personal['is_favoritable']);
        $this->assertFalse($personal['has_bio']);
        $this->assertFalse($personal['is_queryable']);
        $this->assertTrue($personal['is_inviteable']);
        $this->assertFalse($custom['is_queryable']);
        $this->assertFalse($custom['is_favoritable']);
        $this->assertTrue($custom['is_reference_location_enabled']);
        $this->assertFalse($custom['is_poi_enabled']);

        $this->runCapabilityCanonicalizationMigration();

        $this->assertSame($personal, $this->capabilitiesFor('personal'));
        $this->assertSame($custom, $this->capabilitiesFor('custom'));
    }

    public function test_repair_filter_preserves_an_explicit_boolean_written_after_candidate_selection(): void
    {
        $collection = $this->profileTypesCollection();
        $insert = $collection->insertOne([
            'type' => 'custom',
            'capabilities' => [
                'is_favoritable' => null,
            ],
        ]);
        $documentId = $insert->getInsertedId();

        $repairer = new AccountProfileTypeCapabilityRepairer(new AccountProfileTypeCapabilityCatalog);
        $collection->updateOne(
            ['_id' => $documentId],
            ['$set' => ['capabilities.is_favoritable' => false]],
        );

        $result = $collection->updateOne(
            array_merge(
                ['_id' => $documentId],
                $repairer->repairableFieldFilter('is_favoritable'),
            ),
            ['$set' => ['capabilities.is_favoritable' => true]],
        );

        $this->assertSame(0, $result->getMatchedCount());
        $this->assertFalse($this->capabilitiesFor('custom')['is_favoritable']);
    }

    public function test_migration_provisions_catalog_and_semantic_capability_indexes(): void
    {
        $this->runCapabilityCanonicalizationMigration();

        $indexNames = array_map(
            static fn ($index): string => $index->getName(),
            iterator_to_array($this->profileTypesCollection()->listIndexes()),
        );

        foreach ((new AccountProfileTypeCapabilityCatalog)->capabilityIndexDefinitions() as $definition) {
            $this->assertContains($definition['name'], $indexNames);
        }

        foreach (TenantProfileType::capabilityQueryIndexDefinitions() as $definition) {
            $this->assertContains($definition['name'], $indexNames);
        }
    }

    private function runCapabilityCanonicalizationMigration(): void
    {
        $migration = require base_path(
            'database/migrations/tenants/2026_07_18_000100_canonicalize_profile_type_capabilities.php',
        );

        $migration->up();
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $service->initialize(new InitializationPayload(
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

    /**
     * @return \MongoDB\Collection<array<string, mixed>>
     */
    private function profileTypesCollection(): \MongoDB\Collection
    {
        return DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilitiesFor(string $type): array
    {
        $document = $this->profileTypesCollection()->findOne(['type' => $type]);
        $this->assertNotNull($document);

        return $this->arrayFrom($document['capabilities'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function assertCompleteBooleanMap(
        AccountProfileTypeCapabilityCatalog $catalog,
        array $capabilities,
    ): void {
        foreach ($catalog->definitions() as $definition) {
            $key = $definition['key'];
            $this->assertArrayHasKey($key, $capabilities);
            $this->assertIsBool($capabilities[$key]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
}
