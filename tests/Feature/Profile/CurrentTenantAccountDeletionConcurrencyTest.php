<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Application\Profiles\CurrentTenantAccountDeletionAccountGuard;
use App\Application\Profiles\CurrentTenantAccountDeletionAttemptService;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Models\Tenants\TenantSettings;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class CurrentTenantAccountDeletionConcurrencyTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected static bool $bootstrapped = false;

    private Tenant $tenantModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantModel = Tenant::query()->firstOrFail();
        $this->tenantModel->makeCurrent();
    }

    private function initializeSystem(): void
    {
        $this->ensureSystemInitialized();
    }

    public function test_deleted_phone_can_verify_into_a_clean_unrelated_identity_after_direct_delete(): void
    {
        $phone = '+5527999990304';
        $deletedUser = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Deleted Identity',
            'phones' => [$phone],
            'credentials' => [],
        ]);
        $deletedUserId = (string) $deletedUser->_id;

        Sanctum::actingAs($deletedUser, ['*']);
        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $this->configurePhoneOtpReviewAccess($phone);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => 'u05-clean-reregistration',
        ])->assertStatus(202);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => $phone,
            'code' => '123456',
            'device_name' => 'u05-clean-reregistration',
        ])->assertOk();

        $this->assertNotSame($deletedUserId, (string) $verify->json('data.user_id'));
        $this->assertNull(AccountUser::withTrashed()->find($deletedUserId));
        $this->assertSame(PhoneOtpChallenge::STATUS_VERIFIED, (string) PhoneOtpChallenge::query()
            ->findOrFail($challenge->json('data.challenge_id'))
            ->status);
    }

    public function test_delete_first_blocks_phone_otp_verify_until_direct_delete_finishes(): void
    {
        $phone = '+5527999990314';
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Delete First Target',
            'phones' => [$phone],
            'credentials' => [],
        ]);
        $this->configurePhoneOtpReviewAccess($phone);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => 'delete-first-seed',
        ])->assertStatus(202);

        $results = $this->runLeaderWithFollowers(
            $this->deleteProcess((string) $target->_id, [
                'BELLUGA_TEST_CURRENT_ACCOUNT_DELETE_BEFORE_MUTATION_SLEEP_MS' => '1200',
            ]),
            [
                $this->verifyProcess(
                    (string) $challenge->json('data.challenge_id'),
                    $phone,
                    '123456',
                    'delete-first-verify',
                ),
            ],
            200,
        );

        $deleteResult = $results[0];
        $verifyResult = $results[1];

        $this->assertTrue($deleteResult['ok'], json_encode($results, JSON_PRETTY_PRINT));
        $this->assertFalse($verifyResult['ok'], json_encode($results, JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(900, (int) ($verifyResult['duration_ms'] ?? 0), json_encode($results, JSON_PRETTY_PRINT));
        $this->assertSame('The OTP challenge could not be verified.', $verifyResult['errors']['code'][0] ?? null);
        $this->assertNull(AccountUser::withTrashed()->find((string) $target->_id));
    }

    public function test_verify_first_blocks_direct_delete_until_phone_otp_verify_finishes(): void
    {
        $phone = '+5527999990315';
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Verify First Target',
            'phones' => [$phone],
            'credentials' => [],
        ]);
        $this->configurePhoneOtpReviewAccess($phone);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => 'verify-first-seed',
        ])->assertStatus(202);

        $results = $this->runLeaderWithFollowers(
            $this->verifyProcess(
                (string) $challenge->json('data.challenge_id'),
                $phone,
                '123456',
                'verify-first-verify',
                ['BELLUGA_TEST_PHONE_OTP_VERIFY_BEFORE_MUTATION_SLEEP_MS' => '1200'],
            ),
            [
                $this->deleteProcess((string) $target->_id),
            ],
            200,
        );

        $verifyResult = $results[0];
        $deleteResult = $results[1];

        $this->assertTrue($verifyResult['ok'], json_encode($results, JSON_PRETTY_PRINT));
        $this->assertTrue($deleteResult['ok'], json_encode($results, JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(900, (int) ($deleteResult['duration_ms'] ?? 0), json_encode($results, JSON_PRETTY_PRINT));
        $this->assertNull(AccountUser::withTrashed()->find((string) $target->_id));
    }

    public function test_lease_loss_aborts_stale_phone_otp_verify_before_it_can_mutate_the_identity_slice(): void
    {
        $phone = '+5527999990316';
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Lease Loss Target',
            'phones' => [$phone],
            'credentials' => [],
        ]);
        $this->configurePhoneOtpReviewAccess($phone);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => 'lease-loss-seed',
        ])->assertStatus(202);

        $results = $this->runLeaderWithFollowers(
            $this->verifyProcess(
                (string) $challenge->json('data.challenge_id'),
                $phone,
                '123456',
                'lease-loss-verify',
                [
                    'BELLUGA_TEST_PHONE_IDENTITY_LEASE_TTL_SECONDS' => '1',
                    'BELLUGA_TEST_PHONE_OTP_VERIFY_BEFORE_MUTATION_SLEEP_MS' => '1500',
                ],
            ),
            [
                $this->deleteProcess((string) $target->_id, [
                    'BELLUGA_TEST_PHONE_IDENTITY_LEASE_TTL_SECONDS' => '1',
                ]),
            ],
            200,
        );

        $verifyResult = $results[0];
        $deleteResult = $results[1];

        $this->assertFalse($verifyResult['ok'], json_encode($results, JSON_PRETTY_PRINT));
        $this->assertSame(ConcurrencyConflictException::class, $verifyResult['exception'] ?? null);
        $this->assertTrue($deleteResult['ok'], json_encode($results, JSON_PRETTY_PRINT));
        $this->assertNull(AccountUser::withTrashed()->find((string) $target->_id));
    }

    #[DataProvider('overlapProfileProvider')]
    public function test_mixed_overlap_profiles_of_five_ten_and_twenty_never_mutate_same_phone_slice_concurrently(int $followers): void
    {
        $phone = sprintf('+55279999904%02d', $followers);
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => "Overlap {$followers} Target",
            'phones' => [$phone],
            'credentials' => [],
        ]);
        $this->configurePhoneOtpReviewAccess($phone);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => "overlap-{$followers}-seed",
        ])->assertStatus(202);

        $processes = [];
        foreach (range(1, $followers) as $index) {
            $processes[] = $this->verifyProcess(
                (string) $challenge->json('data.challenge_id'),
                $phone,
                '123456',
                "overlap-{$followers}-verify-{$index}",
            );
        }

        $results = $this->runLeaderWithFollowers(
            $this->deleteProcess((string) $target->_id, [
                'BELLUGA_TEST_CURRENT_ACCOUNT_DELETE_BEFORE_MUTATION_SLEEP_MS' => '2000',
            ]),
            $processes,
            800,
        );

        $leadDelete = array_shift($results);
        $this->assertIsArray($leadDelete);
        $this->assertTrue($leadDelete['ok'], json_encode([$leadDelete, ...$results], JSON_PRETTY_PRINT));

        foreach ($results as $result) {
            $this->assertFalse($result['ok'], json_encode($results, JSON_PRETTY_PRINT));
            $this->assertSame('The OTP challenge could not be verified.', $result['errors']['code'][0] ?? null, json_encode($results, JSON_PRETTY_PRINT));
        }

        $this->assertNull(AccountUser::withTrashed()->find((string) $target->_id));
    }

    public function test_stale_personal_account_snapshot_cannot_hard_delete_an_account_that_gained_a_member(): void
    {
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Deletion target',
            'phones' => ['+5527999990311'],
        ]);
        $other = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Concurrent member',
            'phones' => ['+5527999990312'],
        ]);
        $targetId = (string) $target->_id;
        $account = Account::create([
            'name' => 'Stale delete candidate',
            'slug' => 'stale-delete-candidate',
            'document' => ['type' => 'cpf', 'number' => 'STALE-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Deletion target',
            'slug' => 'stale-delete-profile',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);

        $staleProfileIds = [(string) $profile->_id];
        $staleAccountIds = [(string) $account->_id];
        $attempts = app(CurrentTenantAccountDeletionAttemptService::class);
        $attempt = $attempts->captureOrResume($targetId);
        $attempt = $attempts->transition($attempt, 'captured_and_fenced', 'references_cleaned');
        $other->account_roles = [[
            'account_id' => (string) $account->_id,
            'name' => 'Member',
            'slug' => 'member',
            'permissions' => [],
        ]];
        $other->save();

        app(CurrentTenantAccountDeletionAccountGuard::class)->eraseRevalidatedPersonalGraph(
            $targetId,
            $staleProfileIds,
            $staleAccountIds,
            $attempt,
        );

        $this->assertNotNull(Account::query()->find((string) $account->_id));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $profile->_id));
    }

    public function test_captured_personal_profile_cannot_terminalize_after_its_account_changes(): void
    {
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Deletion target profile',
            'phones' => ['+5527999990313'],
        ]);
        $targetId = (string) $target->_id;
        $account = Account::create([
            'name' => 'Stale profile candidate',
            'slug' => 'stale-profile-candidate',
            'document' => ['type' => 'cpf', 'number' => 'STALE-PROFILE-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Deletion target profile',
            'slug' => 'stale-profile-delete-profile',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);

        $attempts = app(CurrentTenantAccountDeletionAttemptService::class);
        $attempt = $attempts->captureOrResume($targetId);
        $attempt = $attempts->transition($attempt, 'captured_and_fenced', 'references_cleaned');
        $profile->account_id = 'concurrently-reassigned-account';
        $profile->save();

        try {
            app(CurrentTenantAccountDeletionAccountGuard::class)->eraseRevalidatedPersonalGraph(
                $targetId,
                [(string) $profile->_id],
                [(string) $account->_id],
                $attempt,
            );
            $this->fail('A captured Profile must not terminalize after its Account changes.');
        } catch (ConcurrencyConflictException) {
            // The frozen descriptor and current target no longer agree.
        }

        $this->assertNotNull(Account::query()->find((string) $account->_id));
        $this->assertNotNull(AccountProfile::withTrashed()->find((string) $profile->_id));
    }

    private function configurePhoneOtpReviewAccess(string $phone): void
    {
        $settings = TenantSettings::current() ?? new TenantSettings;
        $settings->setAttribute('_id', TenantSettings::ROOT_ID);
        $settings->tenant_public_auth = ['enabled_methods' => ['phone_otp']];
        $settings->phone_otp_review_access = [
            'phone_e164' => $phone,
            'code_hash' => app(\App\Application\Auth\PhoneOtpReviewAccessCodeHasher::class)->make('123456'),
        ];
        $settings->outbound_integrations = [
            'otp' => [
                'ttl_minutes' => 30,
                'resend_cooldown_seconds' => 1,
                'max_attempts' => 5,
            ],
        ];
        $settings->save();
    }

    /**
     * @param  list<Process>  $followers
     * @return list<array<string, mixed>>
     */
    private function runLeaderWithFollowers(Process $leader, array $followers, int $followerDelayMilliseconds): array
    {
        $leader->start();
        if ($followerDelayMilliseconds > 0) {
            usleep($followerDelayMilliseconds * 1000);
        }

        foreach ($followers as $follower) {
            $follower->start();
        }

        return $this->collectProcessResults([$leader, ...$followers]);
    }

    /**
     * @param  list<Process>  $processes
     * @return list<array<string, mixed>>
     */
    private function collectProcessResults(array $processes): array
    {
        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $outputLines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $process->getOutput()) ?: [])));
            $jsonLine = end($outputLines);
            $this->assertIsString($jsonLine, $process->getOutput());
            $results[] = json_decode($jsonLine, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function deleteProcess(string $userId, array $env = []): Process
    {
        $tenantSlug = var_export($this->tenantModel->slug, true);
        $userIdValue = var_export($userId, true);

        $code = <<<PHP
\$started = microtime(true);
try {
    \$tenantModel = 'App\\\\Models\\\\Landlord\\\\Tenant';
    \$tenant = \$tenantModel::query()->where('slug', {$tenantSlug})->firstOrFail();
    \$tenant->makeCurrent();
    \$user = app('App\\\\Models\\\\Tenants\\\\AccountUser')::query()->findOrFail({$userIdValue});
    app('App\\\\Application\\\\Profiles\\\\CurrentTenantAccountDeletionService')->delete(\$tenant, \$user);
    echo json_encode([
        'ok' => true,
        'duration_ms' => (int) round((microtime(true) - \$started) * 1000),
    ], JSON_THROW_ON_ERROR);
} catch (\\Throwable \$exception) {
    \$errors = method_exists(\$exception, 'errors') ? \$exception->errors() : null;
    echo json_encode([
        'ok' => false,
        'exception' => \$exception::class,
        'message' => \$exception->getMessage(),
        'errors' => \$errors,
        'duration_ms' => (int) round((microtime(true) - \$started) * 1000),
    ], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), $env, null, 30);
    }

    /**
     * @param  array<string, string>  $env
     */
    private function verifyProcess(
        string $challengeId,
        string $phone,
        string $codeValue,
        string $deviceName,
        array $env = [],
    ): Process {
        $payload = var_export([
            'challenge_id' => $challengeId,
            'phone' => $phone,
            'code' => $codeValue,
            'device_name' => $deviceName,
        ], true);
        $tenantSlug = var_export($this->tenantModel->slug, true);

        $code = <<<PHP
\$started = microtime(true);
try {
    \$tenantModel = 'App\\\\Models\\\\Landlord\\\\Tenant';
    \$tenant = \$tenantModel::query()->where('slug', {$tenantSlug})->firstOrFail();
    \$tenant->makeCurrent();
    \$result = app('App\\\\Application\\\\Auth\\\\TenantPhoneOtpAuthService')->verify(\$tenant, {$payload});
    echo json_encode([
        'ok' => true,
        'user_id' => (string) (\$result->user->_id ?? ''),
        'duration_ms' => (int) round((microtime(true) - \$started) * 1000),
    ], JSON_THROW_ON_ERROR);
} catch (\\Throwable \$exception) {
    \$errors = method_exists(\$exception, 'errors') ? \$exception->errors() : null;
    echo json_encode([
        'ok' => false,
        'exception' => \$exception::class,
        'message' => \$exception->getMessage(),
        'errors' => \$errors,
        'duration_ms' => (int) round((microtime(true) - \$started) * 1000),
    ], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), $env, null, 30);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function overlapProfileProvider(): array
    {
        return [
            'five-followers' => [5],
            'ten-followers' => [10],
            'twenty-followers' => [20],
        ];
    }
}
