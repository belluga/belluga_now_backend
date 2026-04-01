<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Belluga\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;

class AppLinksIdentifierGatewayAdapter implements AppLinksIdentifierGatewayContract
{
    public function identifierForPlatform(string $platform): ?string
    {
        $tenant = Tenant::current()?->fresh();
        if ($tenant === null) {
            return null;
        }

        $domainType = match (strtolower(trim($platform))) {
            Tenant::APP_PLATFORM_ANDROID => Tenant::DOMAIN_TYPE_APP_ANDROID,
            Tenant::APP_PLATFORM_IOS => Tenant::DOMAIN_TYPE_APP_IOS,
            default => null,
        };
        if (! is_string($domainType) || $domainType === '') {
            return null;
        }

        $domain = Domains::query()
            ->where('tenant_id', (string) $tenant->_id)
            ->where('type', $domainType)
            ->orderBy('created_at')
            ->first();
        if (! $domain instanceof Domains) {
            return null;
        }

        $identifier = trim((string) $domain->path);

        return $identifier === '' ? null : $identifier;
    }

    public function hasIdentifierForPlatform(string $platform): bool
    {
        return $this->identifierForPlatform($platform) !== null;
    }
}
