<?php

declare(strict_types=1);

return [
    'schema_version' => '1.0.0',
    'routes' => [
        'tenant' => [
            'prefix' => 'api/v1',
            'settings_prefix' => 'settings',
        ],
        'landlord' => [
            'prefix' => 'admin/api/v1',
            'settings_prefix' => 'settings',
            'tenant_settings_prefix' => '{tenant_slug}/settings',
        ],
    ],
];
