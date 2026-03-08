<?php

declare(strict_types=1);

$riskMatrix = [
    [
        'domain' => 'ticketing_checkout',
        'pattern' => '#^api/v1/checkout/confirm$#',
        'methods' => ['POST'],
        'level' => 'L3',
        'require_idempotency' => true,
    ],
    [
        'domain' => 'ticketing_admission',
        'pattern' => '#^api/v1/events/[^/]+/occurrences/[^/]+/admission$#',
        'methods' => ['POST'],
        'level' => 'L3',
        'require_idempotency' => true,
    ],
    [
        'domain' => 'ticketing_admission_occurrence',
        'pattern' => '#^api/v1/occurrences/[^/]+/admission$#',
        'methods' => ['POST'],
        'level' => 'L3',
        'require_idempotency' => true,
    ],
    [
        'domain' => 'ticketing_validation',
        'pattern' => '#^api/v1/events/[^/]+/occurrences/[^/]+/validation$#',
        'methods' => ['POST'],
        'level' => 'L3',
        'require_idempotency' => true,
    ],
    [
        'domain' => 'ticketing_transfer_reissue',
        'pattern' => '#^api/v1/events/[^/]+/occurrences/[^/]+/ticket_units/[^/]+/(transfer|reissue)$#',
        'methods' => ['POST'],
        'level' => 'L3',
        'require_idempotency' => true,
    ],
    [
        'domain' => 'settings_namespace_patch',
        'pattern' => '#^admin/api/v1/settings/values/[^/]+$#',
        'methods' => ['PATCH'],
        'level' => 'L2',
        'require_idempotency' => false,
    ],
    [
        'domain' => 'events_admin_mutation',
        'pattern' => '#^admin/api/v1/events(?:/[^/]+)?$#',
        'methods' => ['POST', 'PATCH', 'DELETE'],
        'level' => 'L2',
        'require_idempotency' => false,
    ],
    [
        'domain' => 'tenant_admin_onboarding',
        'pattern' => '#^admin/api/v1/account_onboardings$#',
        'methods' => ['POST'],
        'level' => 'L2',
        'require_idempotency' => false,
    ],
];

$routeOverrides = array_values(array_map(
    static function (array $entry): array {
        return array_filter(
            [
                'pattern' => (string) ($entry['pattern'] ?? ''),
                'methods' => array_values((array) ($entry['methods'] ?? [])),
                'level' => (string) ($entry['level'] ?? 'L2'),
                'require_idempotency' => (bool) ($entry['require_idempotency'] ?? false),
                'requests_per_minute' => $entry['requests_per_minute'] ?? null,
                'replay_window_seconds' => $entry['replay_window_seconds'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null
        );
    },
    $riskMatrix
));

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
    'minimum_level' => (string) env('API_SECURITY_MINIMUM_LEVEL', 'L1'),

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
    'level_rank' => [
        'L1' => 1,
        'L2' => 2,
        'L3' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint Risk Matrix
    |--------------------------------------------------------------------------
    |
    | Canonical domain mapping consumed by docs/tests/guardrails. Route-level
    | overrides are generated from this matrix.
    |
    */
    'risk_matrix' => $riskMatrix,
    'route_overrides' => $routeOverrides,

    /*
    |--------------------------------------------------------------------------
    | Tenant Overrides
    |--------------------------------------------------------------------------
    |
    | Resolution hierarchy is system_default -> tenant_override -> endpoint_override.
    | Overrides are monotonic and cannot reduce the effective level below the
    | stronger of system minimum and current profile.
    |
    */
    'tenant_overrides' => [
        'enabled' => (bool) env('API_SECURITY_TENANT_OVERRIDES_ENABLED', true),
        'tenants' => [
            // 'tenant-slug' => ['level' => 'L3', 'requests_per_minute' => 100],
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
        // Keep API available if the rate limiter backend is temporarily unhealthy.
        'fail_closed_on_backend_error' => (bool) env('API_SECURITY_RATE_LIMIT_FAIL_CLOSED_ON_BACKEND_ERROR', false),
    ],
    'lifecycle' => [
        'enabled' => (bool) env('API_SECURITY_LIFECYCLE_ENABLED', true),
        'cache_prefix' => 'api_security:lifecycle',
        'window_seconds' => (int) env('API_SECURITY_LIFECYCLE_WINDOW_SECONDS', 900),
        'recover_after_seconds' => (int) env('API_SECURITY_LIFECYCLE_RECOVER_AFTER_SECONDS', 1800),
        'warn_after' => (int) env('API_SECURITY_LIFECYCLE_WARN_AFTER', 1),
        'challenge_after' => (int) env('API_SECURITY_LIFECYCLE_CHALLENGE_AFTER', 2),
        'soft_block_after' => (int) env('API_SECURITY_LIFECYCLE_SOFT_BLOCK_AFTER', 4),
        'hard_block_after' => (int) env('API_SECURITY_LIFECYCLE_HARD_BLOCK_AFTER', 8),
        'challenge_seconds' => (int) env('API_SECURITY_LIFECYCLE_CHALLENGE_SECONDS', 120),
        'soft_block_seconds' => (int) env('API_SECURITY_LIFECYCLE_SOFT_BLOCK_SECONDS', 180),
        'hard_block_seconds' => (int) env('API_SECURITY_LIFECYCLE_HARD_BLOCK_SECONDS', 900),
    ],
    'abuse_signals' => [
        'enabled' => (bool) env('API_SECURITY_ABUSE_SIGNALS_ENABLED', true),
        'hash_salt' => (string) env('API_SECURITY_ABUSE_SIGNALS_HASH_SALT', env('APP_KEY', 'api-security')),
        'raw_retention_hours' => (int) env('API_SECURITY_ABUSE_SIGNALS_RAW_RETENTION_HOURS', 72),
        'aggregate_retention_days' => (int) env('API_SECURITY_ABUSE_SIGNALS_AGGREGATE_RETENTION_DAYS', 30),
        'max_metadata_bytes' => (int) env('API_SECURITY_ABUSE_SIGNALS_MAX_METADATA_BYTES', 4096),
    ],

    'cloudflare' => [
        /*
         | If enabled, reject API requests that do not include Cloudflare edge
         | signal headers. Keep disabled for local/dev environments.
         */
        'enforce_origin_lock' => (bool) env('API_SECURITY_ENFORCE_CLOUDFLARE_ORIGIN_LOCK', false),
        'require_trusted_proxy_for_forwarded_headers' => (bool) env('API_SECURITY_REQUIRE_TRUSTED_PROXY_FOR_FORWARDED_HEADERS', true),
        'presence_headers' => ['CF-Ray', 'CF-Connecting-IP'],
        'edge_policy_by_level' => [
            'L1' => 'waf_managed',
            'L2' => 'waf_plus_bot_fight',
            'L3' => 'waf_bot_fight_and_challenge',
        ],
    ],
];
