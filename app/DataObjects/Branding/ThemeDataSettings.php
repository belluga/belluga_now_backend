<?php

namespace App\DataObjects\Branding;

readonly class ThemeDataSettings
{
    public function __construct(
        public ColorSchemeData $darkSchemeData,
        public ColorSchemeData $lightSchemeData,
    ) {}

    /**
     * Creates a ThemeDataSettings object from an array.
     */
    public static function fromArray(array $data): self
    {
        print("fromArray ThemeDataSettings");
        print_r($data);
        return new self(
            darkSchemeData: ColorSchemeData::fromArray(
                [
                        "brightness" => "dark",
                    ...$data['darkSchemeData']
                ]
            ),

            lightSchemeData: ColorSchemeData::fromArray(
                [
                    "brightness" => "light",
                    ...$data['lightSchemeData']
                ]
            )
        );
    }

    /**
     * Converts the ThemeDataSettings object to an array.
     */
    public function toArray(): array
    {
        return [
            'darkSchemeData' => $this->darkSchemeData->toArray(),
            'lightSchemeData' => $this->lightSchemeData->toArray(),
        ];
    }
}
