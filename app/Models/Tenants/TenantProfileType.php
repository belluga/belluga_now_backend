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

    public function scopeQueryable($query)
    {
        return $query->whereRaw(self::queryabilityCapabilityExpression());
    }

    public function scopePubliclyNavigable($query)
    {
        return $query->whereRaw(self::publicNavigabilityCapabilityExpression());
    }

    public function scopePubliclyDiscoverable($query)
    {
        return $query
            ->queryable()
            ->whereRaw(self::publicDiscoveryCapabilityExpression());
    }

    public function scopePublicCatalog($query)
    {
        return $query
            ->publiclyDiscoverable();
    }

    public function scopeFavoritable($query)
    {
        return $query->whereRaw(self::favoritableCapabilityExpression());
    }

    public function scopePublicDiscoverySurface($query)
    {
        return $query
            ->publiclyDiscoverable()
            ->favoritable();
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
    public static function queryabilityCapabilityExpression(): array
    {
        return [
            '$and' => [
                ['type' => ['$ne' => self::PERSONAL_TYPE]],
                [
                    '$or' => [
                        ['capabilities.is_queryable' => true],
                        ['capabilities.is_queryable' => ['$exists' => false]],
                        ['capabilities.is_queryable' => null],
                    ],
                ],
            ],
        ];
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

    /**
     * @return array<string, mixed>
     */
    public static function publicNavigabilityCapabilityExpression(): array
    {
        return [
            '$or' => [
                ['capabilities.is_publicly_navigable' => true],
                [
                    '$and' => [
                        ['type' => ['$ne' => self::PERSONAL_TYPE]],
                        [
                            '$or' => [
                                ['capabilities.is_publicly_navigable' => ['$exists' => false]],
                                ['capabilities.is_publicly_navigable' => null],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function favoritableCapabilityExpression(): array
    {
        return [
            '$or' => [
                ['capabilities.is_favoritable' => true],
                ['capabilities.is_favoritable' => ['$exists' => false]],
                ['capabilities.is_favoritable' => null],
            ],
        ];
    }
}
