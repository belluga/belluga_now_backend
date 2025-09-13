<?php

namespace App\DataObjects\Branding;

readonly class ColorSchemeData
{
    public function __construct(
        public string $primarySeedColor,
        public string $secondarySeedColor,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            primarySeedColor: $data['primarySeedColor'],
            secondarySeedColor: $data['secondarySeedColor'],
        );
    }

    public function toArray(): array
    {
        return [
            'primarySeedColor' => $this->primarySeedColor,
            'secondarySeedColor' => $this->secondarySeedColor,
        ];
    }
}
