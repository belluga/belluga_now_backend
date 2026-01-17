<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Models\Tenants\PushCredential;

class PushCredentialService
{
    public function current(): ?PushCredential
    {
        $credentials = PushCredential::query()->get();

        if ($credentials->count() > 1) {
            throw new MultiplePushCredentialsException($credentials->count());
        }

        return $credentials->first();
    }
}
