<?php

namespace App\DataObjects\Branding;

/**
 * A Data Transfer Object that holds all branding configuration for a tenant.
 */
readonly class BrandingData
{
    public function __construct(
        public ThemeDataSettings $theme_data_settings,
        public LogoSettings $logo_settings,
    ) {}

    /**
     * Creates a BrandingData object from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            theme_data_settings: ThemeDataSettings::fromArray($data['theme_data_settings']),
            logo_settings: LogoSettings::fromArray($data['logo_settings'])
        );
    }

    /**
     * Converts the BrandingData object to an array.
     */
    public function toArray(): array
    {
        return [
            'themeDataSettings' => $this->theme_data_settings->toArray(),
            'logoSettings' => $this->logo_settings->toArray(),
        ];
    }
}
