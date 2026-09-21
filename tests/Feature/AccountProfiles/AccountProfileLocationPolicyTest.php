<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileLocationPolicy;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileRegistryManagementService;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventAggregateWriteService;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class AccountProfileLocationPolicyTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    private const BARRIER_TIMEOUT_SECONDS = 30;

    private const PROCESS_TIMEOUT_SECONDS = 60;

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
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Location Policy', 'subdomain' => 'location-policy'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'location-policy@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['location-policy.test'],
            ));
            self::$bootstrapped = true;
        }
        Tenant::query()->firstOrFail()->makeCurrent();
        TenantProfileType::query()->delete();
    }

    public function test_disabled_optional_and_required_writes_follow_the_canonical_truth_table(): void
    {
        $policy = app(AccountProfileLocationPolicy::class);
        $this->type('disabled', false);
        $policy->assertCreateAllowed('place', null);
        $this->expectValidation(fn () => $policy->assertCreateAllowed('place', ['lat' => -22.9, 'lng' => -43.2]));

        $this->type('optional', true);
        $policy->assertCreateAllowed('place', null);
        $policy->assertCreateAllowed('place', ['lat' => -22.9, 'lng' => -43.2]);
        $this->expectValidation(fn () => $policy->assertCreateAllowed('place', ['lat' => -22.9]));
        $this->expectValidation(fn () => $policy->assertCreateAllowed('place', ['lat' => '-22.9', 'lng' => '-43.2']));

        $this->type('required', true);
        $this->expectValidation(fn () => $policy->assertCreateAllowed('place', null));
        $policy->assertCreateAllowed('place', ['lat' => -22.9, 'lng' => -43.2]);
    }

    public function test_disabled_update_preserves_dormant_location_on_omission_and_only_accepts_explicit_clear(): void
    {
        $this->type('disabled', false);
        $profile = new AccountProfile([
            'profile_type' => 'place',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ]);
        $policy = app(AccountProfileLocationPolicy::class);

        $policy->assertUpdateAllowed($profile, 'place', false, null);
        $policy->assertUpdateAllowed($profile, 'place', true, null);
        $this->expectValidation(fn () => $policy->assertUpdateAllowed(
            $profile,
            'place',
            true,
            ['lat' => -22.8, 'lng' => -43.1],
        ));
    }

    public function test_map_projection_requires_capability_permission_and_valid_instance_point(): void
    {
        $this->type('optional', true);
        $policy = app(AccountProfileLocationPolicy::class);
        self::assertTrue($policy->isMapProjectionEligible(new AccountProfile([
            'profile_type' => 'place',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ])));
        self::assertFalse($policy->isMapProjectionEligible(new AccountProfile([
            'profile_type' => 'place',
            'location' => null,
        ])));

        $this->type('disabled', false);
        self::assertFalse($policy->isMapProjectionEligible(new AccountProfile([
            'profile_type' => 'place',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ])));
    }

    public function test_optional_to_required_transition_serializes_against_every_profile_admission_path(): void
    {
        foreach (['create', 'onboarding', 'clear', 'reassign_to', 'reassign_from'] as $scenario) {
            $this->assertOptionalToRequiredRace($scenario);
        }
    }

    public function test_unrelated_profile_write_does_not_touch_the_type_admission_fence(): void
    {
        $this->resetRaceCollections();
        $this->raceTypes();
        $account = $this->raceAccount('unrelated-profile');
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'place',
            'display_name' => 'Unrelated Profile',
            'slug' => 'unrelated-profile',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
            'is_active' => true,
        ]);
        $before = (int) TenantProfileType::query()->where('type', 'place')->firstOrFail()
            ->host_admission_fence_revision;

        app(AccountProfileManagementService::class)->update(
            $profile,
            ['display_name' => 'Unrelated Profile Renamed'],
            'unrelated-profile-write',
            dispatchOutboxImmediately: false,
        );

        $after = (int) TenantProfileType::query()->where('type', 'place')->firstOrFail()
            ->host_admission_fence_revision;
        self::assertSame($before, $after);
    }

    public function test_unrelated_event_write_does_not_touch_the_type_admission_fence(): void
    {
        $this->resetRaceCollections();
        $this->raceTypes();
        $event = Event::query()->create([
            'title' => 'Fence Neutral Event',
            'slug' => 'fence-neutral-event',
            'content' => '',
            'publication' => ['status' => 'draft', 'publish_at' => null],
            'is_active' => true,
        ]);
        $before = (int) TenantProfileType::query()->where('type', 'place')->firstOrFail()
            ->host_admission_fence_revision;

        app(EventAggregateWriteService::class)->update(
            $event,
            ['title' => 'Fence Neutral Event Renamed'],
            [],
        );

        $after = (int) TenantProfileType::query()->where('type', 'place')->firstOrFail()
            ->host_admission_fence_revision;
        self::assertSame($before, $after);
    }

    private function type(string $locationPolicy, bool $mapEnabled): void
    {
        $registry = app(AccountProfileCapabilityRegistry::class);
        TenantProfileType::query()->where('type', 'place')->delete();
        TenantProfileType::create([
            'type' => 'place',
            'capabilities' => $registry->completeCreationConfiguration([
                'location_policy' => ['value' => $locationPolicy, 'parameters' => []],
                'is_map_poi_enabled' => ['value' => $mapEnabled, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected location validation to fail.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('location', $exception->errors());
        }
    }

    private function assertOptionalToRequiredRace(string $scenario): void
    {
        $this->resetRaceCollections();
        $this->raceTypes();
        $fixture = $this->raceFixture($scenario);
        $tenantSlug = (string) Tenant::current()?->slug;
        $barrier = sys_get_temp_dir().'/account-profile-location-race-'.bin2hex(random_bytes(8));
        $process = $this->profileAdmissionRaceProcess(
            $tenantSlug,
            $barrier,
            $scenario,
            $fixture['account_id'],
            $fixture['profile_id'],
            $fixture['name'],
        );

        try {
            $process->start();
            $this->waitForRaceBarrier($process, $barrier.'.ready');

            $payload = [
                'expected_capability_revision' => 0,
                'capabilities' => [
                    'location_policy' => ['value' => 'required', 'parameters' => []],
                ],
            ];
            $result = app(AccountProfileRegistryManagementService::class)->update(
                Request::create(
                    'http://location-policy.test/admin/api/v1/account_profile_types/place',
                    'PATCH',
                    $payload,
                ),
                'place',
                $payload,
            );
            self::assertSame(1, $result['capability_revision'], $scenario);
        } finally {
            file_put_contents($barrier.'.release', 'release');
        }

        $process->wait();
        self::assertTrue(
            $process->isSuccessful(),
            $scenario.":\n".$process->getOutput()."\n".$process->getErrorOutput(),
        );
        $worker = $this->profileAdmissionRaceResult($process);

        Tenant::query()->where('slug', $tenantSlug)->firstOrFail()->makeCurrent();
        $type = TenantProfileType::query()->where('type', 'place')->firstOrFail();
        self::assertSame('required', data_get($type->capabilities, 'location_policy.value'), $scenario);
        self::assertSame(1, (int) $type->capability_revision, $scenario);

        if ($scenario === 'reassign_from') {
            self::assertSame('ok', $worker['status'] ?? null, json_encode($worker, JSON_THROW_ON_ERROR));
            self::assertSame(
                'origin',
                (string) AccountProfile::query()->findOrFail($fixture['profile_id'])->profile_type,
            );
        } else {
            self::assertSame('validation', $worker['status'] ?? null, json_encode($worker, JSON_THROW_ON_ERROR));
            self::assertArrayHasKey('location', (array) ($worker['errors'] ?? []), $scenario);
            self::assertGreaterThanOrEqual(2, (int) ($worker['type_reads'] ?? 0), $scenario);
            if (in_array($scenario, ['create', 'onboarding'], true)) {
                self::assertTrue((bool) ($worker['paused_in_transaction'] ?? false), $scenario);
            }
            match ($scenario) {
                'create' => self::assertSame(0, AccountProfile::query()->where('slug', 'race-create')->count()),
                'onboarding' => self::assertSame(0, Account::query()->where('name', $fixture['name'])->count()),
                'clear' => self::assertSame(
                    'Point',
                    data_get(AccountProfile::query()->findOrFail($fixture['profile_id'])->location, 'type'),
                ),
                'reassign_to' => self::assertSame(
                    'origin',
                    (string) AccountProfile::query()->findOrFail($fixture['profile_id'])->profile_type,
                ),
                default => null,
            };
        }

        foreach ([$barrier.'.ready', $barrier.'.release'] as $path) {
            @unlink($path);
        }
    }

    /** @return array{account_id:string,profile_id:string,name:string} */
    private function raceFixture(string $scenario): array
    {
        $name = 'Location Race '.str_replace('_', ' ', $scenario).' '.bin2hex(random_bytes(4));
        if ($scenario === 'onboarding') {
            return ['account_id' => '', 'profile_id' => '', 'name' => $name];
        }

        $account = $this->raceAccount($scenario);
        if ($scenario === 'create') {
            return ['account_id' => (string) $account->getKey(), 'profile_id' => '', 'name' => $name];
        }

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => $scenario === 'reassign_to' ? 'origin' : 'place',
            'display_name' => $name,
            'slug' => 'race-'.str_replace('_', '-', $scenario),
            'location' => $scenario === 'reassign_to'
                ? null
                : ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
            'is_active' => true,
        ]);

        return [
            'account_id' => (string) $account->getKey(),
            'profile_id' => (string) $profile->getKey(),
            'name' => $name,
        ];
    }

    private function raceAccount(string $suffix): Account
    {
        return Account::query()->create([
            'name' => 'Location Race Account '.$suffix,
            'document' => 'LOCATION-RACE-'.strtoupper($suffix).'-'.bin2hex(random_bytes(4)),
            'ownership_state' => 'tenant_owned',
            'publication' => ['status' => 'draft', 'publish_at' => null],
        ]);
    }

    private function resetRaceCollections(): void
    {
        foreach ([
            'account_profile_outbox',
            'account_profile_projection_checkpoints',
            'map_pois',
            'accounts_nested',
            'event_occurrences',
            'events',
            'account_profiles',
            'account_role_templates',
            'accounts',
            'account_profile_types',
        ] as $collection) {
            DB::connection('tenant')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
    }

    private function raceTypes(): void
    {
        $registry = app(AccountProfileCapabilityRegistry::class);
        TenantProfileType::query()->create([
            'type' => 'place',
            'label' => 'Place',
            'capabilities' => $registry->completeCreationConfiguration([
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
        TenantProfileType::query()->create([
            'type' => 'origin',
            'label' => 'Origin',
            'capabilities' => $registry->completeCreationConfiguration([
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
    }

    private function profileAdmissionRaceProcess(
        string $tenantSlug,
        string $barrier,
        string $scenario,
        string $accountId,
        string $profileId,
        string $name,
    ): Process {
        $tenantSlugValue = var_export($tenantSlug, true);
        $barrierValue = var_export($barrier, true);
        $scenarioValue = var_export($scenario, true);
        $accountIdValue = var_export($accountId, true);
        $profileIdValue = var_export($profileId, true);
        $nameValue = var_export($name, true);
        $timeoutValue = var_export(self::BARRIER_TIMEOUT_SECONDS, true);
        $commandIdValue = var_export('location-race-'.$scenario.'-'.bin2hex(random_bytes(8)), true);
        $code = <<<PHP
try {
    \$tenant = \App\Models\Landlord\Tenant::query()->where('slug', {$tenantSlugValue})->firstOrFail();
    \$tenant->makeCurrent();
    \$barrier = {$barrierValue};
    \$subscriber = new class(\$barrier, {$timeoutValue}) implements \MongoDB\Driver\Monitoring\CommandSubscriber {
        private ?int \$targetRequestId = null;
        private bool \$paused = false;
        private int \$typeReads = 0;
        private bool \$pausedInsideTransaction = false;

        public function __construct(private string \$barrier, private int \$timeout) {}

        public function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent \$event): void
        {
            \$commandName = \$event->getCommandName();
            if (! in_array(\$commandName, ['find', 'aggregate', 'findAndModify'], true)) {
                return;
            }
            \$command = \$event->getCommand();
            if ((string) (\$command->{\$commandName} ?? '') !== 'account_profile_types') {
                return;
            }
            \$this->typeReads++;
            if (\$this->paused || \$this->targetRequestId !== null) {
                return;
            }
            \$this->pausedInsideTransaction = property_exists(\$command, 'txnNumber');
            \$this->targetRequestId = \$event->getRequestId();
        }

        public function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent \$event): void
        {
            if (\$this->paused
                || \$this->targetRequestId === null
                || ! in_array(\$event->getCommandName(), ['find', 'aggregate', 'findAndModify'], true)) {
                return;
            }
            \$this->paused = true;
            file_put_contents(\$this->barrier.'.ready', 'ready');
            \$deadline = microtime(true) + \$this->timeout;
            while (! is_file(\$this->barrier.'.release')) {
                if (microtime(true) >= \$deadline) {
                    throw new \RuntimeException('profile admission race barrier timed out');
                }
                usleep(10_000);
            }
        }

        public function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent \$event): void {}

        public function typeReads(): int
        {
            return \$this->typeReads;
        }

        public function pausedInsideTransaction(): bool
        {
            return \$this->pausedInsideTransaction;
        }
    };
    \$client = \Illuminate\Support\Facades\DB::connection('tenant')->getClient();
    \$client->addSubscriber(\$subscriber);
    \$scenario = {$scenarioValue};
    \$commandId = {$commandIdValue};
    if (\$scenario === 'create') {
        app(\App\Application\AccountProfiles\AccountProfileManagementService::class)->create([
            'account_id' => {$accountIdValue},
            'profile_type' => 'place',
            'display_name' => {$nameValue},
            'slug' => 'race-create',
            'location' => null,
            'taxonomy_terms' => [],
        ], \$commandId);
    } elseif (\$scenario === 'onboarding') {
        app(\App\Application\Accounts\AccountOnboardingService::class)->create([
            'name' => {$nameValue},
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'place',
            'location' => null,
            'taxonomy_terms' => [],
        ], \Illuminate\Http\Request::create('http://location-policy.test/onboarding', 'POST'), \$commandId);
    } else {
        \$profile = \App\Models\Tenants\AccountProfile::query()->findOrFail({$profileIdValue});
        \$attributes = match (\$scenario) {
            'clear' => ['location' => null],
            'reassign_to' => ['profile_type' => 'place'],
            'reassign_from' => ['profile_type' => 'origin'],
        };
        app(\App\Application\AccountProfiles\AccountProfileManagementService::class)
            ->update(\$profile, \$attributes, \$commandId);
    }
    \$client->removeSubscriber(\$subscriber);
    echo 'PROFILE_ADMISSION_RACE_RESULT='.json_encode([
        'status' => 'ok',
        'scenario' => \$scenario,
        'type_reads' => \$subscriber->typeReads(),
        'paused_in_transaction' => \$subscriber->pausedInsideTransaction(),
    ], JSON_THROW_ON_ERROR);
} catch (\Illuminate\Validation\ValidationException \$exception) {
    echo 'PROFILE_ADMISSION_RACE_RESULT='.json_encode([
        'status' => 'validation',
        'scenario' => {$scenarioValue},
        'errors' => \$exception->errors(),
        'type_reads' => isset(\$subscriber) ? \$subscriber->typeReads() : 0,
        'paused_in_transaction' => isset(\$subscriber) ? \$subscriber->pausedInsideTransaction() : false,
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable \$exception) {
    echo 'PROFILE_ADMISSION_RACE_RESULT='.json_encode([
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

    private function waitForRaceBarrier(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                self::fail($process->getOutput()."\n".$process->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                $process->stop(1);
                self::fail('Timed out waiting for the deterministic profile-admission race barrier.');
            }
            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function profileAdmissionRaceResult(Process $process): array
    {
        $matched = preg_match(
            '/PROFILE_ADMISSION_RACE_RESULT=(\{[^\r\n]+\})/',
            $process->getOutput(),
            $matches,
        );
        self::assertSame(1, $matched, $process->getOutput());

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
