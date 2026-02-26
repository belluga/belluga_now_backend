<?php

declare(strict_types=1);

namespace Belluga\Settings\Application;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsSchemaValidatorContract;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Auth\Access\AuthorizationException;

class SettingsKernelService
{
    public function __construct(
        private readonly SettingsRegistryContract $registry,
        private readonly SettingsStoreContract $store,
        private readonly SettingsSchemaValidatorContract $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $scope, mixed $user): array
    {
        $definitions = $this->accessibleDefinitions($scope, $user);
        $schema = array_map(
            static fn (SettingsNamespaceDefinition $definition): array => $definition->toSchemaArray(),
            $definitions
        );

        return [
            'schema_version' => (string) config('belluga_settings.schema_version', '1.0.0'),
            'schema_version_policy' => (array) config('belluga_settings.schema_version_policy', []),
            'namespaces' => $schema,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(string $scope, mixed $user): array
    {
        $values = [];

        foreach ($this->accessibleDefinitions($scope, $user) as $definition) {
            $values[$definition->namespace] = $this->store->getNamespaceValue($scope, $definition->namespace);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patchNamespace(string $scope, mixed $user, string $namespace, array $payload): array
    {
        $definition = $this->registry->find($namespace, $scope);

        if (! $definition) {
            abort(404, 'Settings namespace not found.');
        }

        if (! $this->canAccess($user, $definition)) {
            throw new AuthorizationException('Not authorized for this settings namespace.');
        }

        $changes = $this->validator->validatePatch($definition, $payload);

        if ($changes === []) {
            return $this->store->getNamespaceValue($scope, $namespace);
        }

        return $this->store->mergeNamespace($scope, $namespace, $changes, $definition);
    }

    /**
     * @return array<int, SettingsNamespaceDefinition>
     */
    private function accessibleDefinitions(string $scope, mixed $user): array
    {
        $definitions = $this->registry->all($scope);
        $filtered = array_filter(
            $definitions,
            fn (SettingsNamespaceDefinition $definition): bool => $this->canAccess($user, $definition)
        );

        usort($filtered, static function (SettingsNamespaceDefinition $left, SettingsNamespaceDefinition $right): int {
            $order = $left->order <=> $right->order;
            if ($order !== 0) {
                return $order;
            }

            return strcmp($left->namespace, $right->namespace);
        });

        return array_values($filtered);
    }

    private function canAccess(mixed $user, SettingsNamespaceDefinition $definition): bool
    {
        $ability = $definition->ability;

        if (! is_string($ability) || $ability === '') {
            return true;
        }

        if (! $user || ! method_exists($user, 'tokenCan')) {
            return false;
        }

        return (bool) $user->tokenCan($ability);
    }
}
