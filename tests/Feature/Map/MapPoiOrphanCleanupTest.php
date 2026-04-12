<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\StaticAsset;
use Belluga\Events\Models\Tenants\Event;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Jobs\CleanupOrphanedMapPoisJob;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class MapPoiOrphanCleanupTest extends TestCaseTenant
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

        MapPoi::query()->delete();
        AccountProfile::withTrashed()->forceDelete();
        StaticAsset::withTrashed()->forceDelete();
        Event::withTrashed()->forceDelete();
        Account::withTrashed()->forceDelete();
    }

    public function test_cleanup_orphaned_map_pois_job_deletes_soft_deleted_account_profile_projections(): void
    {
        $liveAccount = Account::create([
            'name' => 'Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);
        $deletedAccount = Account::create([
            'name' => 'Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);

        $liveProfile = AccountProfile::create([
            'account_id' => (string) $liveAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Live Artist',
            'is_active' => true,
        ]);
        $deletedProfile = AccountProfile::create([
            'account_id' => (string) $deletedAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Deleted Artist',
            'is_active' => true,
        ]);

        $livePoi = $this->createMapPoi('account_profile', (string) $liveProfile->_id, 'Live Artist');
        $deletedPoi = $this->createMapPoi('account_profile', (string) $deletedProfile->_id, 'Deleted Artist');

        $deletedProfile->delete();

        app()->call([new CleanupOrphanedMapPoisJob(['account_profile']), 'handle']);

        $this->assertTrue(MapPoi::query()->where('_id', $livePoi->_id)->exists());
        $this->assertFalse(MapPoi::query()->where('_id', $deletedPoi->_id)->exists());
    }

    public function test_cleanup_orphaned_map_pois_job_deletes_soft_deleted_static_asset_projections(): void
    {
        $liveAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Live Asset',
            'is_active' => true,
        ]);
        $deletedAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Deleted Asset',
            'is_active' => true,
        ]);

        $livePoi = $this->createMapPoi('static', (string) $liveAsset->_id, 'Live Asset');
        $deletedPoi = $this->createMapPoi('static', (string) $deletedAsset->_id, 'Deleted Asset');

        $deletedAsset->delete();

        app()->call([new CleanupOrphanedMapPoisJob(['static']), 'handle']);

        $this->assertTrue(MapPoi::query()->where('_id', $livePoi->_id)->exists());
        $this->assertFalse(MapPoi::query()->where('_id', $deletedPoi->_id)->exists());
    }

    public function test_cleanup_orphaned_map_pois_job_batches_deleted_static_asset_projections_past_threshold(): void
    {
        $liveAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Batch Live Asset',
            'is_active' => true,
        ]);
        $livePoi = $this->createMapPoi('static', (string) $liveAsset->_id, 'Batch Live Asset');
        $projectionSpy = new class($this->app->make(MapPoiRegistryContract::class), $this->app->make(MapPoiSourceReaderContract::class), $this->app->make(MapPoiSettingsContract::class)) extends MapPoiProjectionService
        {
            /**
             * @var array<int, array{ref_type: string, ref_ids: array<int, string>}>
             */
            public array $deleteByRefsCalls = [];

            public function __construct(
                MapPoiRegistryContract $registry,
                MapPoiSourceReaderContract $sourceReader,
                MapPoiSettingsContract $settings,
            ) {
                parent::__construct($registry, $sourceReader, $settings);
            }

            /**
             * @param  array<int, string>  $refIds
             */
            public function deleteByRefs(string $refType, array $refIds): void
            {
                $this->deleteByRefsCalls[] = [
                    'ref_type' => $refType,
                    'ref_ids' => array_values($refIds),
                ];

                parent::deleteByRefs($refType, $refIds);
            }
        };
        $this->app->instance(MapPoiProjectionService::class, $projectionSpy);

        foreach (range(1, 205) as $index) {
            $deletedAsset = StaticAsset::create([
                'profile_type' => 'poi',
                'display_name' => sprintf('Batch Deleted Asset %03d', $index),
                'is_active' => true,
            ]);

            $this->createMapPoi('static', (string) $deletedAsset->_id, sprintf('Batch Deleted Asset %03d', $index));
            $deletedAsset->delete();
        }

        app()->call([new CleanupOrphanedMapPoisJob(['static']), 'handle']);

        $this->assertSame(
            [200, 5],
            array_map(
                static fn (array $call): int => count($call['ref_ids']),
                $projectionSpy->deleteByRefsCalls
            )
        );
        $this->assertSame(
            ['static', 'static'],
            array_column($projectionSpy->deleteByRefsCalls, 'ref_type')
        );
        $this->assertTrue(MapPoi::query()->where('_id', $livePoi->_id)->exists());
        $this->assertSame(
            1,
            MapPoi::query()
                ->where('ref_type', 'static')
                ->count()
        );
    }

    public function test_cleanup_orphaned_map_pois_job_deletes_force_deleted_static_asset_projections(): void
    {
        $liveAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Force Live Asset',
            'is_active' => true,
        ]);
        $deletedAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Force Deleted Asset',
            'is_active' => true,
        ]);

        $livePoi = $this->createMapPoi('static', (string) $liveAsset->_id, 'Force Live Asset');
        $deletedPoi = $this->createMapPoi('static', (string) $deletedAsset->_id, 'Force Deleted Asset');

        $deletedAsset->forceDelete();

        app()->call([new CleanupOrphanedMapPoisJob(['static']), 'handle']);

        $this->assertTrue(MapPoi::query()->where('_id', $livePoi->_id)->exists());
        $this->assertFalse(MapPoi::query()->where('_id', $deletedPoi->_id)->exists());
    }

    public function test_cleanup_orphaned_map_pois_job_honors_deleted_since_cutoff(): void
    {
        $recentDeletedAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Recent Deleted Asset',
            'is_active' => true,
        ]);
        $oldDeletedAsset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Old Deleted Asset',
            'is_active' => true,
        ]);

        $recentDeletedPoi = $this->createMapPoi('static', (string) $recentDeletedAsset->_id, 'Recent Deleted Asset');
        $oldDeletedPoi = $this->createMapPoi('static', (string) $oldDeletedAsset->_id, 'Old Deleted Asset');

        $recentDeletedAsset->delete();
        $oldDeletedAsset->delete();
        $oldDeletedAsset->forceFill([
            'deleted_at' => Carbon::now()->subHours(2),
        ]);
        $oldDeletedAsset->save();

        app()->call([new CleanupOrphanedMapPoisJob(['static'], 60), 'handle']);

        $this->assertFalse(MapPoi::query()->where('_id', $recentDeletedPoi->_id)->exists());
        $this->assertTrue(MapPoi::query()->where('_id', $oldDeletedPoi->_id)->exists());
    }

    public function test_cleanup_orphaned_map_pois_job_honors_deleted_since_cutoff_for_account_profiles(): void
    {
        $recentAccount = Account::create([
            'name' => 'Recent Deleted Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);
        $oldAccount = Account::create([
            'name' => 'Old Deleted Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);
        $recentDeletedProfile = AccountProfile::create([
            'account_id' => (string) $recentAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Recent Deleted Profile',
            'is_active' => true,
        ]);
        $oldDeletedProfile = AccountProfile::create([
            'account_id' => (string) $oldAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Old Deleted Profile',
            'is_active' => true,
        ]);

        $recentDeletedPoi = $this->createMapPoi('account_profile', (string) $recentDeletedProfile->_id, 'Recent Deleted Profile');
        $oldDeletedPoi = $this->createMapPoi('account_profile', (string) $oldDeletedProfile->_id, 'Old Deleted Profile');

        $recentDeletedProfile->delete();
        $oldDeletedProfile->delete();
        $oldDeletedProfile->forceFill([
            'deleted_at' => Carbon::now()->subHours(2),
        ]);
        $oldDeletedProfile->save();

        app()->call([new CleanupOrphanedMapPoisJob(['account_profile'], 60), 'handle']);

        $this->assertFalse(MapPoi::query()->where('_id', $recentDeletedPoi->_id)->exists());
        $this->assertTrue(MapPoi::query()->where('_id', $oldDeletedPoi->_id)->exists());
    }

    public function test_cleanup_orphaned_map_pois_job_deletes_soft_deleted_event_projections(): void
    {
        $liveEvent = Event::create([
            'title' => 'Live Event',
        ]);
        $deletedEvent = Event::create([
            'title' => 'Deleted Event',
        ]);

        $livePoi = $this->createMapPoi('event', (string) $liveEvent->_id, 'Live Event');
        $deletedPoi = $this->createMapPoi('event', (string) $deletedEvent->_id, 'Deleted Event');

        $deletedEvent->delete();

        app()->call([new CleanupOrphanedMapPoisJob(['event']), 'handle']);

        $this->assertTrue(MapPoi::query()->where('_id', $livePoi->_id)->exists());
        $this->assertFalse(MapPoi::query()->where('_id', $deletedPoi->_id)->exists());
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
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
            tenantDomains: ['tenant-zeta.test'],
        );

        $service->initialize($payload);
    }

    private function createMapPoi(string $refType, string $refId, string $name): MapPoi
    {
        return MapPoi::query()->create([
            'ref_type' => $refType,
            'ref_id' => $refId,
            'name' => $name,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);
    }
}
