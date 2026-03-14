<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account\Concerns;

use Belluga\PushHandler\Http\Support\PushAccountScopeResolver;

trait ResolvesAccountContext
{
    protected function requireAccountId(PushAccountScopeResolver $accountScope): string
    {
        $accountId = $accountScope->currentAccountId();
        if ($accountId === null || $accountId === '') {
            abort(422, 'Account context not available.');
        }

        return $accountId;
    }
}
