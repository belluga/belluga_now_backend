<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use Closure;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Session;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Throwable;

final class AccountProfileTransactionRunner
{
    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    public function __construct(
        private readonly AccountProfileTransactionRetryPolicy $retryPolicy,
        ?Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * @template T
     *
     * @param  Closure(AccountProfileTransactionContext): T  $callback
     * @param  null|Closure(): ?T  $reconcileIndeterminate
     * @return T
     */
    public function run(Closure $callback, ?Closure $reconcileIndeterminate = null): mixed
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for Account Profile transactions.');
        }

        for ($bodyAttempt = 1; $bodyAttempt <= AccountProfileTransactionRetryPolicy::MAX_BODY_ATTEMPTS; $bodyAttempt++) {
            $session = null;
            $bodyCompleted = false;
            try {
                $connection->beginTransaction();
                $session = $connection->getSession();
                if ($session === null) {
                    throw new RuntimeException('Account Profile transaction session is unavailable.');
                }

                /** @var T $result */
                $result = $callback(new AccountProfileTransactionContext(
                    $connection->getDatabase(),
                    $session,
                ));
                $bodyCompleted = true;
                $this->commit($connection);

                return $result;
            } catch (Throwable $exception) {
                if ($session instanceof Session) {
                    $this->abortIfActive($connection, $session);
                }

                if ($this->retryPolicy->shouldRetryBody($exception, $bodyAttempt)) {
                    $delay = $this->retryPolicy->bodyRetryDelayMicroseconds($exception, $bodyAttempt);
                    if ($delay > 0) {
                        ($this->sleeper)($delay);
                    }

                    continue;
                }

                if ($this->retryPolicy->isWriteConflict($exception)) {
                    throw new ConcurrencyConflictException(
                        'Account Profile mutation could not stabilize under concurrent writes.',
                        previous: $exception,
                    );
                }

                if ($bodyCompleted
                    && $this->retryPolicy->isUnknownCommit($exception)
                    && $reconcileIndeterminate !== null) {
                    $reconciled = $reconcileIndeterminate();
                    if ($reconciled !== null) {
                        return $reconciled;
                    }

                    throw new AccountProfileCommandIndeterminateException($exception);
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Account Profile transaction attempts were exhausted.');
    }

    private function commit(Connection $connection): void
    {
        for ($commitAttempt = 1; ; $commitAttempt++) {
            try {
                $connection->commit();

                return;
            } catch (Throwable $exception) {
                if ($this->retryPolicy->shouldRetryCommit($exception, $commitAttempt)) {
                    continue;
                }

                throw $exception;
            }
        }
    }

    private function abortIfActive(Connection $connection, Session $session): void
    {
        if (! $session->isInTransaction()) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (Throwable) {
            // The original body or commit failure remains the actionable error.
        }
    }
}
