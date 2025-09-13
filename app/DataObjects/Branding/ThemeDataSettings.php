<?php

namespace App\DataObjects\Branding;

readonly class ThemeDataSettings
{
    public function __construct(
        public ColorSchemeData $dark_scheme_data,
        public ColorSchemeData $light_scheme_data,
    ) {}

    /**
     * Creates a ThemeDataSettings object from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dark_scheme_data: ColorSchemeData::fromArray($data['dark_scheme_data']),
            light_scheme_data: ColorSchemeData::fromArray($data['light_scheme_data'])
        );
    }

    /**
     * Converts the ThemeDataSettings object to an array.
     */
    public function toArray(): array
    {
        return [
            'dark_scheme_data' => $this->dark_scheme_data->toArray(),
            'light_scheme_data' => $this->light_scheme_data->toArray(),
        ];
    }
}
