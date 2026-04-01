<?php

declare(strict_types=1);

return [
    'resend' => [
        'base_url' => env('RESEND_API_BASE_URL', 'https://api.resend.com'),
        'timeout_seconds' => (int) env('RESEND_TIMEOUT_SECONDS', 10),
    ],
];
