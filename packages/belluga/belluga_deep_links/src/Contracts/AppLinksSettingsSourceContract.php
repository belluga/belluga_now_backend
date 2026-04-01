<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Contracts;

interface AppLinksSettingsSourceContract
{
    /**
     * @return array<string, mixed>
     */
    public function currentAppLinksSettings(): array;
}
