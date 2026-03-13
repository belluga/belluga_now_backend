<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface PushSettingsStoreContract
{
    /**
     * @return array<string, mixed>
     */
    public function getNamespaceValue(string $namespace): array;
}
