<?php

declare(strict_types=1);

namespace Belluga\Settings\Models\Tenants;

use Belluga\Settings\Models\SettingsDocument;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantSettings extends SettingsDocument
{
    use UsesTenantConnection;
}
