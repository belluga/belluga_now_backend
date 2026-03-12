<?php

namespace App\Models\Landlord;

use App\Support\Helpers\ArrayReplaceEmptyAware;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * Representa o documento aninhado 'branding_data'.
 */
class BrandingData extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'theme_data_settings',
        'logo_settings',
        'pwa_icon',
    ];

    //    protected static $unguarded = true;

    protected $casts = [
        //        'theme_data_settings' => 'array',
        //        'logo_settings' => 'array',
        //        'pwa_icon' => 'array',
    ];

    public function toArray(): array
    {
        return [
            'theme_data_settings' => $this->theme_data_settings,
            'logo_settings' => $this->logo_settings,
            'pwa_icon' => $this->pwa_icon,
        ];
    }

    public static function getCurrentData(): array
    {
        $tenant = Tenant::current();
        $landlord = Landlord::singleton();

        if (! $tenant) {
            return $landlord->brandingData->toArray();
        }

        $branding_tenant = $tenant->brandingData;
        $branding_landlord = $landlord->brandingData;

        return ArrayReplaceEmptyAware::mergeIfEmptyRecursive($branding_landlord->toArray(), $branding_tenant->toArray());

    }
}
