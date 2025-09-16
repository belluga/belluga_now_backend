<?php

namespace App\DataObjects\Branding;

class LogoSettings
{
    public function __construct(
        public string $faviconUri,
        public string $lightLogoUri,
        public string $darkLogoUri,
        public string $lightIconUri,
        public string $darkIconUri,
    ) {}

    /**
     * Cria uma instância da classe a partir de um array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            faviconUri: $data['favicon_uri'],
            lightLogoUri: $data['light_logo_uri'],
            darkLogoUri: $data['dark_logo_uri'],
            lightIconUri: $data['light_icon_uri'],
            darkIconUri: $data['dark_icon_uri']
        );
    }

    /**
     * Converte o objeto de volta para um array.
     */
    public function toArray(): array
    {
        return [
            'logo_settings' => [
                'favicon_uri' => $this->faviconUri,
                'light_logo_uri' => $this->lightLogoUri,
                'dark_logo_uri' => $this->darkLogoUri,
                'light_icon_uri' => $this->lightIconUri,
                'dark_icon_uri' => $this->darkIconUri,
            ]
        ];
    }
}
