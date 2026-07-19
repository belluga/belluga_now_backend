<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileCandidateDiscoveryMigrationTest extends TestCaseTenant
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

    public function test_candidate_migration_backfills_search_keys_and_canonicalizes_legacy_contact_modes_idempotently(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profiles');
        $id = new ObjectId;
        $collection->insertOne([
            '_id' => $id,
            'display_name' => '  Xápuri   Cultural  ',
            'contact_mode' => 'legacy-unknown-mode',
            'is_active' => true,
        ]);

        $migration = $this->candidateMigration();
        $migration->up();
        $migration->up();

        $profile = $this->documentToArray($collection->findOne(['_id' => $id]));
        $this->assertSame('xapuri cultural', $profile['name_search_key'] ?? null);
        $this->assertSame('own', $profile['contact_mode'] ?? null);
    }

    public function test_candidate_migration_provisions_the_declared_direct_query_indexes(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $profileIndexes = $this->indexMap($database->selectCollection('account_profiles'));
        $typeIndexes = $this->indexMap($database->selectCollection('account_profile_types'));

        $this->assertSame(
            ['capabilities.is_queryable' => 1, 'type' => 1],
            $typeIndexes['idx_account_profile_types_candidate_queryable_v1']->getKey(),
        );
        $this->assertSame(
            ['capabilities.has_contact_channels' => 1, 'type' => 1],
            $typeIndexes['idx_account_profile_types_candidate_contact_capable_v1']->getKey(),
        );
        $this->assertSame(
            ['profile_type' => 1, 'name_search_key' => 1, '_id' => 1],
            $profileIndexes['idx_account_profiles_candidate_queryable_name_v1']->getKey(),
        );
        $this->assertSame(
            ['profile_type' => 1, 'name_search_key' => 1, '_id' => 1],
            $profileIndexes['idx_account_profiles_candidate_contact_name_v1']->getKey(),
        );
        $this->assertSame(
            ['is_active' => true, 'deleted_at' => null],
            $this->documentToArray($profileIndexes['idx_account_profiles_candidate_queryable_name_v1']['partialFilterExpression']),
        );
        $this->assertSame(
            ['is_active' => true, 'deleted_at' => null, 'contact_mode' => 'own'],
            $this->documentToArray($profileIndexes['idx_account_profiles_candidate_contact_name_v1']['partialFilterExpression']),
        );
    }

    private function candidateMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/tenants/2026_07_19_000100_add_account_profile_candidate_discovery_indexes.php');

        return $migration;
    }

    /**
     * @return array<string, \MongoDB\Model\IndexInfo>
     */
    private function indexMap(\MongoDB\Collection $collection): array
    {
        $indexes = [];
        foreach ($collection->listIndexes() as $index) {
            $indexes[$index->getName()] = $index;
        }

        return $indexes;
    }

    /** @return array<string, mixed> */
    private function documentToArray(mixed $document): array
    {
        if (is_array($document)) {
            return $document;
        }

        if ($document instanceof BSONDocument) {
            return $document->getArrayCopy();
        }

        return [];
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
