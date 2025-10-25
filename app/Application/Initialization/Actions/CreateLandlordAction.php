<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Models\Landlord\Landlord;

class CreateLandlordAction
{
    /**
     * @param array<string, mixed> $landlordData
     * @param array<string, mixed> $themeData
     * @param array<string, mixed> $logoSettings
     * @param array<string, mixed> $pwaIcon
     */
    public function execute(
        array $landlordData,
        array $themeData,
        array $logoSettings,
        array $pwaIcon
    ): Landlord {
        $landlord = Landlord::create([
            'name' => $landlordData['name'],
        ]);

        $landlord->branding_data = [
            'theme_data_settings' => $themeData,
            'logo_settings' => $logoSettings,
            'pwa_icon' => $pwaIcon,
        ];

        $landlord->save();

        return $landlord;
    }
}
