<?php

declare(strict_types=1);

namespace App\Integration\DiscoveryFilters;

use App\Models\Tenants\TenantSettings;
use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterSettingsContract;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class TenantDiscoveryFilterSettingsAdapter implements DiscoveryFilterSettingsContract
{
    public function resolveDiscoveryFiltersSettings(): array
    {
        $settings = TenantSettings::current();

        return $this->normalizeArray($settings?->getAttribute('discovery_filters'));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }
}
