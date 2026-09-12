<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Accounts\AccountPublicationStateService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class MapPoiAccountProfileConcurrencyTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private const BARRIER_TIMEOUT_SECONDS = 30;

    private const PROCESS_TIMEOUT_SECONDS = 60;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
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

        Tenant::query()->firstOrFail()->makeCurrent();
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'venue'],
            [
                'label' => 'Venue',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => true,
                ],
            ],
        );
    }

    public function test_real_same_identity_account_and_profile_overlaps_preserve_effective_activity(): void
    {
        $tenantSlug = (string) Tenant::current()?->slug;
        $account = Account::query()->create([
            'name' => 'Concurrent Map Account',
            'document' => 'DOC-CONCURRENT-MAP',
            'ownership_state' => 'tenant_owned',
            'publication' => [
                'status' => AccountPublicationStateService::DRAFT,
                'publish_at' => null,
            ],
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Concurrent Map Venue',
            'slug' => 'concurrent-map-venue',
            'visibility' => 'public',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => false,
        ]);

        app(MapPoiProjectionService::class)->upsertFromAccountProfile($profile->fresh());
        $this->assertEffectiveActivityInvariant($account, $profile, false, false, 'initial');

        foreach ([5, 10, 20] as $concurrency) {
            $this->runOverlapBurst(
                $tenantSlug,
                $account,
                $profile,
                $concurrency,
                publish: true,
                activate: true,
            );
            $this->runOverlapBurst(
                $tenantSlug,
                $account,
                $profile,
                $concurrency,
                publish: false,
                activate: false,
            );
        }
    }

    private function runOverlapBurst(
        string $tenantSlug,
        Account $account,
        AccountProfile $profile,
        int $concurrency,
        bool $publish,
        bool $activate,
    ): void {
        $barrier = sys_get_temp_dir().'/map-poi-account-profile-barrier-'.bin2hex(random_bytes(8));
        $processes = [];

        try {
            foreach (range(1, $concurrency) as $worker) {
                $processes[] = $this->overlapProcess(
                    tenantSlug: $tenantSlug,
                    accountId: (string) $account->_id,
                    profileId: (string) $profile->_id,
                    barrier: $barrier,
                    concurrency: $concurrency,
                    worker: $worker,
                    operation: $worker % 2 === 1 ? 'account' : 'profile',
                    publish: $publish,
                    activate: $activate,
                );
            }

            foreach ($processes as $process) {
                $process->start();
            }

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $results[] = [
                    'successful' => $process->isSuccessful(),
                    'stdout' => $process->getOutput(),
                    'stderr' => $process->getErrorOutput(),
                    'result' => $this->lastJsonLine($process),
                ];
            }

            $label = sprintf(
                'concurrency=%d,target=%s',
                $concurrency,
                $publish && $activate ? 'active' : 'inactive',
            );
            $this->assertEffectiveActivityInvariant(
                $account,
                $profile,
                $publish,
                $activate,
                $label,
            );
            $this->assertSame(
                array_fill(0, $concurrency, true),
                array_column($results, 'successful'),
                $label.': '.json_encode($results, JSON_THROW_ON_ERROR),
            );
            $this->assertOverlapResultsAreAdmittedOrKnownTransactionRejections(
                $results,
                $label,
            );
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($barrier.'.ready.*') ?: [] as $path) {
                @unlink($path);
            }
        }
    }

    private function overlapProcess(
        string $tenantSlug,
        string $accountId,
        string $profileId,
        string $barrier,
        int $concurrency,
        int $worker,
        string $operation,
        bool $publish,
        bool $activate,
    ): Process {
        $tenantSlugValue = var_export($tenantSlug, true);
        $accountIdValue = var_export($accountId, true);
        $profileIdValue = var_export($profileId, true);
        $barrierValue = var_export($barrier, true);
        $concurrencyValue = var_export($concurrency, true);
        $workerValue = var_export((string) $worker, true);
        $operationValue = var_export($operation, true);
        $statusValue = var_export(
            $publish ? AccountPublicationStateService::PUBLISHED : AccountPublicationStateService::DRAFT,
            true,
        );
        $activateValue = var_export($activate, true);
        $barrierTimeoutValue = var_export(self::BARRIER_TIMEOUT_SECONDS, true);
        $commandIdValue = var_export(
            sprintf('map-poi-overlap-%d-%d-%s', $concurrency, $worker, bin2hex(random_bytes(8))),
            true,
        );
        $code = <<<PHP
try {
    \$tenant = \App\Models\Landlord\Tenant::query()->where('slug', {$tenantSlugValue})->firstOrFail();
    \$tenant->makeCurrent();
    \$operation = {$operationValue};
    \$aggregate = \$operation === 'account'
        ? \App\Models\Tenants\Account::query()->findOrFail({$accountIdValue})
        : \App\Models\Tenants\AccountProfile::query()->findOrFail({$profileIdValue});
    \$barrier = {$barrierValue};
    file_put_contents(\$barrier.'.ready.'.{$workerValue}, 'ready');
    \$deadline = microtime(true) + {$barrierTimeoutValue};
    while (count(glob(\$barrier.'.ready.*')) < {$concurrencyValue}) {
        if (microtime(true) >= \$deadline) {
            throw new \RuntimeException('map POI overlap barrier timed out');
        }
        usleep(10_000);
    }

    if (\$operation === 'account') {
        app(\App\Application\Accounts\AccountManagementService::class)->update(\$aggregate, [
            'publication' => [
                'status' => {$statusValue},
                'publish_at' => null,
            ],
        ]);
    } else {
        app(\App\Application\AccountProfiles\AccountProfileManagementService::class)->update(
            \$aggregate,
            ['is_active' => {$activateValue}],
            {$commandIdValue},
        );
    }

    echo 'MAP_BCI_RESULT='.json_encode(
        ['status' => 'ok', 'operation' => \$operation],
        JSON_THROW_ON_ERROR,
    );
} catch (\Throwable \$exception) {
    echo 'MAP_BCI_RESULT='.json_encode([
        'status' => 'error',
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

    private function assertEffectiveActivityInvariant(
        Account $account,
        AccountProfile $profile,
        bool $expectedPublished,
        bool $expectedProfileActive,
        string $label,
    ): void {
        Tenant::query()->firstOrFail()->makeCurrent();
        $persistedAccount = Account::query()->findOrFail((string) $account->_id);
        $persistedProfile = AccountProfile::query()->findOrFail((string) $profile->_id);
        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->firstOrFail();
        $published = data_get(
            $persistedAccount->getAttribute('publication'),
            'status',
        ) === AccountPublicationStateService::PUBLISHED;
        $profileActive = (bool) $persistedProfile->is_active;

        $this->assertSame($expectedPublished, $published, $label.': account publication');
        $this->assertSame($expectedProfileActive, $profileActive, $label.': profile activity');
        $this->assertSame(
            $published && $profileActive,
            (bool) $projection->is_active,
            $label.': map projection invariant',
        );
    }

    /**
     * MongoDB may reject a same-document transaction contender. The services
     * expose that existing boundary as a validation error; rejected contenders
     * are not admitted mutations, so the burst proof is the persisted invariant.
     *
     * @param  array<int, array{successful: bool, stdout: string, stderr: string, result: array<string, mixed>}>  $results
     */
    private function assertOverlapResultsAreAdmittedOrKnownTransactionRejections(
        array $results,
        string $label,
    ): void {
        $admittedOperations = [];
        $rejected = 0;

        foreach ($results as $result) {
            $payload = $result['result'];
            if (($payload['status'] ?? null) === 'ok') {
                $admittedOperations[] = (string) ($payload['operation'] ?? '');

                continue;
            }

            $rejected++;
            $this->assertSame(
                'Illuminate\\Validation\\ValidationException',
                $payload['exception'] ?? null,
                $label.': '.json_encode($results, JSON_THROW_ON_ERROR),
            );
            $this->assertContains(
                $payload['message'] ?? null,
                [
                    'Something went wrong when trying to update the account.',
                    'Something went wrong when trying to update the account profile.',
                ],
                $label.': '.json_encode($results, JSON_THROW_ON_ERROR),
            );
        }

        $this->assertContains('account', $admittedOperations, $label.': no Account mutation admitted');
        $this->assertContains('profile', $admittedOperations, $label.': no Profile mutation admitted');
        fwrite(STDOUT, sprintf(
            "MAP_BCI_BURST %s admitted=%d rejected=%d invariant=preserved\n",
            $label,
            count($results) - $rejected,
            $rejected,
        ));
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(Process $process): array
    {
        $matched = preg_match(
            '/MAP_BCI_RESULT=(\{[^\r\n]+\})/',
            $process->getOutput(),
            $matches,
        );
        $this->assertSame(1, $matched, $process->getOutput());

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
