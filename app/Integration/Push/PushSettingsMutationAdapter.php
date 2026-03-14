<?php

declare(strict_types=1);

namespace App\Integration\Push;

use Belluga\PushHandler\Contracts\PushSettingsMutationContract;
use Belluga\Settings\Application\SettingsKernelService;

class PushSettingsMutationAdapter implements PushSettingsMutationContract
{
    public function __construct(
        private readonly SettingsKernelService $settingsKernelService,
    ) {}

    public function patchNamespace(mixed $user, string $namespace, array $payload): array
    {
        return $this->settingsKernelService->patchNamespace('tenant', $user, $namespace, $payload);
    }
}
