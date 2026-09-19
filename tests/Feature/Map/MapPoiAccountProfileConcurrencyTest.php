<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountPublicationStateService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
                    'is_queryable' => ['value' => true, 'parameters' => []],
                    'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                    'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                    'location_policy' => ['value' => 'required', 'parameters' => []], 'is_map_poi_enabled' => ['value' => true, 'parameters' => []], 'is_physical_host_enabled' => ['value' => true, 'parameters' => []], 'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
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

    public function test_ordered_publication_conflict_rolls_back_and_sequential_publication_preserves_activity(): void
    {
        $account = Account::query()->create([
            'name' => 'Ordered Map Account',
            'document' => 'DOC-ORDERED-MAP',
            'ownership_state' => 'tenant_owned',
            'publication' => ['status' => AccountPublicationStateService::DRAFT, 'publish_at' => null],
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Ordered Map Venue',
            'slug' => 'ordered-map-venue',
            'visibility' => 'public',
            'location' => ['type' => 'Point', 'coordinates' => [-40.0, -20.0]],
            'is_active' => false,
        ]);
        app(MapPoiProjectionService::class)->upsertFromAccountProfile($profile->fresh());
        $barrier = sys_get_temp_dir().'/map-poi-ordered-'.bin2hex(random_bytes(8));
        $tenantSlug = var_export((string) Tenant::current()?->slug, true);
        $accountId = var_export((string) $account->_id, true);
        $barrierValue = var_export($barrier, true);
        $deadlineSeconds = self::BARRIER_TIMEOUT_SECONDS;
        $code = <<<PHP
\$tenant = \App\Models\Landlord\Tenant::query()->where('slug', {$tenantSlug})->firstOrFail();
\$tenant->makeCurrent();
\$account = \App\Models\Tenants\Account::query()->findOrFail({$accountId});
\$paused = false;
\App\Models\Tenants\Account::saved(function (\$saved) use (&\$paused): void {
    if (\$paused || (string) \$saved->_id !== {$accountId}) {
        return;
    }
    \$paused = true;
    file_put_contents({$barrierValue}.'.saved', 'ready');
    \$deadline = microtime(true) + {$deadlineSeconds};
    while (! is_file({$barrierValue}.'.release')) {
        if (microtime(true) >= \$deadline) {
            throw new \RuntimeException('Ordered publication barrier timed out');
        }
        usleep(10000);
    }
});
try {
    app(\App\Application\Accounts\AccountManagementService::class)->update(\$account, [
        'publication' => ['status' => 'published', 'publish_at' => null],
    ]);
    \$result = ['status' => 'ok'];
} catch (\Illuminate\Validation\ValidationException \$exception) {
    \$result = ['status' => 'rejected', 'exception' => \$exception::class, 'errors' => \$exception->errors()];
}
echo 'MAP_BCI_RESULT='.json_encode(\$result, JSON_THROW_ON_ERROR).PHP_EOL;
PHP;
        $process = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), timeout: self::PROCESS_TIMEOUT_SECONDS);
        try {
            $process->start();
            $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;
            while (! is_file($barrier.'.saved')) {
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $this->assertLessThan($deadline, microtime(true), 'Account must reach its open transaction barrier.');
                usleep(10_000);
            }

            app(AccountProfileManagementService::class)->update($profile, ['is_active' => true], 'ordered-profile-'.(string) $profile->_id);
            $event = DB::connection('tenant')->getDatabase()->selectCollection('account_profile_outbox')
                ->findOne(['profile_id' => (string) $profile->_id]);
            $this->assertNotNull($event);
            $this->assertSame('completed', $event['delivery_state']);
            file_put_contents($barrier.'.release', 'release');
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame([
                'status' => 'rejected',
                'exception' => ValidationException::class,
                'errors' => ['account' => ['Something went wrong when trying to update the account.']],
            ], $this->lastJsonLine($process));
            $this->assertEffectiveActivityInvariant($account, $profile, false, true, 'publication conflict rollback');

            app(AccountManagementService::class)->update($account->fresh(), [
                'publication' => ['status' => AccountPublicationStateService::PUBLISHED, 'publish_at' => null],
            ]);
            $this->assertEffectiveActivityInvariant($account, $profile, true, true, 'sequential publication');
        } finally {
            file_put_contents($barrier.'.release', 'release');
            if ($process->isRunning()) {
                $process->stop(1);
            }
            foreach ([$barrier.'.saved', $barrier.'.release'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
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
        $before = $this->effectiveActivityState($account, $profile);

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
            $this->assertSame(
                array_fill(0, $concurrency, true),
                array_column($results, 'successful'),
                $label.': '.json_encode($results, JSON_THROW_ON_ERROR),
            );
            $admittedOperations = $this->assertOverlapResultsAreAdmittedOrKnownTransactionRejections(
                $results,
                $label,
            );
            $this->assertEffectiveActivityInvariant(
                $account,
                $profile,
                in_array('account', $admittedOperations, true) ? $publish : $before['published'],
                in_array('profile', $admittedOperations, true) ? $activate : $before['profile_active'],
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

    /** @return array{published: bool, profile_active: bool} */
    private function effectiveActivityState(Account $account, AccountProfile $profile): array
    {
        Tenant::query()->firstOrFail()->makeCurrent();

        return [
            'published' => data_get(
                Account::query()->findOrFail((string) $account->_id)->getAttribute('publication'),
                'status',
            ) === AccountPublicationStateService::PUBLISHED,
            'profile_active' => (bool) AccountProfile::query()
                ->findOrFail((string) $profile->_id)
                ->is_active,
        ];
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
    ): array {
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

        $this->assertNotEmpty($admittedOperations, $label.': no mutation admitted');
        fwrite(STDOUT, sprintf(
            "MAP_BCI_BURST %s admitted=%d rejected=%d invariant=preserved\n",
            $label,
            count($results) - $rejected,
            $rejected,
        ));

        return $admittedOperations;
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
