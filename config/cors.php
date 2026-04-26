<?php

$appUrl = rtrim((string) env('APP_URL', 'http://nginx'), '/');
$appUrlParts = parse_url($appUrl) ?: [];

$appHost = strtolower((string) ($appUrlParts['host'] ?? 'nginx'));
$appScheme = strtolower((string) ($appUrlParts['scheme'] ?? 'http'));
$appPort = isset($appUrlParts['port']) ? ':' . $appUrlParts['port'] : '';

$escapedHost = preg_quote($appHost, '#');
$escapedPort = preg_quote($appPort, '#');

$allowedOrigins = [
    "{$appScheme}://{$appHost}{$appPort}",
];

$alternateScheme = $appScheme === 'https' ? 'http' : 'https';
$allowedOrigins[] = "{$alternateScheme}://{$appHost}{$appPort}";

$allowedOriginsPatterns = [
    "#^(?:http|https)://(?:[a-z0-9-]+\\.)+{$escapedHost}{$escapedPort}$#i",
];

return [
    'paths' => [
        'api/*',
        'admin/api',
        'admin/api/*',
    ],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginsPatterns,

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Cache-Control',
        'Content-Language',
        'Content-Type',
        'DNT',
        'If-Modified-Since',
        'Origin',
        'Range',
        'User-Agent',
        'X-App-Domain',
        'X-CSRF-TOKEN',
        'X-HTTP-Method-Override',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,
];
