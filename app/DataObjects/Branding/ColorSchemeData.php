<?php

namespace App\Models\Landlord\Branding;

readonly class ColorSchemeData
{
    /**
     * @param string $brightness Should be 'dark' or 'light'.
     * @param string $primarySeedColor The primary color as a hex string (e.g., '#004b7c').
     * @param string $secondarySeedColor The secondary color as a hex string (e.g., '#00E6B8').
     */
    public function __construct(
        public string $brightness,
        public string $primarySeedColor,
        public string $secondarySeedColor,
    ) {}
}
