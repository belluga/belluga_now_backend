<?php

namespace App\DataObjects\Branding;

class ThemeDataSettings
{
    public function __construct(
        public string $brightnessDefault,
        public string $primarySeedColor,
        public string $secondarySeedColor,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            brightnessDefault: $data['brightness_default'] ?? '',
            primarySeedColor: $data['primary_seed_color'] ?? '',
            secondarySeedColor: $data['secondary_seed_color'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'brightness_default' => $this->brightnessDefault ?? '',
            'primary_seed_color' => $this->primarySeedColor ?? '',
            'secondary_seed_color' => $this->secondarySeedColor ?? '',
        ];
    }
}
