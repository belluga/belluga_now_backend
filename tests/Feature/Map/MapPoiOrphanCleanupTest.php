<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\Event;
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
