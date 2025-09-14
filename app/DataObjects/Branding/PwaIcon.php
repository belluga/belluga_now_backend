<?php

namespace App\DataObjects\Branding;

readonly class PwaIcon
{
    public function __construct(
        public string $source_uri,
        public string $icon_192_uri,
        public string $icon_512_uri,
        public string $icon_maskable_512_uri,
    ) {}

    public static function fromArray(array|string $data): self
    {
        return new self(
            source_uri: $data['source_uri'] ?? '',
            icon_192_uri: $data['icon_192_uri'] ?? '',
            icon_512_uri: $data['icon_512_uri'] ?? '',
            icon_maskable_512_uri: $data['icon_maskable_512_uri'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'source_uri' => $this->source_uri,
            'icon_192_uri' => $this->icon_192_uri,
            'icon_512_uri' => $this->icon_512_uri,
            'icon_maskable_512_uri' => $this->icon_maskable_512_uri,
        ];
    }
}
