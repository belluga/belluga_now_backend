<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\PasswordResetFlowService;
use App\Application\Auth\PasswordResetTokenService;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PasswordResetFlowServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_reset_replays_a_probe_consume_before_rejecting_missing_users(): void
    {
        $tokenService = Mockery::mock(PasswordResetTokenService::class);
        $tokenService->shouldReceive('attemptConsumeForUser')
            ->once()
            ->with(Mockery::type('string'), 'invalid-token', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturn(false);
        $tokenService->shouldReceive('rejectInvalidResetAttempt')
            ->once()
            ->with('invalid-token', 'Password123!', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andThrow(ValidationException::withMessages([
                'reset_token' => 'Invalid token',
            ]));

        $service = new PasswordResetFlowService($tokenService);

        $this->expectException(ValidationException::class);

        $service->reset(
            email: 'missing@example.org',
            token: 'invalid-token',
            password: 'Password123!',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
            user: null,
            userIdResolver: static fn (mixed $user): mixed => $user,
            applyReset: static function (): void {},
        );
    }

    public function test_reset_normalizes_existing_user_wrong_token_failures_through_the_same_rejection_boundary(): void
    {
        $tokenService = Mockery::mock(PasswordResetTokenService::class);
        $tokenService->shouldReceive('attemptConsumeForUser')
            ->once()
            ->with('user-123', 'invalid-token', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturn(false);
        $tokenService->shouldReceive('rejectInvalidResetAttempt')
            ->once()
            ->with('invalid-token', 'Password123!', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andThrow(ValidationException::withMessages([
                'reset_token' => 'Invalid token',
            ]));

        $service = new PasswordResetFlowService($tokenService);

        $this->expectException(ValidationException::class);

        $service->reset(
            email: 'existing@example.org',
            token: 'invalid-token',
            password: 'Password123!',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
            user: (object) ['id' => 'user-123'],
            userIdResolver: static fn (object $user): string => $user->id,
            applyReset: static function (): void {},
        );
    }

    public function test_missing_user_and_wrong_token_paths_share_the_same_invalid_reset_sequence(): void
    {
        $missingTrace = [];
        $wrongTokenTrace = [];

        $missingTokenService = Mockery::mock(PasswordResetTokenService::class);
        $missingTokenService->shouldReceive('attemptConsumeForUser')
            ->once()
            ->with(Mockery::type('string'), 'invalid-token', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturnUsing(function (mixed $userId, string $token, string $broker, ?string $scope) use (&$missingTrace): bool {
                $this->assertIsString($userId);
                $missingTrace[] = ['attemptConsumeForUser', $token, $broker, $scope];

                return false;
            });
        $missingTokenService->shouldReceive('rejectInvalidResetAttempt')
            ->once()
            ->with('invalid-token', 'Password123!', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturnUsing(function (string $token, string $password, string $broker, ?string $scope) use (&$missingTrace): never {
                $missingTrace[] = ['rejectInvalidResetAttempt', $token, $password, $broker, $scope];

                throw ValidationException::withMessages([
                    'reset_token' => 'Invalid token',
                ]);
            });

        $wrongTokenService = Mockery::mock(PasswordResetTokenService::class);
        $wrongTokenService->shouldReceive('attemptConsumeForUser')
            ->once()
            ->with('user-123', 'invalid-token', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturnUsing(function (mixed $userId, string $token, string $broker, ?string $scope) use (&$wrongTokenTrace): bool {
                $this->assertSame('user-123', $userId);
                $wrongTokenTrace[] = ['attemptConsumeForUser', $token, $broker, $scope];

                return false;
            });
        $wrongTokenService->shouldReceive('rejectInvalidResetAttempt')
            ->once()
            ->with('invalid-token', 'Password123!', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturnUsing(function (string $token, string $password, string $broker, ?string $scope) use (&$wrongTokenTrace): never {
                $wrongTokenTrace[] = ['rejectInvalidResetAttempt', $token, $password, $broker, $scope];

                throw ValidationException::withMessages([
                    'reset_token' => 'Invalid token',
                ]);
            });

        $missingService = new PasswordResetFlowService($missingTokenService);
        $wrongTokenServiceFlow = new PasswordResetFlowService($wrongTokenService);

        try {
            $missingService->reset(
                email: 'missing@example.org',
                token: 'invalid-token',
                password: 'Password123!',
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
                scope: 'tenant-a',
                user: null,
                userIdResolver: static fn (mixed $user): mixed => $user,
                applyReset: static function (): void {},
            );
            $this->fail('Expected missing-user invalid reset attempt to be rejected.');
        } catch (ValidationException) {
        }

        try {
            $wrongTokenServiceFlow->reset(
                email: 'existing@example.org',
                token: 'invalid-token',
                password: 'Password123!',
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
                scope: 'tenant-a',
                user: (object) ['id' => 'user-123'],
                userIdResolver: static fn (object $user): string => $user->id,
                applyReset: static function (): void {},
            );
            $this->fail('Expected wrong-token invalid reset attempt to be rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame($missingTrace, $wrongTokenTrace);
    }

    public function test_issue_releases_the_user_cooldown_when_token_issuance_fails(): void
    {
        $user = (object) ['id' => 'user-123'];

        $tokenService = Mockery::mock(PasswordResetTokenService::class);
        $tokenService->shouldReceive('acquireIssueCooldownForUser')
            ->once()
            ->with('user-123', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturn(true);
        $tokenService->shouldReceive('issueForUser')
            ->once()
            ->with('user-123', 'existing@example.org', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andThrow(new RuntimeException('Simulated issuance failure.'));
        $tokenService->shouldReceive('releaseIssueCooldownForUser')
            ->once()
            ->with('user-123', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a');

        $service = new PasswordResetFlowService($tokenService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated issuance failure.');

        $service->issue(
            email: 'existing@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
            user: $user,
            userIdResolver: static fn (object $resolvedUser): string => $resolvedUser->id,
        );
    }

    public function test_reset_releases_the_issue_cooldown_when_password_persistence_fails_after_consume(): void
    {
        $user = (object) ['id' => 'user-123'];

        $tokenService = Mockery::mock(PasswordResetTokenService::class);
        $tokenService->shouldReceive('attemptConsumeForUser')
            ->once()
            ->with('user-123', 'valid-token', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a')
            ->andReturn(true);
        $tokenService->shouldNotReceive('rejectInvalidResetAttempt');
        $tokenService->shouldReceive('releaseIssueCooldownForUser')
            ->once()
            ->with('user-123', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a');

        $service = new PasswordResetFlowService($tokenService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated password persistence failure.');

        $service->reset(
            email: 'existing@example.org',
            token: 'valid-token',
            password: 'Password123!',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
            user: $user,
            userIdResolver: static fn (object $resolvedUser): string => $resolvedUser->id,
            applyReset: static function (): void {
                throw new RuntimeException('Simulated password persistence failure.');
            },
        );
    }
}
