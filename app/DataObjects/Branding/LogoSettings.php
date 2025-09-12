<?php

namespace App\Models\Landlord\Branding;

readonly class LogoSettings
{
    public function __construct(
        public string $lightLogoUri,
        public string $darkLogoUri,
        public string $lightIconUri,
        public string $darkIconUri,
    ) {}
}
