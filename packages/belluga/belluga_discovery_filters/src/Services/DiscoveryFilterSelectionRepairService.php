<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters\Services;

use Belluga\DiscoveryFilters\Data\DiscoveryFilterDefinition;

final class DiscoveryFilterSelectionRepairService
{
    /**
     * @param  array<int, DiscoveryFilterDefinition>  $catalog
     * @param  array{primary?: array<int, string>|string|null, taxonomy?: array<string, array<int, string>|string|null>|null}  $selection
     * @return array{primary: array<int, string>, taxonomy: array<string, array<int, string>>, repaired: bool}
     */
    public function repair(array $catalog, array $selection): array
    {
        $catalogByKey = [];
        foreach ($catalog as $definition) {
            $catalogByKey[$definition->key] = $definition;
        }

        $rawPrimary = $selection['primary'] ?? [];
        $primary = is_array($rawPrimary) ? $rawPrimary : [$rawPrimary];
        $nextPrimary = [];
        $repaired = false;

        foreach ($primary as $key) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '' || ! isset($catalogByKey[$normalizedKey])) {
                $repaired = true;
                continue;
            }
            if (! in_array($normalizedKey, $nextPrimary, true)) {
                $nextPrimary[] = $normalizedKey;
            }
        }

        $allowedTaxonomy = [];
        foreach ($nextPrimary as $key) {
            foreach ($catalogByKey[$key]->taxonomyValuesByGroup as $group => $values) {
                $allowedTaxonomy[$group] ??= [];
                foreach ($values as $value) {
                    if (! in_array($value, $allowedTaxonomy[$group], true)) {
                        $allowedTaxonomy[$group][] = $value;
                    }
                }
            }
        }

        $rawTaxonomy = $selection['taxonomy'] ?? [];
        if (! is_array($rawTaxonomy)) {
            $rawTaxonomy = [];
            $repaired = true;
        }

        $nextTaxonomy = [];
        foreach ($rawTaxonomy as $group => $values) {
            $groupKey = strtolower(trim((string) $group));
            if ($groupKey === '' || ! isset($allowedTaxonomy[$groupKey])) {
                $repaired = true;
                continue;
            }
            $valueList = is_array($values) ? $values : [$values];
            foreach ($valueList as $value) {
                $normalizedValue = strtolower(trim((string) $value));
                if ($normalizedValue === '' || ! in_array($normalizedValue, $allowedTaxonomy[$groupKey], true)) {
                    $repaired = true;
                    continue;
                }
                $nextTaxonomy[$groupKey] ??= [];
                if (! in_array($normalizedValue, $nextTaxonomy[$groupKey], true)) {
                    $nextTaxonomy[$groupKey][] = $normalizedValue;
                }
            }
        }

        if (count($nextPrimary) !== count($primary)) {
            $repaired = true;
        }

        return [
            'primary' => $nextPrimary,
            'taxonomy' => $nextTaxonomy,
            'repaired' => $repaired,
        ];
    }
}
