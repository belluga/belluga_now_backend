<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Throwable;

final class AccountProfileTransactionRetryPolicy
{
    public const MAX_BODY_ATTEMPTS = 3;

    private const MAX_COMMIT_ATTEMPTS = 3;

    private const WRITE_CONFLICT_CODE = 112;

    public function shouldRetryBody(Throwable $exception, int $bodyAttempt): bool
    {
        return $bodyAttempt < self::MAX_BODY_ATTEMPTS
            && (
                $this->hasErrorLabel($exception, 'TransientTransactionError')
                || $this->isWriteConflict($exception)
            );
    }

    public function shouldRetryCommit(Throwable $exception, int $commitAttempt): bool
    {
        return $commitAttempt < self::MAX_COMMIT_ATTEMPTS
            && (int) $exception->getCode() !== 50
            && $this->hasErrorLabel($exception, 'UnknownTransactionCommitResult');
    }

    public function bodyRetryDelayMicroseconds(Throwable $exception, int $bodyAttempt): int
    {
        if (! $this->isWriteConflict($exception)) {
            return 0;
        }

        return match ($bodyAttempt) {
            1 => 1_000_000,
            2 => 2_000_000,
            default => 0,
        };
    }

    public function isUnknownCommit(Throwable $exception): bool
    {
        return (int) $exception->getCode() !== 50
            && $this->hasErrorLabel($exception, 'UnknownTransactionCommitResult');
    }

    private function hasErrorLabel(Throwable $exception, string $label): bool
    {
        return method_exists($exception, 'hasErrorLabel')
            && $exception->hasErrorLabel($label) === true;
    }

    public function isWriteConflict(Throwable $exception): bool
    {
        return (int) $exception->getCode() === self::WRITE_CONFLICT_CODE
            && str_contains(strtolower($exception->getMessage()), 'write conflict');
    }
}
