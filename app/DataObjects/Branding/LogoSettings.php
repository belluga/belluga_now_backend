<?php

namespace App\DataObjects\Branding;

readonly class LogoSettings
{
    public function __construct(
        public string $favicon_uri,
        public string $light_logo_uri,
        public string $dark_logo_uri,
        public string $light_icon_uri,
        public string $dark_icon_uri,
    ) {}

    /**
     * Creates a LogoSettings object from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            favicon_uri: $data['favicon_uri'],
            light_logo_uri: $data['light_logo_uri'],
            dark_logo_uri: $data['dark_logo_uri'],
            light_icon_uri: $data['light_icon_uri'],
            dark_icon_uri: $data['dark_icon_uri']
        );
    }

    /**
     * Converts the LogoSettings object to an array.
     */
    public function toArray(): array
    {
        return [
            'favicon_uri' => $this->favicon_uri,
            'light_logo_uri' => $this->light_logo_uri,
            'dark_logo_uri' => $this->dark_logo_uri,
            'light_icon_uri' => $this->light_icon_uri,
            'dark_icon_uri' => $this->dark_icon_uri,
        ];
    }
}
