<?php

declare(strict_types=1);

namespace App\Integration\Push;

use App\Models\Tenants\Account;
use Belluga\PushHandler\Contracts\PushAccountContextContract;

class PushAccountContextAdapter implements PushAccountContextContract
{
    public function currentAccountId(): ?string
    {
        $account = Account::current();

        if ($account === null) {
            return null;
        }

        return (string) $account->getAttribute('_id');
    }
}
