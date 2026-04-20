<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Integration\DeepLinks\AppLinksPatchGuard;
use App\Integration\Email\ResendEmailSettingsPatchGuard;
use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class CompositeSettingsPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly AppLinksPatchGuard $appLinksPatchGuard,
        private readonly ResendEmailSettingsPatchGuard $resendEmailPatchGuard,
        private readonly TenantPublicAuthMethodPatchGuard $tenantPublicAuthMethodPatchGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {
        $this->appLinksPatchGuard->guard($scope, $user, $namespace, $payload, $definition);
        $this->resendEmailPatchGuard->guard($scope, $user, $namespace, $payload, $definition);
        $this->tenantPublicAuthMethodPatchGuard->guard($scope, $user, $namespace, $payload, $definition);
    }
}
