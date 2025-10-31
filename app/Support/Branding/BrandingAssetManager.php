<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Traits\HasLogoFiles;
use Illuminate\Http\Request;

class BrandingAssetManager
{
    use HasLogoFiles;

    /**
     * @return array{logo_settings: array<string, string>, pwa_icon: array<string, string>}
     */
    public function createBrandingPayload(Request $request): array
    {
        $logoSettings = $this->processLogoUploads($request);

        $pwaIconPayload = [];
        $pwaIconFile = $request->file('branding_data.pwa_icon');
        if ($pwaIconFile) {
            $pwaIconPayload = $this->generatePwaIconVariants($pwaIconFile);
        }

        return [
            'logo_settings' => $logoSettings,
            'pwa_icon' => $pwaIconPayload,
        ];
    }
}
