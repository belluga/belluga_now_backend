<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantProfileType extends Model
{
    use UsesTenantConnection;

    public const PERSONAL_TYPE = 'personal';

    protected $table = 'account_profile_types';

    protected $fillable = [
        'type',
        'label',
        'labels',
        'allowed_taxonomies',
        'visual',
        'poi_visual',
        'type_asset_url',
        'capabilities',
    ];

    protected $casts = [
    ];

    public function scopePubliclyDiscoverable($query)
    {
        return $query
            ->where('type', '!=', self::PERSONAL_TYPE)
            ->whereRaw(self::publicDiscoveryCapabilityExpression());
    }

    public function scopePublicCatalog($query)
    {
        return $query
            ->where('capabilities.is_favoritable', true)
            ->publiclyDiscoverable();
    }

    public function scopePublicPoiCatalog($query)
    {
        return $query
            ->publicCatalog()
            ->where('capabilities.is_poi_enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicDiscoveryCapabilityExpression(): array
    {
        return [
            '$or' => [
                ['capabilities.is_publicly_discoverable' => true],
                [
                    '$and' => [
                        ['type' => ['$ne' => self::PERSONAL_TYPE]],
                        [
                            '$or' => [
                                ['capabilities.is_publicly_discoverable' => ['$exists' => false]],
                                ['capabilities.is_publicly_discoverable' => null],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
