<?php

declare(strict_types=1);

namespace Belluga\Settings\Registry;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use RuntimeException;

class InMemorySettingsRegistry implements SettingsRegistryContract
{
    /**
     * @var array<string, SettingsNamespaceDefinition>
     */
    private array $definitions = [];

    public function register(SettingsNamespaceDefinition $definition): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $definition->namespace)) {
            throw new RuntimeException("Invalid namespace [{$definition->namespace}]. Use snake_case.");
        }

        $key = $definition->scope . ':' . $definition->namespace;

        if (array_key_exists($key, $this->definitions)) {
            throw new RuntimeException("Settings namespace already registered [{$key}].");
        }

        $this->definitions[$key] = $definition;
    }

    public function all(?string $scope = null): array
    {
        if ($scope === null) {
            return array_values($this->definitions);
        }

        $prefix = $scope . ':';

        return array_values(array_filter(
            $this->definitions,
            static fn (string $key): bool => str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY
        ));
    }

    public function find(string $namespace, ?string $scope = null): ?SettingsNamespaceDefinition
    {
        if ($scope !== null) {
            $key = $scope . ':' . $namespace;

            return $this->definitions[$key] ?? null;
        }

        foreach ($this->definitions as $key => $definition) {
            if (str_ends_with($key, ':' . $namespace)) {
                return $definition;
            }
        }

        return null;
    }
}
