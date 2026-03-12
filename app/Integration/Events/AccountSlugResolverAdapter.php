<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\Accounts\AccountQueryService;
use Belluga\Events\Contracts\EventAccountResolverContract;

class AccountSlugResolverAdapter implements EventAccountResolverContract
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService
    ) {}

    public function resolveAccountIdBySlug(string $accountSlug): string
    {
        $account = $this->accountQueryService->findBySlugOrFail($accountSlug);

        return (string) $account->_id;
    }
}
