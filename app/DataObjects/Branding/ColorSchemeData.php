<?php

namespace App\DataObjects\Branding;

class ColorSchemeData
{
    public function __construct(
        public string $primarySeedColor,
        public string $secondarySeedColor,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            primarySeedColor: $data['primary_seed_color'] ?? '',
            secondarySeedColor: $data['secondary_seed_color'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'color_scheme_data' => [
                'primary_seed_color' => $this->primarySeedColor,
                'secondary_seed_color' => $this->secondarySeedColor,
            ]
        ];
    }
}
