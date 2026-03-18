<?php

declare(strict_types=1);

namespace Belluga\Settings\Validation;

use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

final class NoopSettingsNamespacePatchGuard implements SettingsNamespacePatchGuardContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {}
}
