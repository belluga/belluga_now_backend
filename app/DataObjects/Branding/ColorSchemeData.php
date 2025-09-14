<?php

namespace App\DataObjects\Branding;

readonly class ColorSchemeData
{
    public function __construct(
        public string $primary_seed_color,
        public string $secondary_seed_color,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            primary_seed_color: $data['primary_seed_color'] ?? '',
            secondary_seed_color: $data['secondary_seed_color' ?? ''],
        );
    }

    public function toArray(): array
    {
        return [
            'primary_seed_color' => $this->primary_seed_color,
            'secondary_seed_color' => $this->secondary_seed_color,
        ];
    }
}
