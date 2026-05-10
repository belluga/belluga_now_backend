<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Throwable;

class PasswordResetFlowService
{
    public function __construct(
        private readonly PasswordResetTokenService $passwordResetTokenService,
    ) {}

    public function issue(
        string $email,
        string $broker,
        ?string $scope,
        mixed $user,
        callable $userIdResolver,
    ): void {
        $normalizedEmail = strtolower($email);

        if ($user === null) {
            $cooldownAcquired = $this->passwordResetTokenService->acquireIssueCooldown(
                email: $normalizedEmail,
                broker: $broker,
                scope: $scope,
            );

            if (! $cooldownAcquired) {
                $this->passwordResetTokenService->absorbMissingIssueWorkFactor(
                    email: $normalizedEmail,
                    broker: $broker,
                    scope: $scope,
                );

                return;
            }

            $this->passwordResetTokenService->absorbMissingIssueWorkFactor(
                email: $normalizedEmail,
                broker: $broker,
                scope: $scope,
            );

            return;
        }

        $userId = $userIdResolver($user);
        $cooldownAcquired = $this->passwordResetTokenService->acquireIssueCooldownForUser(
            userId: $userId,
            broker: $broker,
            scope: $scope,
        );

        if (! $cooldownAcquired) {
            $this->passwordResetTokenService->absorbMissingIssueWorkFactor(
                email: $normalizedEmail,
                broker: $broker,
                scope: $scope,
            );

            return;
        }

        try {
            $this->passwordResetTokenService->issueForUser(
                userId: $userId,
                email: $normalizedEmail,
                broker: $broker,
                scope: $scope,
            );
        } catch (Throwable $exception) {
            $this->passwordResetTokenService->releaseIssueCooldownForUser(
                userId: $userId,
                broker: $broker,
                scope: $scope,
            );

            throw $exception;
        }
    }

    public function reset(
        string $email,
        string $token,
        string $password,
        string $broker,
        ?string $scope,
        mixed $user,
        callable $userIdResolver,
        callable $applyReset,
    ): void {
        $userId = $user === null
            ? $this->invalidResetProbeUserId($email, $broker, $scope)
            : $userIdResolver($user);

        $consumed = $this->passwordResetTokenService->attemptConsumeForUser(
            userId: $userId,
            token: $token,
            broker: $broker,
            scope: $scope,
        );

        if ($user === null || ! $consumed) {
            $this->passwordResetTokenService->rejectInvalidResetAttempt(
                token: $token,
                password: $password,
                broker: $broker,
                scope: $scope,
            );
        }

        try {
            $applyReset($user, $password);
        } catch (Throwable $exception) {
            $this->passwordResetTokenService->releaseIssueCooldownForUser(
                userId: $userId,
                broker: $broker,
                scope: $scope,
            );

            throw $exception;
        }
    }

    private function invalidResetProbeUserId(string $email, string $broker, ?string $scope): string
    {
        return 'invalid-reset-probe:'.hash(
            'sha256',
            strtolower($email).'|'.$broker.'|'.trim((string) $scope),
        );
    }
}
