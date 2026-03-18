<?php

declare(strict_types=1);

namespace Belluga\Settings\Contracts;

use Belluga\Settings\Support\SettingsNamespaceDefinition;

interface SettingsNamespacePatchGuardContract
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
    ): void;
}
