<?php

declare(strict_types=1);

namespace App\Models\Tenants;

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
        'capability_revision',
        'host_admission_fence_revision',
    ];

    protected $casts = [
        'capability_revision' => 'int',
        'host_admission_fence_revision' => 'int',
    ];

    protected static function booted(): void
    {
        $invalidateTypeSets = static function (): void {
            AccountProfileTypeSetProvider::bumpRevision();
        };

        static::saved($invalidateTypeSets);
        static::deleted($invalidateTypeSets);
    }
}
