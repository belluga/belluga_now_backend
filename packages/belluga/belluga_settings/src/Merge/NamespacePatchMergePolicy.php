<?php

declare(strict_types=1);

namespace Belluga\Settings\Merge;

use Belluga\Settings\Contracts\SettingsMergePolicyContract;

class NamespacePatchMergePolicy implements SettingsMergePolicyContract
{
    public function merge(array $current, array $changes): array
    {
        $merged = $current;

        foreach ($changes as $path => $value) {
            data_set($merged, $path, $value);
        }

        return $merged;
    }
}
