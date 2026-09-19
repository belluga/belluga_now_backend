<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\AccountProfiles\AccountProfileOutboxPublisher;
use App\Application\AccountProfiles\AccountProfileRegistryManagementService;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Application\Accounts\AccountManagementService;
use App\Jobs\AccountProfiles\DispatchAccountProfileOutboxEventJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Multitenancy\Jobs\TenantAware;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MapPoiAccountProfileProjectionTest extends TestCase
{
    private const BARRIER_TIMEOUT_SECONDS = 30;

    private const PROCESS_TIMEOUT_SECONDS = 60;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::withoutEvents(fn (): Tenant => Tenant::query()->firstOrCreate(
            ['slug' => 'map-capability-projection'],
            [
                'name' => 'Map capability projection',
                'subdomain' => 'map-capability-projection',
                'database' => Tenant::tenantDatabasePrefix().'map-capability-projection',
                'app_domains' => ['map-capability-projection.test'],
            ],
        ))->makeCurrent();
        foreach (['account_profile_types', 'account_profiles', 'accounts', 'account_profile_outbox', 'account_profile_projection_checkpoints', 'map_pois'] as $collection) {
            DB::connection('tenant')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_type_capability_transition_removes_stale_rows_and_persists_reconciliation_generation(): void
    {
        $type = $this->type(mapEnabled: true);
        $profile = $this->profile('Transition venue');
        MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $profile->getKey(),
            'projection_key' => 'account_profile:'.(string) $profile->getKey(),
            'category' => 'place',
            'source_type' => 'place',
            'location' => $profile->location,
        ]);
        Queue::fake([DispatchAccountProfileOutboxEventJob::class]);

        app(AccountProfileRegistryManagementService::class)->update(
            Request::create('/admin/api/v1/account_profile_types/place', 'PATCH'),
            'place',
            [
                'expected_capability_revision' => 0,
                'capabilities' => [
                    'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                ],
            ],
        );

        $database = DB::connection('tenant')->getDatabase();
        $item = $database->selectCollection('account_profile_outbox')->findOne([
            '_id' => 'profile-type:place:1:map-poi-reconcile',
        ]);
        $this->assertNotNull($item);
        $this->assertSame('pending', (string) ($item['delivery_state'] ?? ''));
        $this->assertSame(1, MapPoi::query()->where('ref_type', 'account_profile')->count());
        $job = null;
        Queue::assertPushed(
            DispatchAccountProfileOutboxEventJob::class,
            function (DispatchAccountProfileOutboxEventJob $queued) use (&$job): bool {
                $job = $queued;

                return true;
            },
        );
        $this->assertInstanceOf(DispatchAccountProfileOutboxEventJob::class, $job);
        $job->handle(app(AccountProfileOutboxDispatcher::class));

        $completed = $database->selectCollection('account_profile_outbox')->findOne([
            '_id' => 'profile-type:place:1:map-poi-reconcile',
        ]);
        $this->assertSame('completed', (string) ($completed['delivery_state'] ?? ''));
        $this->assertSame(0, MapPoi::query()->where('ref_type', 'account_profile')->count());
        $this->assertSame(1, (int) $type->fresh()?->capability_revision);
    }

    public function test_visual_refreshes_are_durable_distinct_and_survive_unrelated_capability_changes(): void
    {
        $type = $this->type(mapEnabled: true);
        $profiles = [$this->profile('Visual venue one'), $this->profile('Visual venue two')];
        $service = app(AccountProfileRegistryManagementService::class);
        $dispatcher = app(AccountProfileOutboxDispatcher::class);
        $outbox = DB::connection('tenant')->getDatabase()->selectCollection('account_profile_outbox');
        $eventIds = [];

        foreach (['#FF8800', '#00897B'] as $index => $color) {
            Queue::fake();
            $service->update(
                Request::create('/admin/api/v1/account_profile_types/place', 'PATCH'),
                'place',
                ['visual' => ['mode' => 'icon', 'icon' => 'place', 'color' => $color, 'icon_color' => '#FFFFFF']],
            );

            Queue::assertPushed(DispatchAccountProfileOutboxEventJob::class, 1);
            Queue::assertNotPushed(\Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob::class);
            Queue::assertNotPushed(\Belluga\MapPois\Jobs\DeleteMapPoiByRefJob::class);
            $this->assertSame(0, (int) $type->fresh()->capability_revision);
            $this->assertSame($index + 1, $outbox->countDocuments(['operation' => 'map_poi_type_reconcile']));
            $item = $outbox->findOne(['operation' => 'map_poi_type_reconcile', 'delivery_state' => 'pending']);
            $this->assertNotNull($item);
            $eventId = (string) $item['_id'];
            $this->assertNotContains($eventId, $eventIds);
            $eventIds[] = $eventId;

            if ($index === 1) {
                $service->update(
                    Request::create('/admin/api/v1/account_profile_types/place', 'PATCH'),
                    'place',
                    [
                        'expected_capability_revision' => 0,
                        'capabilities' => ['is_queryable' => ['value' => false, 'parameters' => []]],
                    ],
                );
                $this->assertSame(1, (int) $type->fresh()->capability_revision);
            }

            $this->assertTrue($dispatcher->dispatchEvent($eventId));
            $completed = $outbox->findOne(['_id' => $eventId]);
            $this->assertSame('completed', (string) $completed['delivery_state']);
            foreach ($profiles as $profile) {
                $projection = MapPoi::query()->where('ref_type', 'account_profile')
                    ->where('ref_id', (string) $profile->getKey())->first();
                $this->assertNotNull($projection);
                $this->assertSame($color, data_get($projection->visual, 'color'));
                $this->assertSame('place', data_get($projection->visual, 'icon'));
            }
        }
    }

    public function test_reconciliation_schedules_and_completes_the_next_keyset_page(): void
    {
        $type = $this->type(mapEnabled: true);
        foreach (range(1, 101) as $index) {
            $this->profile('Paged venue '.$index);
        }

        $eventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordMapPoiTypeReconcile(
                $context,
                'place',
                0,
                1234,
            ),
        );
        $dispatcher = app(AccountProfileOutboxDispatcher::class);
        Queue::fake([DispatchAccountProfileOutboxEventJob::class]);
        $this->assertTrue($dispatcher->dispatchEvent($eventId));

        $database = DB::connection('tenant')->getDatabase();
        $pending = $database->selectCollection('account_profile_outbox')->findOne(['_id' => $eventId]);
        $this->assertSame('pending', (string) ($pending['delivery_state'] ?? ''));
        $this->assertSame(100, MapPoi::query()->where('ref_type', 'account_profile')->count());
        $continuation = null;
        Queue::assertPushed(
            DispatchAccountProfileOutboxEventJob::class,
            function (DispatchAccountProfileOutboxEventJob $job) use ($eventId, &$continuation): bool {
                $eventIdProperty = new \ReflectionProperty($job, 'eventId');
                $continuation = $job;

                return $eventIdProperty->getValue($job) === $eventId;
            },
        );
        $this->assertInstanceOf(ShouldQueue::class, $continuation);
        $this->assertInstanceOf(TenantAware::class, $continuation);
        $this->assertSame(5, $continuation->tries);
        $this->assertSame(DispatchAccountProfileOutboxEventJob::TIMEOUT_SECONDS, $continuation->timeout);
        $this->assertSame([1, 5, 15, 30], $continuation->backoff());

        $claimTtl = (new \ReflectionClass(AccountProfileOutboxDispatcher::class))
            ->getReflectionConstant('CLAIM_TTL_SECONDS')
            ?->getValue();
        $this->assertIsInt($claimTtl);
        $this->assertGreaterThan($continuation->timeout, $claimTtl);

        $continuation->handle($dispatcher);
        $completed = $database->selectCollection('account_profile_outbox')->findOne(['_id' => $eventId]);
        $this->assertSame('completed', (string) ($completed['delivery_state'] ?? ''));
        $this->assertSame(2, (int) ($completed['delivery_attempts'] ?? 0));
        $this->assertSame(101, MapPoi::query()->where('ref_type', 'account_profile')->count());
        $this->assertSame(2, (int) $type->fresh()?->host_admission_fence_revision);
    }

    public function test_obsolete_reconciliation_generation_completes_without_projection(): void
    {
        $type = $this->type(mapEnabled: true);
        $this->profile('Obsolete venue');
        $eventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordMapPoiTypeReconcile(
                $context,
                'place',
                0,
                1234,
            ),
        );
        $type->capability_revision = 1;
        $type->save();

        $this->assertTrue(app(AccountProfileOutboxDispatcher::class)->dispatchEvent($eventId));

        $item = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $eventId]);
        $this->assertSame('completed', (string) ($item['delivery_state'] ?? ''));
        $this->assertSame(0, MapPoi::query()->where('ref_type', 'account_profile')->count());
    }

    public function test_equal_generation_reconcile_page_retries_after_newer_type_transition_without_stale_projection_or_cursor(): void
    {
        $this->type(mapEnabled: true);
        $this->profile('Paused reconciliation venue');
        $eventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordMapPoiTypeReconcile(
                $context,
                'place',
                0,
                1234,
            ),
        );

        $this->assertPausedProjectionLosesToDisableTransition($eventId, 'type_reconcile');

        $item = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $eventId]);
        $this->assertSame('completed', (string) ($item['delivery_state'] ?? ''));
        $this->assertNull($item['after_profile_id'] ?? null);
        $this->assertSame(0, MapPoi::query()->where('ref_type', 'account_profile')->count());
    }

    public function test_ordinary_projection_retries_after_disabling_transition_without_stale_upsert_or_acknowledgement(): void
    {
        $this->type(mapEnabled: true);
        $profile = $this->profile('Paused ordinary projection venue');
        $eventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordUpsert(
                $context,
                $profile,
                'paused-ordinary-projection',
                hash('sha256', 'paused-ordinary-projection'),
            ),
        );

        $this->assertPausedProjectionLosesToDisableTransition($eventId, 'ordinary');

        $database = DB::connection('tenant')->getDatabase();
        $item = $database->selectCollection('account_profile_outbox')->findOne(['_id' => $eventId]);
        $checkpoint = $database->selectCollection('account_profile_projection_checkpoints')->findOne([
            'consumer_id' => 'map_poi',
            'profile_id' => (string) $profile->getKey(),
        ]);
        $this->assertSame('completed', (string) ($item['delivery_state'] ?? ''));
        $this->assertSame(1, (int) ($checkpoint['aggregate_revision'] ?? 0));
        $this->assertSame(0, MapPoi::query()->where('ref_type', 'account_profile')->count());
    }

    public function test_tombstone_removes_projection_without_touching_the_shared_profile_type_fence(): void
    {
        $type = $this->type(mapEnabled: true);
        $profile = $this->profile('Tombstone without type fence');
        MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $profile->getKey(),
            'projection_key' => 'account_profile:'.(string) $profile->getKey(),
            'category' => 'place',
            'source_type' => 'place',
            'location' => $profile->location,
        ]);
        $publisher = app(AccountProfileOutboxPublisher::class);
        $eventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => $publisher->recordTombstone(
                $context,
                $profile,
                'tombstone-without-type-fence',
                $publisher->fingerprintForLifecycle((string) $profile->getKey(), 'force_delete'),
            ),
        );

        $this->assertTrue(app(AccountProfileOutboxDispatcher::class)->dispatchEvent($eventId));

        $this->assertNull(MapPoi::query()->where('ref_id', (string) $profile->getKey())->first());
        $this->assertSame(0, (int) $type->fresh()?->host_admission_fence_revision);
    }

    public function test_ordinary_projection_uses_the_profile_type_state_loaded_by_its_fence(): void
    {
        $type = $this->type(mapEnabled: true);
        $firstProfile = $this->profile('First fence-loaded projection venue');
        $secondProfile = $this->profile('Second fence-loaded projection venue');
        $reconciledProfile = $this->profile('Fence-loaded reconciliation venue');
        $firstEventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordUpsert(
                $context,
                $firstProfile,
                'first-fence-loaded-projection',
                hash('sha256', 'first-fence-loaded-projection'),
            ),
        );
        $secondEventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordUpsert(
                $context,
                $secondProfile,
                'second-fence-loaded-projection',
                hash('sha256', 'second-fence-loaded-projection'),
            ),
        );
        $dispatcher = app(AccountProfileOutboxDispatcher::class);
        $this->assertTrue($dispatcher->dispatchEvent($firstEventId));
        $this->assertNotNull(MapPoi::query()->where('ref_id', (string) $firstProfile->getKey())->first());

        $capabilities = $type->capabilities;
        $capabilities['is_map_poi_enabled'] = ['value' => false, 'parameters' => []];
        DB::connection('tenant')->getDatabase()->selectCollection('account_profile_types')->updateOne(
            ['type' => 'place'],
            ['$set' => ['capabilities' => $capabilities, 'capability_revision' => 1]],
        );
        $storedType = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_types')
            ->findOne(['type' => 'place']);
        $this->assertFalse((bool) data_get($storedType, 'capabilities.is_map_poi_enabled.value', true));

        $this->assertTrue($dispatcher->dispatchEvent($secondEventId));
        $this->assertNull(MapPoi::query()->where('ref_id', (string) $secondProfile->getKey())->first());
        $reconcileEventId = app(AccountProfileTransactionRunner::class)->run(
            fn ($context): string => app(AccountProfileOutboxPublisher::class)->recordMapPoiTypeReconcile(
                $context,
                'place',
                1,
                1234,
            ),
        );

        $this->assertTrue($dispatcher->dispatchEvent($reconcileEventId));
        $this->assertNull(MapPoi::query()->where('ref_id', (string) $reconciledProfile->getKey())->first());
    }

    public function test_account_profile_projection_rejects_a_non_numeric_source_point(): void
    {
        $this->type(mapEnabled: true);
        $profileId = (string) new ObjectId;

        app(\Belluga\MapPois\Application\MapPoiProjectionService::class)->upsertFromAccountProfile(
            (object) [
                '_id' => $profileId,
                'profile_type' => 'place',
                'display_name' => 'Invalid point source',
                'location' => ['type' => 'Point', 'coordinates' => ['invalid', -22.9]],
                'is_active' => true,
            ],
            parentAccountPublished: true,
        );

        $this->assertNull(MapPoi::query()->where('ref_id', $profileId)->first());
    }

    #[DataProvider('publicationCapabilityCases')]
    public function test_publication_preserves_configured_and_effective_map_eligibility(bool $configured, string $locationPolicy, bool $effective): void
    {
        $type = $this->type(mapEnabled: $configured);
        $capabilities = $type->capabilities;
        $capabilities['location_policy'] = ['value' => $locationPolicy, 'parameters' => []];
        $type->capabilities = $capabilities;
        $type->save();
        $profile = $this->profile('Publication capability venue');
        $account = Account::query()->findOrFail($profile->account_id);
        $account->publication = ['status' => 'draft', 'publish_at' => null];
        $account->save();

        $resolver = app(AccountProfileCapabilityResolverContract::class);
        $resolvedBefore = $resolver->resolveForProfileType($type->fresh(), 'is_map_poi_enabled');
        $this->assertSame($configured, $resolvedBefore['configured']['value']);
        $this->assertSame($effective, $resolvedBefore['effective']['value']);

        app(AccountManagementService::class)->update($account, [
            'publication' => ['status' => 'published', 'publish_at' => null],
        ]);

        $this->assertSame('published', data_get($account->fresh()->publication, 'status'));
        $this->assertTrue((bool) $profile->fresh()->is_active);
        $resolvedAfter = $resolver->resolveForProfileType($type->fresh(), 'is_map_poi_enabled');
        $this->assertSame($configured, $resolvedAfter['configured']['value']);
        $this->assertSame($effective, $resolvedAfter['effective']['value']);
        $projection = MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', (string) $profile->_id)->first();
        if ($effective) {
            $this->assertNotNull($projection);
            $this->assertTrue((bool) $projection->is_active);
        } else {
            $this->assertNull($projection);
        }
    }

    public static function publicationCapabilityCases(): array
    {
        return [
            'enabled' => [true, 'optional', true],
            'explicitly disabled' => [false, 'optional', false],
            'dependency dormant' => [true, 'disabled', false],
        ];
    }

    private function type(bool $mapEnabled): TenantProfileType
    {
        $capabilities = app(AccountProfileCapabilityRegistry::class)->completeCreationConfiguration([
            'location_policy' => ['value' => 'optional', 'parameters' => []],
            'is_map_poi_enabled' => ['value' => $mapEnabled, 'parameters' => []],
        ]);

        return TenantProfileType::query()->create([
            'type' => 'place',
            'label' => 'Place',
            'allowed_taxonomies' => [],
            'capabilities' => $capabilities,
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
    }

    private function profile(string $name): AccountProfile
    {
        $account = Account::query()->create([
            'name' => $name,
            'document' => 'MAP-'.bin2hex(random_bytes(6)),
            'ownership_state' => 'tenant_owned',
            'publication' => ['status' => 'published', 'publish_at' => null],
        ]);

        return AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'place',
            'display_name' => $name,
            'slug' => str($name)->slug()->append('-'.bin2hex(random_bytes(3)))->toString(),
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
            'is_active' => true,
            'aggregate_revision' => 1,
        ]);
    }

    private function assertPausedProjectionLosesToDisableTransition(string $eventId, string $scenario): void
    {
        $tenantSlug = (string) Tenant::current()?->slug;
        $barrier = sys_get_temp_dir().'/map-poi-type-fence-race-'.bin2hex(random_bytes(8));
        $process = $this->pausedProjectionProcess($tenantSlug, $eventId, $barrier, $scenario);

        try {
            $process->start();
            $this->waitForProjectionBarrier($process, $barrier.'.ready');
            $payload = [
                'expected_capability_revision' => 0,
                'capabilities' => [
                    'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                ],
            ];
            $result = app(AccountProfileRegistryManagementService::class)->update(
                Request::create(
                    'http://map-capability-projection.test/admin/api/v1/account_profile_types/place',
                    'PATCH',
                    $payload,
                ),
                'place',
                $payload,
            );
            $this->assertSame(1, $result['capability_revision'], $scenario);
        } finally {
            file_put_contents($barrier.'.release', 'release');
        }

        $process->wait();
        $this->assertTrue(
            $process->isSuccessful(),
            $scenario.":\n".$process->getOutput()."\n".$process->getErrorOutput(),
        );
        $worker = $this->projectionRaceResult($process);
        $this->assertSame('ok', $worker['status'] ?? null, json_encode($worker, JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) ($worker['paused_in_transaction'] ?? false), $scenario);

        Tenant::query()->where('slug', $tenantSlug)->firstOrFail()->makeCurrent();
        $type = TenantProfileType::query()->where('type', 'place')->firstOrFail();
        $this->assertSame(1, (int) $type->capability_revision, $scenario);
        $this->assertFalse((bool) data_get($type->capabilities, 'is_map_poi_enabled.value', true), $scenario);

        foreach ([$barrier.'.ready', $barrier.'.release'] as $path) {
            @unlink($path);
        }
    }

    private function pausedProjectionProcess(
        string $tenantSlug,
        string $eventId,
        string $barrier,
        string $scenario,
    ): Process {
        $tenantSlugValue = var_export($tenantSlug, true);
        $eventIdValue = var_export($eventId, true);
        $barrierValue = var_export($barrier, true);
        $scenarioValue = var_export($scenario, true);
        $targetCollectionValue = var_export(
            $scenario === 'ordinary' ? 'account_profiles' : 'account_profile_types',
            true,
        );
        $timeoutValue = var_export(self::BARRIER_TIMEOUT_SECONDS, true);
        $code = <<<PHP
try {
    \$tenant = \App\Models\Landlord\Tenant::query()->where('slug', {$tenantSlugValue})->firstOrFail();
    \$tenant->makeCurrent();
    \$barrier = {$barrierValue};
    \$subscriber = new class(\$barrier, {$timeoutValue}, {$targetCollectionValue}) implements \MongoDB\Driver\Monitoring\CommandSubscriber {
        private bool \$targetStarted = false;
        private bool \$paused = false;
        private bool \$pausedInsideTransaction = false;

        public function __construct(
            private string \$barrier,
            private int \$timeout,
            private string \$targetCollection,
        ) {}

        public function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent \$event): void
        {
            if (\$this->paused || \$this->targetStarted || \$event->getCommandName() !== 'find') {
                return;
            }
            \$command = \$event->getCommand();
            if ((string) (\$command->find ?? '') !== \$this->targetCollection) {
                return;
            }
            \$this->pausedInsideTransaction = property_exists(\$command, 'txnNumber');
            \$this->targetStarted = true;
        }

        public function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent \$event): void
        {
            if (\$this->paused || ! \$this->targetStarted || \$event->getCommandName() !== 'find') {
                return;
            }
            \$this->paused = true;
            file_put_contents(\$this->barrier.'.ready', 'ready');
            \$deadline = microtime(true) + \$this->timeout;
            while (! is_file(\$this->barrier.'.release')) {
                if (microtime(true) >= \$deadline) {
                    throw new \RuntimeException('Map projection race barrier timed out');
                }
                usleep(10_000);
            }
        }

        public function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent \$event): void {}

        public function pausedInsideTransaction(): bool
        {
            return \$this->pausedInsideTransaction;
        }
    };
    \$client = \Illuminate\Support\Facades\DB::connection('tenant')->getClient();
    \$client->addSubscriber(\$subscriber);
    \$dispatched = app(\App\Application\AccountProfiles\AccountProfileOutboxDispatcher::class)
        ->dispatchEvent({$eventIdValue});
    \$client->removeSubscriber(\$subscriber);
    echo 'MAP_PROJECTION_RACE_RESULT='.json_encode([
        'status' => 'ok',
        'scenario' => {$scenarioValue},
        'dispatched' => \$dispatched,
        'paused_in_transaction' => \$subscriber->pausedInsideTransaction(),
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable \$exception) {
    echo 'MAP_PROJECTION_RACE_RESULT='.json_encode([
        'status' => 'error',
        'scenario' => {$scenarioValue},
        'exception' => \$exception::class,
        'message' => \$exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
PHP;

        return new Process(
            [PHP_BINARY, 'artisan', 'tinker', '--execute', $code],
            base_path(),
            null,
            null,
            self::PROCESS_TIMEOUT_SECONDS,
        );
    }

    private function waitForProjectionBarrier(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                $this->fail($process->getOutput()."\n".$process->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                $process->stop(1);
                $this->fail('Timed out waiting for the deterministic Map projection race barrier.');
            }
            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function projectionRaceResult(Process $process): array
    {
        $matched = preg_match(
            '/MAP_PROJECTION_RACE_RESULT=(\{[^\r\n]+\})/',
            $process->getOutput(),
            $matches,
        );
        $this->assertSame(1, $matched, $process->getOutput());

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
