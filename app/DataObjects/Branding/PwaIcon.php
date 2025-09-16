<?php

namespace App\DataObjects\Branding;

class PwaIcon
{
    public function __construct(
        public string $sourceUri,
        public string $icon192Uri,
        public string $icon512Uri,
        public string $iconMaskable512Uri,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sourceUri: $data['source_uri'] ?? '',
            icon192Uri: $data['icon192_uri'] ?? '',
            icon512Uri: $data['icon512_uri'] ?? '',
            iconMaskable512Uri: $data['icon_maskable512_uri'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'pwa_icon' => [
                'source_uri' => $this->sourceUri,
                'icon192_uri' => $this->icon192Uri,
                'icon512_uri' => $this->icon512Uri,
                'icon_maskable512_uri' => $this->iconMaskable512Uri,
            ]
        ];
    }
}
