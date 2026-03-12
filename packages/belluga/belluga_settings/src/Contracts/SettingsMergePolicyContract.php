<?php

declare(strict_types=1);

namespace Belluga\Settings\Contracts;

interface SettingsMergePolicyContract
{
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function merge(array $current, array $changes): array;
}
