<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API Security Hardening Baseline
    |--------------------------------------------------------------------------
    |
    | Platform-wide API protection baseline aligned with the
    | foundation_documentation/todos/active/mvp_slices/TODO-v1-api-security-hardening.md
    | decisions. Cloudflare is treated as the edge layer while Laravel enforces
    | principal-aware and mutation-safety controls.
    |
    */
    'default_level' => 'L2',

    /*
    |--------------------------------------------------------------------------
    | Observe Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, policy violations are logged but not enforced. This supports
    | rollout from telemetry-only mode to hard enforcement once false positives
    | are validated.
    |
    */
    'observe_mode' => (bool) env('API_SECURITY_OBSERVE_MODE', false),

    'levels' => [
        'L1' => [
            'label' => 'L1 Core',
            'requests_per_minute' => (int) env('API_SECURITY_L1_RPM', 600),
            'require_idempotency' => false,
            'replay_window_seconds' => (int) env('API_SECURITY_L1_REPLAY_WINDOW', 300),
        ],
        'L2' => [
            'label' => 'L2 Balanced',
            'requests_per_minute' => (int) env('API_SECURITY_L2_RPM', 300),
            'require_idempotency' => false,
            'replay_window_seconds' => (int) env('API_SECURITY_L2_REPLAY_WINDOW', 600),
        ],
        'L3' => [
            'label' => 'L3 High Protection',
            'requests_per_minute' => (int) env('API_SECURITY_L3_RPM', 120),
            'require_idempotency' => true,
            'replay_window_seconds' => (int) env('API_SECURITY_L3_REPLAY_WINDOW', 900),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Level Overrides
    |--------------------------------------------------------------------------
    |
    | Regex patterns evaluated against request path without leading slash.
    | `level` must be one of L1/L2/L3. `require_idempotency` can override
    | level defaults when a specific route needs stronger or weaker guarantees.
    |
    */
    'route_overrides' => [
        [
            'pattern' => '#^api/v1/events/[^/]+/occurrences/[^/]+/admission$#',
            'level' => 'L3',
            'require_idempotency' => true,
        ],
        [
            'pattern' => '#^api/v1/occurrences/[^/]+/admission$#',
            'level' => 'L3',
            'require_idempotency' => true,
        ],
        [
            'pattern' => '#^api/v1/checkout/confirm$#',
            'level' => 'L3',
            'require_idempotency' => true,
        ],
        [
            'pattern' => '#^api/v1/events/[^/]+/occurrences/[^/]+/validation$#',
            'level' => 'L3',
            'require_idempotency' => true,
        ],
        [
            'pattern' => '#^api/v1/events/[^/]+/occurrences/[^/]+/ticket_units/[^/]+/(transfer|reissue)$#',
            'level' => 'L3',
            'require_idempotency' => true,
        ],
    ],

    'idempotency' => [
        'header_keys' => ['Idempotency-Key', 'X-Idempotency-Key'],
        'body_key' => 'idempotency_key',
        'cache_prefix' => 'api_security:idempotency',
        'cacheable_response_max_bytes' => (int) env('API_SECURITY_CACHEABLE_RESPONSE_MAX_BYTES', 131072),
    ],

    'rate_limit' => [
        'cache_prefix' => 'api_security:rate',
        'window_seconds' => (int) env('API_SECURITY_RATE_WINDOW', 60),
    ],

    'cloudflare' => [
        /*
         | If enabled, reject API requests that do not include Cloudflare edge
         | signal headers. Keep disabled for local/dev environments.
         */
        'enforce_origin_lock' => (bool) env('API_SECURITY_ENFORCE_CLOUDFLARE_ORIGIN_LOCK', false),
        'presence_headers' => ['CF-Ray', 'CF-Connecting-IP'],
    ],
];
