<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventCapabilitySettingsContract
{
    /**
     * @return array<string, mixed>
     */
    public function resolveTenantCapabilities(): array;
}

