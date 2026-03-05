<?php

declare(strict_types=1);

namespace Belluga\Settings\Contracts;

use Belluga\Settings\Support\SettingsNamespaceDefinition;

interface SettingsRegistryContract
{
    public function register(SettingsNamespaceDefinition $definition): void;

    /**
     * @return array<int, SettingsNamespaceDefinition>
     */
    public function all(?string $scope = null): array;

    public function find(string $namespace, ?string $scope = null): ?SettingsNamespaceDefinition;
}
