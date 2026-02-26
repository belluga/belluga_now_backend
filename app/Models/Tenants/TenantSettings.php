<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Belluga\Settings\Models\Tenants\TenantSettings as PackageTenantSettings;

class TenantSettings extends PackageTenantSettings
{
    protected $fillable = [
        'map_ui',
        'events',
    ];

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public function getMapUiAttribute(mixed $value): array
    {
        return $this->normalizeArray($value);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public function getEventsAttribute(mixed $value): array
    {
        return $this->normalizeArray($value);
    }

    public static function current(): ?self
    {
        /** @var self|null $current */
        $current = parent::current();

        return $current;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }
}
