<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileReferenceCleanupService;
use App\Application\Profiles\CurrentTenantAccountDeletionAttemptService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Explicitly invoked support harness for real Atlas transaction interleavings.
 * It never addresses an existing tenant database and removes its probe database.
 */
final class AccountProfileLifecycleAtlasProbe
{
    private const PROBE_ENVIRONMENT_FLAG = 'BELLUGA_ATLAS_CONCURRENCY_PROBE';

    /** @return array<string, mixed> */
    public function run(): array
    {
        $probe = $this->provision();
        try {
            $bursts = [];
            foreach ($this->requestedConcurrencyLevels() as $concurrency) {
                $bursts[] = $this->runBurstForProbe(
                    (string) $probe['tenant_id'],
                    (string) $probe['database_name'],
                    $concurrency,
                );
            }

            return [
                'atlas' => true,
                'database_prefix' => 'tenant_u07a_probe_',
                'bursts' => $bursts,
            ];
        } finally {
            $this->cleanup((string) $probe['tenant_id'], (string) $probe['database_name']);
        }
    }

    /** @return array{tenant_id:string,database_name:string} */
    public function provision(): array
    {
        $this->assertConfiguredAtlas();

        $connection = (string) config('multitenancy.tenant_database_connection_name');
        $databaseName = 'tenant_u07a_probe_'.bin2hex(random_bytes(6));
        $tenantId = new ObjectId;
        $tenant = $this->probeTenant($tenantId, $databaseName);
        $tenant->makeCurrent();

        try {
            $migrationExit = Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => config('multitenancy.tenant_migration_paths'),
                '--force' => true,
            ]);
            if ($migrationExit !== 0) {
                throw new RuntimeException('The U07A Atlas probe tenant migrations did not complete.');
            }

            $this->seedProfileTypes();

