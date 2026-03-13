<?php

declare(strict_types=1);

namespace App\Integration\Push;

use Belluga\PushHandler\Contracts\PushSettingsStoreContract;
use Belluga\Settings\Contracts\SettingsStoreContract;

class PushSettingsStoreAdapter implements PushSettingsStoreContract
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
    ) {}

    public function getNamespaceValue(string $namespace): array
    {
        return $this->settingsStore->getNamespaceValue('tenant', $namespace);
    }
}
