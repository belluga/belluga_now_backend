<?php

declare(strict_types=1);

return [
    'invites' => [
        'enabled' => (bool) env('INVITE_STAGE_TEST_SUPPORT_ENABLED', false),
        'secret_header' => (string) env('INVITE_STAGE_TEST_SUPPORT_SECRET_HEADER', 'X-Test-Support-Key'),
        'secret' => (string) env('INVITE_STAGE_TEST_SUPPORT_SECRET', ''),
        'allowed_tenants' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('INVITE_STAGE_TEST_SUPPORT_ALLOWED_TENANTS', 'guarappari'))
        ))),
    ],
];
