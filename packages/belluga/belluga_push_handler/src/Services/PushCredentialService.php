<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;

class PushCredentialService
{
    public function current(): ?PushCredential
    {
        $settings = TenantPushSettings::current();
        $credentialId = $settings?->firebase_credentials_id;

        if (! $credentialId) {
            return null;
        }

        return PushCredential::query()->find($credentialId);
    }
}
