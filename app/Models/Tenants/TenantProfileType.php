<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
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

    protected static function booted(): void
    {
        $invalidateTypeSets = static function (): void {
            AccountProfileTypeSetProvider::bumpRevision();
        };

        static::saved($invalidateTypeSets);
        static::deleted($invalidateTypeSets);
    }

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
        return $query->publiclyDiscoverable();
    }

    public function scopeFavoritable($query)
    {
        return $query->whereRaw(self::favoritableCapabilityExpression());
    }

    public function scopePublicDiscoverySurface($query)
    {
        return $query
            ->publicCatalog();
    }

    public function scopePublicPoiCatalog($query)
    {
        return $query
            ->publicCatalog()
            ->poiEnabled();
    }

    public function scopePoiEnabled($query)
    {
        return $query->whereRaw(self::enabledCapabilityExpression(
            AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED,
        ));
    }

    public function scopeGalleryEnabled($query)
    {
        return $query->whereRaw(self::galleryEnabledCapabilityExpression());
    }

    public function scopeContactChannelsEnabled($query)
    {
        return $query->whereRaw(self::enabledCapabilityExpression(
            AccountProfileTypeCapabilityCatalog::HAS_CONTACT_CHANNELS,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function queryabilityCapabilityExpression(): array
    {
        return [
            '$and' => [
                ['type' => ['$ne' => self::PERSONAL_TYPE]],
                self::enabledCapabilityExpression(AccountProfileTypeCapabilityCatalog::IS_QUERYABLE),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicDiscoveryCapabilityExpression(): array
    {
        return self::enabledCapabilityExpression(
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicNavigabilityCapabilityExpression(): array
    {
        return self::enabledCapabilityExpression(
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_NAVIGABLE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function favoritableCapabilityExpression(): array
    {
        return self::enabledCapabilityExpression(AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function galleryEnabledCapabilityExpression(): array
    {
        return self::enabledCapabilityExpression(AccountProfileTypeCapabilityCatalog::HAS_GALLERY);
    }

    /**
     * @return array<string, bool>
     */
    private static function enabledCapabilityExpression(string $capability): array
    {
        return ["capabilities.{$capability}" => true];
    }
}
