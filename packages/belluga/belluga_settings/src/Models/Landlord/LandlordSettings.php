<?php

declare(strict_types=1);

namespace Belluga\Settings\Models\Landlord;

use Belluga\Settings\Models\SettingsDocument;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordSettings extends SettingsDocument
{
    use UsesLandlordConnection;
}
