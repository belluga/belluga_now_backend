<?php

namespace App\DataObjects\Branding;

readonly class LogoSettings
{
    public function __construct(
        public string $faviconUri,
        public string $lightLogoUri,
        public string $darkLogoUri,
        public string $lightIconUri,
        public string $darkIconUri,
    ) {}

    /**
     * Creates a LogoSettings object from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            faviconUri: $data['faviconUri'],
            lightLogoUri: $data['lightLogoUri'],
            darkLogoUri: $data['darkLogoUri'],
            lightIconUri: $data['lightIconUri'],
            darkIconUri: $data['darkIconUri']
        );
    }

    /**
     * Converts the LogoSettings object to an array.
     */
    public function toArray(): array
    {
        return [
            'faviconUri' => $this->faviconUri,
            'lightLogoUri' => $this->lightLogoUri,
            'darkLogoUri' => $this->darkLogoUri,
            'lightIconUri' => $this->lightIconUri,
            'darkIconUri' => $this->darkIconUri,
        ];
    }
}
