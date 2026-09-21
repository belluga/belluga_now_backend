<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class AccountProfileCapabilityBsonPersistenceTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Capability BSON', 'subdomain' => 'capability-bson'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'capability-bson@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['capability-bson.test'],
            ));
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_definitions_and_type_values_are_native_bson_documents_and_lists(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $types = $database->selectCollection('account_profile_types');
        $gallery = $database->selectCollection('account_profile_capability_definitions')->findOne(['key' => 'has_gallery']);
        self::assertInstanceOf(BSONDocument::class, $gallery);
        self::assertInstanceOf(BSONArray::class, $gallery['parameters']);
        self::assertInstanceOf(BSONDocument::class, $gallery['parameters'][0]);
        self::assertInstanceOf(BSONArray::class, $gallery['parameters'][0]['validations']);
        self::assertInstanceOf(BSONDocument::class, $gallery['resources']);
        self::assertInstanceOf(BSONDocument::class, $gallery['resources']['gallery_groups']);
        self::assertInstanceOf(BSONArray::class, $gallery['resources']['gallery_groups']['operations']);
        self::assertInstanceOf(BSONDocument::class, $gallery['resources']['gallery_groups']['operations'][0]);

        $avatar = $database->selectCollection('account_profile_capability_definitions')->findOne(['key' => 'has_avatar']);
        self::assertInstanceOf(BSONDocument::class, $avatar);
        self::assertInstanceOf(BSONArray::class, $avatar['parameters']);
        self::assertInstanceOf(BSONDocument::class, $avatar['resources']);

        $profileType = $types->findOne(['type' => 'personal']);
        self::assertInstanceOf(BSONDocument::class, $profileType);
        self::assertInstanceOf(BSONDocument::class, $profileType['capabilities']);
        self::assertInstanceOf(BSONDocument::class, $profileType['capabilities']['has_gallery']);
        self::assertIsBool($profileType['capabilities']['has_gallery']['value']);
        self::assertInstanceOf(BSONDocument::class, $profileType['capabilities']['has_gallery']['parameters']);
        self::assertIsInt($profileType['capabilities']['has_gallery']['parameters']['max_groups']);
        self::assertInstanceOf(BSONDocument::class, $profileType['capabilities']['has_avatar']['parameters']);
        self::assertFalse(is_string($profileType['capabilities']));
        self::assertFalse(is_string($gallery['resources']));
    }

    public function test_initialized_tenant_definitions_match_the_canonical_contract(): void
    {
        $definitions = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_capability_definitions');
        self::assertSame(18, $definitions->countDocuments());
        $persisted = [];
        foreach ($definitions->find([], ['sort' => ['key' => 1]]) as $document) {
            if (isset($document['dependencies'])) {
                self::assertInstanceOf(BSONArray::class, $document['dependencies']);
                foreach ($document['dependencies'] as $dependency) {
                    self::assertInstanceOf(BSONDocument::class, $dependency);
                    self::assertInstanceOf(BSONArray::class, $dependency['accepted_values']);
                }
            }
            unset($document['_id']);
            $definition = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $persisted[$definition['key']] = $definition;
        }

        self::assertSame(app(AccountProfileCapabilityRegistry::class)->definitions(), $persisted);
    }
}
