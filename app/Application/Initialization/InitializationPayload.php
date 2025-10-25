<?php

declare(strict_types=1);

namespace App\Application\Initialization;

class InitializationPayload
{
    /**
     * @param array<string, mixed> $landlord
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $role
     * @param array<string, mixed> $user
     * @param array<string, mixed> $themeDataSettings
     * @param array<string, mixed> $logoSettings
     * @param array<string, mixed> $pwaIcon
     * @param array<int, string> $tenantDomains
     */
    public function __construct(
        public readonly array $landlord,
        public readonly array $tenant,
        public readonly array $role,
        public readonly array $user,
        public readonly array $themeDataSettings,
        public readonly array $logoSettings,
        public readonly array $pwaIcon,
        public readonly array $tenantDomains = []
    ) {
    }
}
