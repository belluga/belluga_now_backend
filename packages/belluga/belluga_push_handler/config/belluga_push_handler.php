<?php

declare(strict_types=1);

return [
    'delivery_ttl_minutes' => [
        'transactional' => 60,
        'promotional' => 60 * 24 * 7,
        'default' => 60 * 24 * 7,
    ],
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
            'settings_firebase' => 'firebase',
            'settings_telemetry' => 'telemetry',
        ],
        'landlord' => [
            'prefix' => 'admin/api/v1',
            'tenant_settings_path' => '{tenant_slug}/settings/push',
            'tenant_settings_firebase_path' => '{tenant_slug}/settings/firebase',
            'tenant_settings_telemetry_path' => '{tenant_slug}/settings/telemetry',
        ],
    ],
    'fcm' => [
        'max_batch_size' => 500,
        'max_ttl_days' => 28,
    ],
];
