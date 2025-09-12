<?php

namespace App\Models\Landlord\Branding;

readonly class ThemeDataSettings
{
    public function __construct(
        public ColorSchemeData $darkSchemeData,
        public ColorSchemeData $lightSchemeData,
    ) {}
}
