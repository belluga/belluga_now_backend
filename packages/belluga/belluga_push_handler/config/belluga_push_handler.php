<?php

declare(strict_types=1);

return [
    'delivery_ttl_minutes' => [
        'transactional' => 60,
        'promotional' => 60 * 24 * 7,
        'default' => 60 * 24 * 7,
    ],
    'fcm' => [
        'max_batch_size' => 500,
        'max_ttl_days' => 28,
    ],
];
