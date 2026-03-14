<?php

declare(strict_types=1);

namespace App\Integration\Push;

use Belluga\PushHandler\Contracts\PushSettingsStoreContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsStoreContract;

class PushSettingsStoreAdapter implements PushSettingsStoreContract
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
        private readonly SettingsRegistryContract $settingsRegistry,
    ) {}

    public function getNamespaceValue(string $namespace): array
    {
        return $this->settingsStore->getNamespaceValue('tenant', $namespace);
    }

    public function getResolvedNamespaceValue(string $namespace): array
    {
        $value = $this->getNamespaceValue($namespace);
        $definition = $this->settingsRegistry->find($namespace, 'tenant');

        if ($definition === null) {
            return $value;
        }

        return $definition->applyDefaults($value);
    }
}
