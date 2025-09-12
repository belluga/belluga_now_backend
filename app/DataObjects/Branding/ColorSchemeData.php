<?php

namespace App\DataObjects\Branding;

readonly class ColorSchemeData
{
    public function __construct(
        public string $brightness,
        public string $primarySeedColor,
        public string $secondarySeedColor,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            brightness: $data['brightness'],
            primarySeedColor: $data['primarySeedColor'],
            secondarySeedColor: $data['secondarySeedColor'],
        );
    }

    public function toArray(): array
    {
        return [
            'brightness' => $this->brightness,
            'primarySeedColor' => $this->primarySeedColor,
            'secondarySeedColor' => $this->secondarySeedColor,
        ];
    }
}
