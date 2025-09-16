<?php

namespace App\DataObjects\Branding;

class ThemeDataSettings
{
    public function __construct(
        public ColorSchemeData $darkSchemeData,
        public ColorSchemeData $lightSchemeData,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            darkSchemeData: ColorSchemeData::fromArray($data['dark_scheme_data'] ?? []),
            lightSchemeData: ColorSchemeData::fromArray($data['light_scheme_data'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'theme_data_settings' => [
                'dark_scheme_data' => $this->darkSchemeData->toArray(),
                'light_scheme_data' => $this->lightSchemeData->toArray(),
            ]
        ];
    }
}
