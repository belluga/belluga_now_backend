<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventRadiusSettingsContract
{
    /**
     * @return array{min_km: float, default_km: float, max_km: float}
     */
    public function resolveRadiusSettings(): array;
}
