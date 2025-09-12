<?php

namespace App\DataObjects\Branding;

/**
 * A Data Transfer Object that holds all branding configuration for a tenant.
 */
readonly class BrandingData
{
    public function __construct(
        public ThemeDataSettings $themeDataSettings,
        public LogoSettings $logoSettings,
    ) {}

    /**
     * Creates a BrandingData object from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            themeDataSettings: ThemeDataSettings::fromArray($data['themeDataSettings']),
            logoSettings: LogoSettings::fromArray($data['logoSettings'])
        );
    }

    /**
     * Converts the BrandingData object to an array.
     */
    public function toArray(): array
    {
        return [
            'themeDataSettings' => $this->themeDataSettings->toArray(),
            'logoSettings' => $this->logoSettings->toArray(),
        ];
    }
}
