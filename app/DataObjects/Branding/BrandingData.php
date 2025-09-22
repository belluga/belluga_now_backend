<?php

namespace App\DataObjects\Branding;

class BrandingData
{
    public function __construct(
        public ThemeDataSettings $themeDataSettings,
        public LogoSettings $logoSettings,
        public PwaIcon $pwaIcon,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            themeDataSettings: ThemeDataSettings::fromArray($data['theme_data_settings'] ?? []),
            logoSettings: LogoSettings::fromArray($data['logo_settings'] ?? []),
            pwaIcon: PwaIcon::fromArray($data['pwa_icon'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'theme_data_settings' => $this->themeDataSettings->toArray(),
            'logo_settings' => $this->logoSettings->toArray(),
            'pwa_icon' => $this->pwaIcon->toArray(),
        ];
    }
}
