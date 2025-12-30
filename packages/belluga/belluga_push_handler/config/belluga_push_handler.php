<?php

declare(strict_types=1);

return [
    'routes' => [
        'account' => [
            'prefix' => 'api/v1/accounts/{account_slug}',
            'messages_prefix' => 'push/messages',
        ],
        'tenant' => [
            'prefix' => 'api/v1',
            'register' => 'push/register',
            'unregister' => 'push/unregister',
            'settings_prefix' => 'settings',
            'settings_push' => 'push',
        ],
        'landlord' => [
            'prefix' => 'admin/api/v1',
            'tenant_settings_path' => '{tenant_slug}/settings/push',
        ],
    ],
];
