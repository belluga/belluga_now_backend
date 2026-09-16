<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\Landlord\Tenant;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class MapPoiRebuildCommandTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
        Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
            'name' => 'Map rebuild',
            'slug' => 'map-rebuild',
            'subdomain' => 'map-rebuild',
            'database' => Tenant::tenantDatabasePrefix().'map-rebuild',
            'app_domains' => ['map-rebuild.test'],
        ]))->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_rebuild_rejects_every_retired_static_source_token_before_purge(): void
    {
        $this->bindRebuildCollaborators();
        $this->seedProjection('event', 'event-old');
        $this->seedProjection('account_profile', 'profile-old');

        foreach (['static_assets', 'static', 'assets'] as $source) {
            $this->artisan("map-pois:rebuild {$source}")
                ->expectsOutputToContain('Invalid source')
                ->assertExitCode(2);
        }

        self::assertSame(1, MapPoi::query()->where('ref_type', 'event')->count());
        self::assertSame(1, MapPoi::query()->where('ref_type', 'account_profile')->count());
    }

    public function test_events_rebuild_purges_and_processes_only_events(): void
    {
        $event = (object) ['id' => 'event-new'];
        $reader = $this->bindRebuildCollaborators();
        $reader->shouldReceive('allEventIds')->once()->andReturn(['event-new']);
        $reader->shouldReceive('findEventById')->once()->with('event-new')->andReturn($event);
        $reader->shouldNotReceive('allAccountProfileIds');
        $this->projectionService()->shouldReceive('upsertFromEvent')->once()->with($event);
        $this->seedProjection('event', 'event-old');
        $this->seedProjection('account_profile', 'profile-old');

        $this->artisan('map-pois:rebuild events')
            ->expectsOutputToContain('Map rebuild completed. processed=1 upserted=1')
            ->assertExitCode(0);

        self::assertSame(0, MapPoi::query()->where('ref_type', 'event')->count());
        self::assertSame(1, MapPoi::query()->where('ref_type', 'account_profile')->count());
    }

    public function test_account_profiles_rebuild_purges_and_processes_only_account_profiles(): void
    {
        $profile = (object) ['id' => 'profile-new'];
        $reader = $this->bindRebuildCollaborators();
        $reader->shouldReceive('allAccountProfileIds')->once()->andReturn(['profile-new']);
        $reader->shouldReceive('findAccountProfileById')->once()->with('profile-new')->andReturn($profile);
        $reader->shouldNotReceive('allEventIds');
        $this->projectionService()->shouldReceive('upsertFromAccountProfile')->once()->with($profile);
        $this->seedProjection('event', 'event-old');
        $this->seedProjection('account_profile', 'profile-old');

        $this->artisan('map-pois:rebuild account_profiles')
            ->expectsOutputToContain('Map rebuild completed. processed=1 upserted=1')
            ->assertExitCode(0);

        self::assertSame(1, MapPoi::query()->where('ref_type', 'event')->count());
        self::assertSame(0, MapPoi::query()->where('ref_type', 'account_profile')->count());
    }

    public function test_all_rebuild_processes_exactly_the_two_surviving_source_families(): void
    {
        $event = (object) ['id' => 'event-new'];
        $profile = (object) ['id' => 'profile-new'];
        $reader = $this->bindRebuildCollaborators();
        $reader->shouldReceive('allEventIds')->once()->andReturn(['event-new']);
        $reader->shouldReceive('findEventById')->once()->with('event-new')->andReturn($event);
        $reader->shouldReceive('allAccountProfileIds')->once()->andReturn(['profile-new']);
        $reader->shouldReceive('findAccountProfileById')->once()->with('profile-new')->andReturn($profile);
        $projection = $this->projectionService();
        $projection->shouldReceive('upsertFromEvent')->once()->with($event);
        $projection->shouldReceive('upsertFromAccountProfile')->once()->with($profile);
        $this->seedProjection('event', 'event-old');
        $this->seedProjection('account_profile', 'profile-old');

        $this->artisan('map-pois:rebuild all')
            ->expectsOutputToContain('Rebuilding events...')
            ->expectsOutputToContain('Rebuilding account profiles...')
            ->expectsOutputToContain('Map rebuild completed. processed=2 upserted=2')
            ->assertExitCode(0);

        self::assertSame(0, MapPoi::query()->whereIn('ref_type', ['event', 'account_profile'])->count());
    }

    private function bindRebuildCollaborators(): MapPoiSourceReaderContract&MockInterface
    {
        $settings = Mockery::mock(MapPoiSettingsContract::class);
        $settings->shouldReceive('resolveMapIngestSettings')->andReturn([
            'rebuild' => ['enabled' => true, 'batch_size' => 20],
        ]);
        $reader = Mockery::mock(MapPoiSourceReaderContract::class);
        $projection = Mockery::mock(MapPoiProjectionService::class);
        $this->app->instance(MapPoiSettingsContract::class, $settings);
        $this->app->instance(MapPoiSourceReaderContract::class, $reader);
        $this->app->instance(MapPoiProjectionService::class, $projection);

        return $reader;
    }

    private function projectionService(): MapPoiProjectionService&MockInterface
    {
        /** @var MapPoiProjectionService&MockInterface $projection */
        $projection = $this->app->make(MapPoiProjectionService::class);

        return $projection;
    }

    private function seedProjection(string $refType, string $refId): void
    {
        DB::connection('tenant')->getDatabase()->selectCollection((new MapPoi)->getTable())->insertOne([
            'ref_type' => $refType,
            'ref_id' => $refId,
            'projection_key' => "{$refType}:{$refId}",
        ]);
    }
}
