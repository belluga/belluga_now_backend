<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantSettings extends Model
{
    use UsesTenantConnection;

    protected $table = 'settings';

    protected $fillable = [
        'profile_type_registry',
        'map_ui',
    ];

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    public function getProfileTypeRegistryAttribute(mixed $value): array
    {
        $registry = $this->normalizeArray($value);

        return array_values(array_filter($registry, static fn ($entry): bool => is_array($entry)));
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public function getMapUiAttribute(mixed $value): array
    {
        return $this->normalizeArray($value);
    }

    public static function current(): ?self
    {
        return static::query()->first();
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
