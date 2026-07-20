<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

/**
 * Server-only proof that a current-account deletion attempt may terminalize a
 * captured Profile while its owning Account is gated.
 */
final readonly class AccountProfileDeletionTerminalization
{
    public function __construct(
        public string $attemptId,
        public int $attemptGeneration,
        public string $claimToken,
        public string $accountId,
        public int $lifecycleFenceRevision,
    ) {}
}