            return [
                'tenant_id' => (string) $tenantId,
                'database_name' => $databaseName,
            ];
        } catch (Throwable $exception) {
            DB::connection($connection)->getDatabase()->drop();

            throw $exception;
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    public function runBurstForProbe(string $tenantId, string $databaseName, int $concurrency): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            return $this->runBurst(new ObjectId($tenantId), $databaseName, $concurrency);
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    public function runCaptureFirstProbe(string $tenantId, string $databaseName): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedBurstFixture(1);
            app(CurrentTenantAccountDeletionAttemptService::class)->captureOrResume($fixture['user_id']);
            $process = $this->worker(
                tenantId: $tenantId,
                databaseName: $databaseName,
                action: 'admit_relation',
                payload: [
                    'parent_profile_id' => $fixture['parent_profile_id'],
                    'source_profile_id' => $fixture['source_profile_id'],
                    'command_id' => 'u07a-atlas-capture-first-'.bin2hex(random_bytes(4)),
                ],
                startAt: microtime(true),
            );
            $process->start();
            $result = $this->waitForWorker($process);
            if (($result['ok'] ?? false) || ($result['exception'] ?? '') !== 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException') {
                throw new RuntimeException('Atlas relation admission was not rejected after capture.');
            }

            $parent = AccountProfile::withTrashed()->findOrFail($fixture['parent_profile_id']);
            if (trim((string) ($parent->contact_source_account_profile_id ?? '')) !== '') {
                throw new RuntimeException('Atlas relation admission persisted a reference after capture.');
            }

            return [
                'capture_first_rejected' => true,
                'exception' => (string) $result['exception'],
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    public function runUpdateRaceProbe(string $tenantId, string $databaseName, int $concurrency): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedBurstFixture($concurrency);
            $startAt = microtime(true) + 0.5;
            $workers = [
                $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'capture',
                    payload: ['user_id' => $fixture['user_id']],
                    startAt: $startAt,
                ),
            ];
            foreach (range(1, $concurrency) as $index) {
                $workers[] = $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'update_profile',
                    payload: [
                        'profile_id' => $fixture['source_profile_id'],
                        'worker_id' => (string) $index,
                        'command_id' => "u07a-atlas-update-race-{$concurrency}-{$index}-".bin2hex(random_bytes(4)),
                    ],
                    startAt: $startAt,
                );
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = array_map($this->waitForWorker(...), $workers);

            if (! ($results[0]['ok'] ?? false) || ($results[0]['phase'] ?? '') !== 'captured_and_fenced') {
                throw new RuntimeException(
                    'Atlas capture did not complete during the Profile update race: '
                    .json_encode($results, JSON_THROW_ON_ERROR),
                );
            }
            $mutationResults = array_slice($results, 1);
            $invalidResults = array_values(array_filter(
                $mutationResults,
                static fn (array $result): bool => ! ($result['ok'] ?? false)
                    && ($result['exception'] ?? '') !== 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
            ));
            if ($invalidResults !== []) {
                throw new RuntimeException('Atlas Profile update race returned an unexpected result: '.json_encode($invalidResults, JSON_THROW_ON_ERROR));
            }

            $source = AccountProfile::withTrashed()->findOrFail($fixture['source_profile_id']);
            if (trim((string) ($source->account_profile_deletion_attempt_id ?? '')) !== $fixture['user_id']) {
                throw new RuntimeException('Atlas Profile update race left the captured source unfenced.');
            }

            return [
                'concurrency' => $concurrency,
                'update_successes' => count(array_filter(
                    $mutationResults,
                    static fn (array $result): bool => (bool) ($result['ok'] ?? false),
                )),
                'update_conflicts' => count(array_filter(
                    $mutationResults,
                    static fn (array $result): bool => ($result['exception'] ?? '')
                        === 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
                )),
                'source_fenced' => true,
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    public function runCreateRaceProbe(string $tenantId, string $databaseName, int $concurrency): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedBurstFixture($concurrency);
            $startAt = microtime(true) + 0.5;
            $workers = [
                $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'capture',
                    payload: ['user_id' => $fixture['user_id']],
                    startAt: $startAt,
                ),
            ];
            foreach (range(1, $concurrency) as $index) {
                $workers[] = $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'create_profile',
                    payload: [
                        'account_id' => $fixture['candidate_account_ids'][$index - 1],
                        'user_id' => $fixture['user_id'],
                        'worker_id' => (string) $index,
                        'command_id' => "u07a-atlas-create-race-{$concurrency}-{$index}-".bin2hex(random_bytes(4)),
                        'slug_suffix' => bin2hex(random_bytes(4)),
                    ],
                    startAt: $startAt,
                );
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = array_map($this->waitForWorker(...), $workers);

            if (! ($results[0]['ok'] ?? false) || ($results[0]['phase'] ?? '') !== 'captured_and_fenced') {
                throw new RuntimeException(
                    'Atlas capture did not complete during the Profile create race: '
                    .json_encode($results, JSON_THROW_ON_ERROR),
                );
            }
            $mutationResults = array_slice($results, 1);
            $invalidResults = array_values(array_filter(
                $mutationResults,
                static fn (array $result): bool => ! ($result['ok'] ?? false)
                    && ($result['exception'] ?? '') !== 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
            ));
            if ($invalidResults !== []) {
                throw new RuntimeException('Atlas Profile create race returned an unexpected result: '.json_encode($invalidResults, JSON_THROW_ON_ERROR));
            }

            $profiles = AccountProfile::withTrashed()
                ->where('created_by', $fixture['user_id'])
                ->where('created_by_type', 'tenant')
                ->where('profile_type', 'personal')
                ->get();
            $unfencedProfiles = $profiles->filter(
                static fn (AccountProfile $profile): bool => trim((string) $profile->account_profile_deletion_attempt_id) !== $fixture['user_id'],
            );
            if ($unfencedProfiles->isNotEmpty()) {
                throw new RuntimeException(
                    'Atlas Profile create race committed an unfenced Profile: '
                    .$unfencedProfiles->pluck('id')->implode(','),
                );
            }

            return [
                'concurrency' => $concurrency,
                'create_successes' => count(array_filter(
                    $mutationResults,
                    static fn (array $result): bool => (bool) ($result['ok'] ?? false),
                )),
                'create_conflicts' => count(array_filter(
                    $mutationResults,
                    static fn (array $result): bool => ($result['exception'] ?? '')
                        === 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
                )),
                'fenced_profiles' => $profiles->count(),
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /**
     * Proves that every ordinary lifecycle entrypoint rejects after the current
     * account deletion capture owns the Profile and Account gates.
     *
     * @return array<string, mixed>
     */
    public function runCapturedLifecycleMutationProbe(
        string $tenantId,
        string $databaseName,
        int $concurrency,
        string $operation,
    ): array {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedLifecycleFixture($operation);
            $attempt = app(CurrentTenantAccountDeletionAttemptService::class)->captureOrResume($fixture['user_id']);
            if (($attempt['phase'] ?? '') !== 'captured_and_fenced') {
                throw new RuntimeException("Atlas {$operation} probe did not capture the deletion attempt.");
            }

            $startAt = microtime(true) + 0.5;
            $workers = [];
            foreach (range(1, $concurrency) as $index) {
                $workers[] = $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: $operation,
                    payload: [
                        'profile_id' => $fixture['source_profile_id'],
                        'account_id' => $fixture['source_account_id'],
                        'command_id' => "u07a-atlas-{$operation}-{$concurrency}-{$index}-".bin2hex(random_bytes(4)),
                        'base_url' => 'http://u07a-probe.test',
                    ],
                    startAt: $startAt,
                );
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = array_map($this->waitForWorker(...), $workers);

            $unexpected = array_values(array_filter(
                $results,
                static fn (array $result): bool => (bool) ($result['ok'] ?? false)
                    || ($result['exception'] ?? '') !== 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
            ));
            if ($unexpected !== []) {
                throw new RuntimeException(
                    "Atlas {$operation} probe did not reject every captured lifecycle mutation: "
                    .json_encode($unexpected, JSON_THROW_ON_ERROR),
                );
            }

            $source = AccountProfile::withTrashed()->findOrFail($fixture['source_profile_id']);
            if (trim((string) $source->account_profile_deletion_attempt_id) !== $fixture['user_id']) {
                throw new RuntimeException("Atlas {$operation} probe left the captured Profile unfenced.");
            }
            $account = Account::query()->findOrFail($fixture['source_account_id']);
            if (trim((string) ($account->account_profile_deletion_gate['attempt_id'] ?? '')) !== $fixture['user_id']) {
                throw new RuntimeException("Atlas {$operation} probe lost the Account deletion gate.");
            }

            return [
                'operation' => $operation,
                'concurrency' => $concurrency,
                'conflicts' => count($results),
                'source_fenced' => true,
                'account_gated' => true,
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function runNestedRelationRaceProbe(string $tenantId, string $databaseName, int $concurrency): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedNestedRelationFixture();
            $startAt = microtime(true) + 0.5;
            $cascadeCommandId = "u07a-atlas-nested-cascade-{$concurrency}-".bin2hex(random_bytes(4));
            $workers = [
                $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'cascade_soft_delete',
                    payload: [
                        'account_id' => $fixture['target_account_id'],
                        'command_id' => $cascadeCommandId,
                    ],
                    startAt: $startAt,
                ),
            ];
            foreach (range(1, $concurrency) as $index) {
                $workers[] = $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'admit_nested',
                    payload: [
                        'parent_profile_id' => $fixture['parent_profile_id'],
                        'source_profile_ids' => [
                            $fixture['target_profile_id'],
                            $fixture['surviving_profile_id'],
                        ],
                        'worker_id' => (string) $index,
                        'command_id' => "u07a-atlas-nested-race-{$concurrency}-{$index}-".bin2hex(random_bytes(4)),
                    ],
                    startAt: $startAt,
                );
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = array_map($this->waitForWorker(...), $workers);

            $cascadeResult = $results[0];
            if (! ($cascadeResult['ok'] ?? false)
                && ($cascadeResult['exception'] ?? '') !== 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException') {
                throw new RuntimeException(
                    'Atlas nested relation cascade returned an unexpected result: '
                    .json_encode($cascadeResult, JSON_THROW_ON_ERROR),
                );
            }
            $relationResults = array_slice($results, 1);
            $unexpected = array_values(array_filter(
                $relationResults,
                static fn (array $result): bool => ! ($result['ok'] ?? false)
                    && ! in_array((string) ($result['exception'] ?? ''), [
                        'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
                        'Illuminate\\Validation\\ValidationException',
                    ], true),
            ));
            if ($unexpected !== []) {
                throw new RuntimeException(
                    'Atlas nested relation race returned an unexpected result: '
                    .json_encode($unexpected, JSON_THROW_ON_ERROR),
                );
            }

            $initialCascadeSucceeded = (bool) ($cascadeResult['ok'] ?? false);
            $cleanupCommandId = $cascadeCommandId;
            $cascadeRetried = false;
            if (! $initialCascadeSucceeded) {
                $cleanupCommandId = "{$cascadeCommandId}:retry";
                app(\App\Application\Accounts\AccountManagementService::class)->delete(
                    Account::query()->findOrFail($fixture['target_account_id']),
                    commandId: $cleanupCommandId,
                );
                $cascadeRetried = true;
            }

            $target = AccountProfile::withTrashed()->findOrFail($fixture['target_profile_id']);
            $parent = AccountProfile::withTrashed()->findOrFail($fixture['parent_profile_id']);
            if ($target->deleted_at === null) {
                throw new RuntimeException('Atlas nested relation cascade did not delete its target Profile.');
            }
            foreach ((array) ($parent->nested_profile_groups ?? []) as $group) {
                foreach ((array) ($group['account_profile_ids'] ?? []) as $profileId) {
                    if ((string) $profileId === $fixture['target_profile_id']) {
                        throw new RuntimeException('Atlas nested relation cascade left a committed reference to its deleted target.');
                    }
                }
            }
            if (($parent->nested_profile_groups[0]['account_profile_ids'] ?? []) !== [$fixture['surviving_profile_id']]) {
                throw new RuntimeException('Atlas nested relation cascade did not preserve its surviving nested target.');
            }
            $receipt = DB::connection((string) config('multitenancy.tenant_database_connection_name'))
                ->getDatabase()
                ->selectCollection('account_profile_command_receipts')
                ->findOne(['_id' => "{$cleanupCommandId}:reference-cleanup:".$fixture['parent_profile_id']]);
            if ($receipt === null) {
                throw new RuntimeException('Atlas nested relation cascade did not record the parent cleanup receipt.');
            }

            return [
                'concurrency' => $concurrency,
                'cascade_success' => $initialCascadeSucceeded,
                'cascade_conflict' => ! $initialCascadeSucceeded,
                'cascade_retry_success' => $cascadeRetried,
                'relation_successes' => count(array_filter(
                    $relationResults,
                    static fn (array $result): bool => (bool) ($result['ok'] ?? false),
                )),
                'relation_conflicts' => count(array_filter(
                    $relationResults,
                    static fn (array $result): bool => ($result['exception'] ?? '')
                        === 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
                )),
                'relation_validation_failures' => count(array_filter(
                    $relationResults,
                    static fn (array $result): bool => ($result['exception'] ?? '')
                        === 'Illuminate\\Validation\\ValidationException',
                )),
                'parent_references_cleared_after_cascade' => true,
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    public function runConcurrentCaptureProbe(string $tenantId, string $databaseName, int $concurrency): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedCaptureFixture($concurrency);
            $startAt = microtime(true) + 0.5;
            $workers = [];
            foreach (range(1, $concurrency) as $index) {
                $workers[] = $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'capture',
                    payload: ['user_id' => $fixture['user_id'], 'worker_id' => (string) $index],
                    startAt: $startAt,
                );
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = array_map($this->waitForWorker(...), $workers);

            $successful = array_values(array_filter(
                $results,
                static fn (array $result): bool => (bool) ($result['ok'] ?? false),
            ));
            $conflicts = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['exception'] ?? '') === 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
            ));
            if (count($successful) !== 1 || count($conflicts) !== $concurrency - 1) {
                throw new RuntimeException(
                    'Atlas Account-gate capture did not converge to one claimed attempt: '
                    .json_encode($results, JSON_THROW_ON_ERROR),
                );
            }

            $database = DB::connection((string) config('multitenancy.tenant_database_connection_name'))->getDatabase();
            $attempt = $database
                ->selectCollection('account_profile_deletion_attempts')
                ->findOne(['_id' => $fixture['user_id']]);
            $attempt = $attempt instanceof BSONDocument ? $attempt->getArrayCopy() : $attempt;
            if (! is_array($attempt)
                || (string) ($attempt['phase'] ?? '') !== 'captured_and_fenced'
                || count((array) ($attempt['account_descriptors'] ?? [])) !== $concurrency) {
                throw new RuntimeException('Atlas Account-gate capture did not persist its complete frozen attempt.');
            }

            $profileCount = AccountProfile::query()
                ->where('created_by', $fixture['user_id'])
                ->where('account_profile_deletion_attempt_id', $fixture['user_id'])
                ->where('lifecycle_fence_revision', 1)
                ->count();
            $accountCount = Account::query()
                ->where('created_by', $fixture['user_id'])
                ->where('account_profile_deletion_gate.attempt_id', $fixture['user_id'])
                ->where('account_profile_deletion_gate.attempt_generation', 1)
                ->count();
            if ($profileCount !== $concurrency || $accountCount !== $concurrency) {
                throw new RuntimeException('Atlas Account-gate capture left an incomplete Profile or Account fence set.');
            }

            return [
                'concurrency' => $concurrency,
                'successful_workers' => count($successful),
                'conflicted_workers' => count($conflicts),
                'fenced_profiles' => $profileCount,
                'gated_accounts' => $accountCount,
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    public function runDispatcherClaimProbe(string $tenantId, string $databaseName, int $concurrency): array
    {
        $this->assertConfiguredAtlas();
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            $fixture = $this->seedBurstFixture($concurrency);
            $commandId = "u07a-atlas-dispatch-{$concurrency}-".bin2hex(random_bytes(4));
            app(AccountProfileManagementService::class)->update(
                AccountProfile::query()->findOrFail($fixture['parent_profile_id']),
                ['display_name' => "U07A Atlas Dispatcher {$concurrency}"],
                commandId: $commandId,
                dispatchOutboxImmediately: false,
            );
            $database = DB::connection((string) config('multitenancy.tenant_database_connection_name'))->getDatabase();
            $event = $database
                ->selectCollection('account_profile_outbox')
                ->findOne(['command_id' => $commandId]);
            if ($event === null) {
                throw new RuntimeException('Atlas dispatcher claim probe did not create an outbox event.');
            }

            $startAt = microtime(true) + 0.5;
            $workers = [];
            foreach (range(1, $concurrency) as $index) {
                $workers[] = $this->worker(
                    tenantId: $tenantId,
                    databaseName: $databaseName,
                    action: 'dispatch_event',
                    payload: ['event_id' => (string) $event['_id'], 'worker_id' => (string) $index],
                    startAt: $startAt,
                );
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = array_map($this->waitForWorker(...), $workers);
            if (array_filter($results, static fn (array $result): bool => ! ($result['ok'] ?? false)) !== []) {
                throw new RuntimeException('Atlas dispatcher claim worker did not complete successfully.');
            }

            $delivered = count(array_filter(
                $results,
                static fn (array $result): bool => (bool) ($result['delivered'] ?? false),
            ));
            $completed = $database
                ->selectCollection('account_profile_outbox')
                ->findOne(['_id' => $event['_id']]);
            if ($delivered !== 1
                || (string) ($completed['delivery_state'] ?? '') !== 'completed'
                || (int) ($completed['delivery_attempts'] ?? 0) !== 1) {
                throw new RuntimeException('Atlas dispatcher claim did not converge to one completed delivery.');
            }

            return [
                'concurrency' => $concurrency,
                'delivered_workers' => $delivered,
                'delivery_attempts' => (int) $completed['delivery_attempts'],
                'delivery_state' => (string) $completed['delivery_state'],
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    public function cleanup(string $tenantId, string $databaseName): void
    {
        $this->assertConfiguredAtlas();
        $connection = (string) config('multitenancy.tenant_database_connection_name');
        $tenant = $this->probeTenant(new ObjectId($tenantId), $databaseName);
        $tenant->makeCurrent();

        try {
            DB::connection($connection)->getDatabase()->drop();
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    private function runBurst(ObjectId $tenantId, string $databaseName, int $concurrency): array
    {
        $fixture = $this->seedBurstFixture($concurrency);
        $startAt = microtime(true) + 0.5;
        $processes = [
            $this->worker(
                tenantId: (string) $tenantId,
                databaseName: $databaseName,
                action: 'capture',
                payload: ['user_id' => $fixture['user_id']],
                startAt: $startAt,
            ),
        ];
        foreach (range(1, $concurrency) as $index) {
            $processes[] = $this->worker(
                tenantId: (string) $tenantId,
                databaseName: $databaseName,
                action: 'admit_relation',
                payload: [
                    'parent_profile_id' => $fixture['parent_profile_id'],
                    'source_profile_id' => $fixture['source_profile_id'],
                    'command_id' => "u07a-atlas-{$concurrency}-{$index}-".bin2hex(random_bytes(4)),
                ],
                startAt: $startAt,
            );
        }

        foreach ($processes as $process) {
            $process->start();
        }
        $results = array_map($this->waitForWorker(...), $processes);

        $attempt = DB::connection((string) config('multitenancy.tenant_database_connection_name'))
            ->getDatabase()
            ->selectCollection('account_profile_deletion_attempts')
            ->findOne(['_id' => $fixture['user_id']]);
        $attempt = $attempt instanceof BSONDocument ? $attempt->getArrayCopy() : $attempt;
        if (! is_array($attempt)) {
            throw new RuntimeException('Atlas capture worker did not persist a deletion attempt.');
        }
        app(AccountProfileReferenceCleanupService::class)->cleanSurvivingReferences(
            $fixture['user_id'],
            [$fixture['source_profile_id']],
        );

        $source = AccountProfile::withTrashed()->findOrFail($fixture['source_profile_id']);
        $parent = AccountProfile::withTrashed()->findOrFail($fixture['parent_profile_id']);
        $relationResults = array_slice($results, 1);
        $invalidRelationResults = array_values(array_filter(
            $relationResults,
            static fn (array $result): bool => ! ($result['ok'] ?? false)
                && ! in_array((string) ($result['exception'] ?? ''), [
                    'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
                ], true),
        ));
        if ($invalidRelationResults !== []) {
            throw new RuntimeException('Atlas relation-admission worker returned an unexpected result.');
        }
        if (trim((string) ($source->account_profile_deletion_attempt_id ?? '')) !== $fixture['user_id']) {
            throw new RuntimeException('Atlas capture did not fence the referenced personal profile.');
        }
        if (trim((string) ($parent->contact_source_account_profile_id ?? '')) !== '') {
            throw new RuntimeException('Atlas cleanup left a late contact reference after capture.');
        }

        return [
            'concurrency' => $concurrency,
            'capture_worker' => $results[0],
            'relation_successes' => count(array_filter(
                $relationResults,
                static fn (array $result): bool => (bool) ($result['ok'] ?? false),
            )),
            'relation_conflicts' => count(array_filter(
                $relationResults,
                static fn (array $result): bool => (string) ($result['exception'] ?? '')
                    === 'App\\Exceptions\\FoundationControlPlane\\ConcurrencyConflictException',
            )),
            'attempt_phase' => (string) ($attempt['phase'] ?? ''),
            'source_fenced' => true,
            'parent_reference_cleared' => true,
        ];
    }

    /** @return array{user_id:string,source_account_id:string,candidate_account_ids:list<string>,source_profile_id:string,parent_profile_id:string} */
    private function seedBurstFixture(int $concurrency): array
    {
        $suffix = "{$concurrency}-".bin2hex(random_bytes(4));
        $user = AccountUser::query()->create([
            'identity_state' => 'registered',
            'name' => "U07A Atlas User {$suffix}",
            'credentials' => [],
        ]);
        $userId = (string) $user->getKey();
        $sourceAccount = Account::query()->create([
            'name' => "U07A Atlas Source Account {$suffix}",
            'slug' => "u07a-atlas-source-account-{$suffix}",
            'document' => ['type' => 'cpf', 'number' => "U07A-SOURCE-{$suffix}"],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $parentAccount = Account::query()->create([
            'name' => "U07A Atlas Parent Account {$suffix}",
            'slug' => "u07a-atlas-parent-account-{$suffix}",
            'document' => ['type' => 'cpf', 'number' => "U07A-PARENT-{$suffix}"],
            'ownership_state' => 'unmanaged',
        ]);
        $candidateAccountIds = [];
        foreach (range(1, $concurrency) as $index) {
            $candidateAccount = Account::query()->create([
                'name' => "U07A Atlas Candidate Account {$suffix}-{$index}",
                'slug' => "u07a-atlas-candidate-account-{$suffix}-{$index}",
                'document' => ['type' => 'cpf', 'number' => "U07A-CANDIDATE-{$suffix}-{$index}"],
                'ownership_state' => 'unmanaged',
                'created_by' => $userId,
                'created_by_type' => 'tenant',
            ]);
            $candidateAccountIds[] = (string) $candidateAccount->getKey();
        }
        $source = AccountProfile::query()->create([
            'account_id' => (string) $sourceAccount->getKey(),
            'profile_type' => 'personal',
            'display_name' => "U07A Atlas Source {$suffix}",
            'slug' => "u07a-atlas-source-{$suffix}",
            'created_by' => $userId,
            'created_by_type' => 'tenant',
            'contact_mode' => 'own',
            'contact_channels' => [],
            'is_active' => true,
            'aggregate_revision' => 1,
            'lifecycle_fence_revision' => 0,
        ]);
        $parent = AccountProfile::query()->create([
            'account_id' => (string) $parentAccount->getKey(),
            'profile_type' => 'venue',
            'display_name' => "U07A Atlas Parent {$suffix}",
            'slug' => "u07a-atlas-parent-{$suffix}",
            'contact_mode' => 'own',
            'contact_channels' => [],
            'is_active' => true,
            'aggregate_revision' => 1,
            'lifecycle_fence_revision' => 0,
        ]);

        return [
            'user_id' => $userId,
            'source_account_id' => (string) $sourceAccount->getKey(),
            'candidate_account_ids' => $candidateAccountIds,
            'source_profile_id' => (string) $source->getKey(),
            'parent_profile_id' => (string) $parent->getKey(),
        ];
    }

    /**
     * @return array{user_id:string,source_account_id:string,source_profile_id:string}
     */
    private function seedLifecycleFixture(string $operation): array
    {
        if (! in_array($operation, [
            'replace_gallery',
            'restore_profile',
            'soft_delete_profile',
            'force_delete_profile',
            'cascade_soft_delete',
        ], true)) {
            throw new RuntimeException("Unsupported U07A lifecycle probe operation: {$operation}");
        }

        $fixture = $this->seedBurstFixture(1);
        $source = AccountProfile::query()->findOrFail($fixture['source_profile_id']);
        $source->gallery_groups = [[
            'group_id' => 'u07a-seed',
            'subtitle' => 'U07A seed gallery',
            'items' => [[
                'item_id' => 'seed-item',
                'description' => null,
                'order' => 0,
                'media_path' => '/api/v1/media/account-profiles/seed/gallery/seed-item',
                'version' => '1',
            ]],
        ]];
        $source->save();

        if ($operation === 'restore_profile') {
            // The ordinary delete path correctly rejects a live Account's
            // sole Profile; the probe needs a restorable seeded state only.
            $source->delete();
        }

        return [
            'user_id' => $fixture['user_id'],
            'source_account_id' => $fixture['source_account_id'],
            'source_profile_id' => $fixture['source_profile_id'],
        ];
    }

    /** @return array{target_account_id:string,target_profile_id:string,surviving_profile_id:string,parent_profile_id:string} */
    private function seedNestedRelationFixture(): array
    {
        $suffix = 'nested-'.bin2hex(random_bytes(4));
        $targetAccount = Account::query()->create([
            'name' => "U07A Atlas Nested Target Account {$suffix}",
            'slug' => "u07a-atlas-nested-target-account-{$suffix}",
            'document' => ['type' => 'cpf', 'number' => "U07A-NESTED-TARGET-{$suffix}"],
            'ownership_state' => 'unmanaged',
        ]);
        $parentAccount = Account::query()->create([
            'name' => "U07A Atlas Nested Parent Account {$suffix}",
            'slug' => "u07a-atlas-nested-parent-account-{$suffix}",
            'document' => ['type' => 'cpf', 'number' => "U07A-NESTED-PARENT-{$suffix}"],
            'ownership_state' => 'unmanaged',
        ]);
        $survivingAccount = Account::query()->create([
            'name' => "U07A Atlas Nested Surviving Account {$suffix}",
            'slug' => "u07a-atlas-nested-surviving-account-{$suffix}",
            'document' => ['type' => 'cpf', 'number' => "U07A-NESTED-SURVIVING-{$suffix}"],
            'ownership_state' => 'unmanaged',
        ]);
        $target = AccountProfile::query()->create([
            'account_id' => (string) $targetAccount->getKey(),
            'profile_type' => 'venue',
            'display_name' => "U07A Atlas Nested Target {$suffix}",
            'slug' => "u07a-atlas-nested-target-{$suffix}",
            'contact_mode' => 'own',
            'contact_channels' => [],
            'is_active' => true,
            'aggregate_revision' => 1,
            'lifecycle_fence_revision' => 0,
        ]);
        $parent = AccountProfile::query()->create([
            'account_id' => (string) $parentAccount->getKey(),
            'profile_type' => 'venue',
            'display_name' => "U07A Atlas Nested Parent {$suffix}",
            'slug' => "u07a-atlas-nested-parent-{$suffix}",
            'contact_mode' => 'own',
            'contact_channels' => [],
            'is_active' => true,
            'aggregate_revision' => 1,
            'lifecycle_fence_revision' => 0,
        ]);
        $surviving = AccountProfile::query()->create([
            'account_id' => (string) $survivingAccount->getKey(),
            'profile_type' => 'venue',
            'display_name' => "U07A Atlas Nested Surviving {$suffix}",
            'slug' => "u07a-atlas-nested-surviving-{$suffix}",
            'contact_mode' => 'own',
            'contact_channels' => [],
            'is_active' => true,
            'aggregate_revision' => 1,
            'lifecycle_fence_revision' => 0,
        ]);
        app(AccountProfileManagementService::class)->update(
            $parent,
            [
                'nested_profile_groups' => [[
                    'id' => 'u07a-nested-targets',
                    'label' => 'Persisted Nested Target',
                    'account_profile_ids' => [(string) $target->getKey(), (string) $surviving->getKey()],
                ]],
            ],
            commandId: 'u07a-atlas-nested-seed-'.bin2hex(random_bytes(4)),
            dispatchOutboxImmediately: false,
        );

        return [
            'target_account_id' => (string) $targetAccount->getKey(),
            'target_profile_id' => (string) $target->getKey(),
            'surviving_profile_id' => (string) $surviving->getKey(),
            'parent_profile_id' => (string) $parent->getKey(),
        ];
    }

    /** @return array{user_id:string} */
    private function seedCaptureFixture(int $accountCount): array
    {
        $suffix = "capture-{$accountCount}-".bin2hex(random_bytes(4));
        $user = AccountUser::query()->create([
            'identity_state' => 'registered',
            'name' => "U07A Atlas Capture User {$suffix}",
            'credentials' => [],
        ]);
        $userId = (string) $user->getKey();

        foreach (range(1, $accountCount) as $index) {
            $account = Account::query()->create([
                'name' => "U07A Atlas Capture Account {$suffix}-{$index}",
                'slug' => "u07a-atlas-capture-account-{$suffix}-{$index}",
                'document' => ['type' => 'cpf', 'number' => "U07A-CAPTURE-{$suffix}-{$index}"],
                'ownership_state' => 'unmanaged',
                'created_by' => $userId,
                'created_by_type' => 'tenant',
            ]);
            AccountProfile::query()->create([
                'account_id' => (string) $account->getKey(),
                'profile_type' => 'personal',
                'display_name' => "U07A Atlas Capture {$suffix}-{$index}",
                'slug' => "u07a-atlas-capture-profile-{$suffix}-{$index}",
                'created_by' => $userId,
                'created_by_type' => 'tenant',
                'contact_mode' => 'own',
                'contact_channels' => [],
                'is_active' => true,
                'aggregate_revision' => 1,
                'lifecycle_fence_revision' => 0,
            ]);
        }

        return ['user_id' => $userId];
    }

    private function seedProfileTypes(): void
    {
        foreach ([
            [
                'type' => 'personal',
                'is_queryable' => false,
                'has_gallery' => true,
                'has_nested_profile_groups' => false,
            ],
            [
                'type' => 'venue',
                'is_queryable' => true,
                'has_gallery' => false,
                'has_nested_profile_groups' => true,
            ],
        ] as $definition) {
            TenantProfileType::query()->updateOrCreate(['type' => $definition['type']], [
                'type' => $definition['type'],
                'label' => ucfirst($definition['type']),
                'labels' => [
                    'singular' => ucfirst($definition['type']),
                    'plural' => ucfirst($definition['type']).'s',
                ],
                'capabilities' => [
                    'is_queryable' => $definition['is_queryable'],
                    'has_contact_channels' => true,
                    'is_poi_enabled' => false,
                    'is_publicly_discoverable' => false,
                    'is_publicly_navigable' => false,
                    'is_favoritable' => false,
                    'has_gallery' => $definition['has_gallery'],
                    'has_nested_profile_groups' => $definition['has_nested_profile_groups'],
                ],
            ]);
        }
    }

    private function probeTenant(ObjectId $tenantId, string $databaseName): Tenant
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            '_id' => $tenantId,
            'name' => 'U07A Atlas Probe',
            'slug' => 'u07a-atlas-probe-'.bin2hex(random_bytes(4)),
            'database' => $databaseName,
        ]);

        return $tenant;
    }

    /** @param array<string, mixed> $payload */
    private function worker(
        string $tenantId,
        string $databaseName,
        string $action,
        array $payload,
        float $startAt,
    ): Process {
        $code = $this->workerCode($tenantId, $databaseName, $action, $payload, $startAt);

        return new Process(
            [PHP_BINARY, 'artisan', 'tinker', '--execute', $code],
            base_path(),
            [self::PROBE_ENVIRONMENT_FLAG => '1'],
            null,
            60,
        );
    }

    /** @param array<string, mixed> $payload */
    private function workerCode(
        string $tenantId,
        string $databaseName,
        string $action,
        array $payload,
        float $startAt,
    ): string {
        $tenantIdLiteral = var_export($tenantId, true);
        $databaseLiteral = var_export($databaseName, true);
        $payloadLiteral = var_export($payload, true);
        $startAtLiteral = var_export($startAt, true);
        $actionLiteral = var_export($action, true);

        return <<<PHP
\$started = microtime(true);
try {
    \$tenant = new App\\Models\\Landlord\\Tenant;
    \$tenant->forceFill([
        '_id' => new MongoDB\\BSON\\ObjectId({$tenantIdLiteral}),
        'name' => 'U07A Atlas Probe',
        'slug' => 'u07a-atlas-probe',
        'database' => {$databaseLiteral},
    ]);
    \$tenant->makeCurrent();
    while (microtime(true) < {$startAtLiteral}) {
        usleep(1000);
    }
    \$payload = {$payloadLiteral};
    if ({$actionLiteral} === 'capture') {
        \$attempt = app('App\\Application\\Profiles\\CurrentTenantAccountDeletionAttemptService')->captureOrResume(\$payload['user_id']);
        \$result = ['phase' => (string) (\$attempt['phase'] ?? '')];
    } elseif ({$actionLiteral} === 'create_profile') {
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->create(
            [
                'account_id' => \$payload['account_id'],
                'profile_type' => 'personal',
                'display_name' => 'U07A Atlas Create ' . \$payload['worker_id'],
                'slug' => 'u07a-atlas-create-' . \$payload['worker_id'] . '-' . \$payload['slug_suffix'],
                'created_by' => \$payload['user_id'],
                'created_by_type' => 'tenant',
                'contact_mode' => 'own',
                'contact_channels' => [],
                'is_active' => true,
            ],
            commandId: \$payload['command_id'],
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'update_profile') {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::query()->findOrFail(\$payload['profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->update(
            \$profile,
            ['display_name' => 'U07A Atlas Update ' . \$payload['worker_id']],
            commandId: \$payload['command_id'],
            dispatchOutboxImmediately: false,
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'replace_gallery') {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::query()->findOrFail(\$payload['profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileGalleryService')->replace(
            \$profile,
            [],
            \$payload['base_url'],
            commandId: \$payload['command_id'],
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'restore_profile') {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::withTrashed()->findOrFail(\$payload['profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->restore(
            \$profile,
            commandId: \$payload['command_id'],
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'soft_delete_profile') {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::query()->findOrFail(\$payload['profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->delete(
            \$profile,
            commandId: \$payload['command_id'],
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'force_delete_profile') {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::withTrashed()->findOrFail(\$payload['profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->forceDelete(
            \$profile,
            commandId: \$payload['command_id'],
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'cascade_soft_delete') {
        \$account = app('App\\Models\\Tenants\\Account')::query()->findOrFail(\$payload['account_id']);
        app('App\\Application\\Accounts\\AccountManagementService')->delete(
            \$account,
            commandId: \$payload['command_id'],
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'admit_nested') {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::query()->findOrFail(\$payload['parent_profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->update(
            \$profile,
            [
                'nested_profile_groups' => [[
                    'id' => 'u07a-nested-targets',
                    'label' => 'U07A Nested Targets ' . \$payload['worker_id'],
                    'account_profile_ids' => \$payload['source_profile_ids'],
                ]],
            ],
            commandId: \$payload['command_id'],
            dispatchOutboxImmediately: false,
        );
        \$result = [];
    } elseif ({$actionLiteral} === 'dispatch_event') {
        \$result = ['delivered' => app('App\\Application\\AccountProfiles\\AccountProfileOutboxDispatcher')->dispatchEvent(\$payload['event_id'])];
    } else {
        \$profile = app('App\\Models\\Tenants\\AccountProfile')::query()->findOrFail(\$payload['parent_profile_id']);
        app('App\\Application\\AccountProfiles\\AccountProfileManagementService')->update(
            \$profile,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => \$payload['source_profile_id'],
            ],
            commandId: \$payload['command_id'],
            dispatchOutboxImmediately: false,
        );
        \$result = [];
    }
    echo json_encode([
        'ok' => true,
        'duration_ms' => (int) round((microtime(true) - \$started) * 1000),
        ...\$result,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable \$exception) {
    echo json_encode([
        'ok' => false,
        'exception' => \$exception::class,
        'code' => (int) \$exception->getCode(),
        'message' => \$exception->getMessage(),
        'trace' => array_map(
            static fn (array \$frame): string => (string) (\$frame['file'] ?? '') . ':' . (string) (\$frame['line'] ?? 0),
            array_slice(\$exception->getTrace(), 0, 5),
        ),
        'duration_ms' => (int) round((microtime(true) - \$started) * 1000),
    ], JSON_THROW_ON_ERROR);
} finally {
    if (isset(\$tenant)) {
        \$tenant->forgetCurrent();
    }
}
PHP;
    }

    /** @return array<string, mixed> */
    private function waitForWorker(Process $process): array
    {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Atlas probe worker did not complete: '.$process->getErrorOutput());
        }

        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R+/', $process->getOutput()) ?: [],
        )));
        $json = end($lines);
        if (! is_string($json) || $json === '') {
            throw new RuntimeException('Atlas probe worker did not produce a result.');
        }

        /** @var array<string, mixed> $result */
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    private function assertConfiguredAtlas(): void
    {
        if (getenv(self::PROBE_ENVIRONMENT_FLAG) !== '1') {
            throw new RuntimeException('Set BELLUGA_ATLAS_CONCURRENCY_PROBE=1 to run the destructive ephemeral Atlas probe.');
        }

        $dsn = (string) config('database.connections.tenant.dsn');
        if (! str_starts_with($dsn, 'mongodb+srv://')) {
            throw new RuntimeException('The U07A Atlas probe only runs against an explicitly configured mongodb+srv tenant connection.');
        }
    }

    /** @return list<int> */
    private function requestedConcurrencyLevels(): array
    {
        $configured = getenv('BELLUGA_ATLAS_CONCURRENCY_LEVELS');
        if (! is_string($configured) || trim($configured) === '') {
            return [5, 10, 20];
        }

        $levels = array_values(array_unique(array_filter(
            array_map(static fn (string $level): int => (int) trim($level), explode(',', $configured)),
            static fn (int $level): bool => in_array($level, [5, 10, 20], true),
        )));
        if ($levels === []) {
            throw new RuntimeException('BELLUGA_ATLAS_CONCURRENCY_LEVELS must contain one or more of 5, 10, 20.');
        }

        sort($levels);

        return $levels;
    }
}
