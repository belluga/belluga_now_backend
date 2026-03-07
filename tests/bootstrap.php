<?php

declare(strict_types=1);

$allowedLocalHosts = [
    'nginx',
    'localhost',
    '127.0.0.1',
    '::1',
];
$allowedLocalMongoHosts = [
    'mongo',
    'localhost',
    '127.0.0.1',
    '::1',
];

$allowedLookup = array_fill_keys($allowedLocalHosts, true);
$allowedMongoLookup = array_fill_keys($allowedLocalMongoHosts, true);

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

/**
 * @return list<string>
 */
$resolveHostsFromMongoUri = static function (string $uri) use ($normalizeHost): array {
    $candidate = trim($uri);
    if ($candidate === '') {
        return [];
    }

    $lower = strtolower($candidate);
    if (! str_starts_with($lower, 'mongodb://')) {
        return [];
    }

    $withoutScheme = substr($candidate, strlen('mongodb://'));
    $authority = strstr($withoutScheme, '/', true);
    if ($authority === false) {
        $authority = $withoutScheme;
    }

    if (str_contains($authority, '@')) {
        $authority = substr((string) $authority, (int) strrpos((string) $authority, '@') + 1);
    }

    $hosts = [];
    foreach (explode(',', (string) $authority) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        if (str_starts_with($entry, '[')) {
            $end = strpos($entry, ']');
            if ($end === false) {
                return [];
            }
            $host = substr($entry, 0, $end + 1);
        } else {
            $host = explode(':', $entry, 2)[0] ?? '';
        }

        $host = $normalizeHost($host);
        if ($host === '') {
            return [];
        }
        $hosts[] = $host;
    }

    return $hosts;
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

$mongoUriKeys = [
    'DB_URI',
    'DB_URI_LANDLORD',
    'DB_URI_TENANTS',
];

foreach ($mongoUriKeys as $mongoUriKey) {
    $mongoUri = getenv($mongoUriKey);
    if (! is_string($mongoUri) || trim($mongoUri) === '') {
        $fail("{$mongoUriKey} must be set for test execution.");
    }

    if (str_starts_with(strtolower(trim($mongoUri)), 'mongodb+srv://')) {
        $fail("{$mongoUriKey} cannot use mongodb+srv in tests; local mongodb:// is required.");
    }

    $mongoHosts = $resolveHostsFromMongoUri($mongoUri);
    if ($mongoHosts === []) {
        $fail("{$mongoUriKey} must be a valid mongodb:// URI pointing to local hosts.");
    }

    foreach ($mongoHosts as $mongoHost) {
        if (! isset($allowedMongoLookup[$mongoHost])) {
            $fail("{$mongoUriKey} host '{$mongoHost}' is not local. Allowed hosts: ".implode(', ', $allowedLocalMongoHosts).'.');
        }
    }
}

require __DIR__.'/../vendor/autoload.php';
