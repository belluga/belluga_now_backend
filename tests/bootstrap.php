<?php

declare(strict_types=1);

$allowedLocalHosts = [
    'nginx',
    'localhost',
    '127.0.0.1',
    '::1',
];

$allowedLookup = array_fill_keys($allowedLocalHosts, true);

$normalizeHost = static function (string $host): string {
    return strtolower(trim($host, "[] \t\n\r\0\x0B"));
};

$resolveHostFromUrl = static function (string $url) use ($normalizeHost): ?string {
    $candidate = trim($url);
    if ($candidate === '') {
        return null;
    }

    if (! str_contains($candidate, '://')) {
        $candidate = "http://{$candidate}";
    }

    $host = parse_url($candidate, PHP_URL_HOST);
    if (! is_string($host) || $host === '') {
        return null;
    }

    return $normalizeHost($host);
};

$fail = static function (string $message): void {
    fwrite(STDERR, "[TEST-ENV-GUARD] {$message}\n");
    exit(1);
};

$appUrlRaw = getenv('APP_URL');
if (! is_string($appUrlRaw) || trim($appUrlRaw) === '') {
    $fail('APP_URL must be set and point to a local host for test execution.');
}

$appUrlHost = $resolveHostFromUrl($appUrlRaw);
if ($appUrlHost === null || ! isset($allowedLookup[$appUrlHost])) {
    $fail("APP_URL host '{$appUrlRaw}' is not local. Allowed hosts: ".implode(', ', $allowedLocalHosts).'.');
}

$appHostRaw = getenv('APP_HOST');
if (is_string($appHostRaw) && trim($appHostRaw) !== '') {
    $appHost = $normalizeHost($appHostRaw);
    if (! isset($allowedLookup[$appHost])) {
        $fail("APP_HOST '{$appHostRaw}' is not local. Allowed hosts: ".implode(', ', $allowedLocalHosts).'.');
    }
}

require __DIR__.'/../vendor/autoload.php';
