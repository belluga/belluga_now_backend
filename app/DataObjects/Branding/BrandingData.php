<?php

namespace App\Models\Landlord\Branding;

/**
 * A Data Transfer Object that holds all branding configuration for a tenant.
 */
readonly class BrandingData
{
    public function __construct(
        public ThemeDataSettings $themeDataSettings,
        public LogoSettings $logoSettings,
    ) {}
}
