<?php

declare(strict_types=1);

final class AccountProfileQueryabilityGuardFinding
{
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly int $line,
        public readonly string $method,
        public readonly string $sourceKind,
        public readonly string $message,
        public readonly ?string $category = null,
        public readonly ?string $owner = null,
        public readonly ?string $rationale = null,
    ) {}
}

final class AccountProfileQueryabilityGuard
{
    /** @var array<int, string> */
    public const DEFAULT_SCAN_DIRS = ['app', 'packages'];

    /** @var array<int, string> */
    private const ALLOWED_CATEGORIES = [
        'canonical_gateway',
        'repair_readback',
        'audit_readback',
        'admin_readback',
        'migration',
        'seeder',
        'test_fixture',
    ];

    /** @var list<AccountProfileQueryabilityGuardFinding> */
    private array $blockedFindings = [];

    /** @var list<AccountProfileQueryabilityGuardFinding> */
    private array $allowlistedFindings = [];

    /** @var list<AccountProfileQueryabilityGuardFinding> */
    private array $configViolations = [];

    /** @var array<string, array{key:string,path:string,method:string,source_kind:string,category:string,owner:string,rationale:string}> */
    private array $allowlistByKey = [];

    /** @var array<string, true> */
    private array $observedAllowlistKeys = [];

    /**
     * @param  array<int, array{key?:string,path?:string,method?:string,source_kind?:string,category?:string,owner?:string,rationale?:string}>  $allowlist
     * @param  array<int, string>  $scanDirs
     */
    public function __construct(
        private readonly string $root,
        private readonly array $allowlist,
        private readonly array $scanDirs = self::DEFAULT_SCAN_DIRS,
    ) {}

    public function run(): int
    {
        $this->indexAllowlist();

        foreach ($this->phpFiles() as $relativePath) {
            $this->scanFile($relativePath);
        }

        $this->collectStaleAllowlistEntries();
        $this->emitFindings();

        return ($this->blockedFindings === [] && $this->configViolations === []) ? 0 : 1;
    }

    private function indexAllowlist(): void
    {
        foreach ($this->allowlist as $index => $entry) {
            $path = trim((string) ($entry['path'] ?? ''));
            $method = trim((string) ($entry['method'] ?? ''));
            $sourceKind = trim((string) ($entry['source_kind'] ?? ''));
            $category = trim((string) ($entry['category'] ?? ''));
            $owner = trim((string) ($entry['owner'] ?? ''));
            $rationale = trim((string) ($entry['rationale'] ?? ''));
            $key = trim((string) ($entry['key'] ?? $this->findingKey($path, $method, $sourceKind)));

            if ($path === '' || $method === '' || $sourceKind === '') {
                $this->configViolations[] = new AccountProfileQueryabilityGuardFinding(
                    key: $key !== '' ? $key : '[allowlist]',
                    path: $path !== '' ? $path : '[allowlist]',
                    line: $index + 1,
                    method: $method !== '' ? $method : '[missing-method]',
                    sourceKind: $sourceKind !== '' ? $sourceKind : '[missing-source-kind]',
                    message: 'Allowlist entry must declare path, method, and source_kind to keep the reviewed baseline specific.',
                );

                continue;
            }

            if (! in_array($category, self::ALLOWED_CATEGORIES, true)) {
                $this->configViolations[] = new AccountProfileQueryabilityGuardFinding(
                    key: $key,
                    path: $path,
                    line: $index + 1,
                    method: $method,
                    sourceKind: $sourceKind,
                    message: 'Allowlist entry uses an invalid category. Valid categories: '.implode(', ', self::ALLOWED_CATEGORIES).'.',
                );

                continue;
            }

            if ($owner === '' || $rationale === '') {
                $this->configViolations[] = new AccountProfileQueryabilityGuardFinding(
                    key: $key,
                    path: $path,
                    line: $index + 1,
                    method: $method,
                    sourceKind: $sourceKind,
                    message: 'Allowlist entry must declare owner and rationale so reviewers can audit why this finding is not a convenience bypass.',
                );

                continue;
            }

            $this->allowlistByKey[$key] = [
                'key' => $key,
                'path' => $path,
                'method' => $method,
                'source_kind' => $sourceKind,
                'category' => $category,
                'owner' => $owner,
                'rationale' => $rationale,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach ($this->scanDirs as $dir) {
            $absoluteDir = $this->root.DIRECTORY_SEPARATOR.$dir;
            if (! is_dir($absoluteDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $absoluteDir,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $absolutePath = $file->getPathname();
                $files[] = ltrim(str_replace($this->root, '', $absolutePath), DIRECTORY_SEPARATOR);
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function scanFile(string $relativePath): void
    {
        $absolutePath = $this->root.DIRECTORY_SEPARATOR.$relativePath;
        $lines = @file($absolutePath);
        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            $sourceKind = $this->detectSourceKind($line);
            if ($sourceKind === null) {
                continue;
            }

            $method = $this->enclosingMethodName($lines, $index);
            if ($method === null) {
                continue;
            }

            $block = $this->blockSource($lines, $index);
            if (! $this->isOperationalListSurface($relativePath, $method, $block)) {
                continue;
            }

            $key = $this->findingKey($relativePath, $method, $sourceKind);
            $message = sprintf(
                'Operational AccountProfile query surface [%s] uses raw %s ownership.',
                $method,
                $sourceKind
            );

            $entry = $this->allowlistByKey[$key] ?? null;
            if ($entry === null) {
                $this->blockedFindings[] = new AccountProfileQueryabilityGuardFinding(
                    key: $key,
                    path: $relativePath,
                    line: $index + 1,
                    method: $method,
                    sourceKind: $sourceKind,
                    message: $message.' This finding lacks a reviewed baseline entry. Add one only if this truly belongs to canonical_gateway, repair/audit/admin readback, migration, seeder, or test fixture scope.',
                );

                continue;
            }

            $this->observedAllowlistKeys[$key] = true;
            $this->allowlistedFindings[] = new AccountProfileQueryabilityGuardFinding(
                key: $key,
                path: $relativePath,
                line: $index + 1,
                method: $method,
                sourceKind: $sourceKind,
                message: $message,
                category: $entry['category'],
                owner: $entry['owner'],
                rationale: $entry['rationale'],
            );
        }
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function enclosingMethodName(array $lines, int $index): ?string
    {
        for ($cursor = $index; $cursor >= 0; $cursor--) {
            if (preg_match('/function\s+([A-Za-z0-9_]+)\s*\(/', $lines[$cursor] ?? '', $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function blockSource(array $lines, int $index, int $radius = 40): string
    {
        $slice = array_slice($lines, $index, $radius);

        return implode('', $slice);
    }

    private function detectSourceKind(string $line): ?string
    {
        return match (true) {
            str_contains($line, 'AccountProfile::query(') => 'query',
            str_contains($line, 'AccountProfile::raw(') => 'raw',
            str_contains($line, 'AccountProfile::onlyTrashed(') => 'onlyTrashed',
            str_contains($line, 'AccountProfile::withTrashed(') => 'withTrashed',
            default => null,
        };
    }

    private function isOperationalListSurface(string $relativePath, string $method, string $block): bool
    {
        $leadingBlock = substr($block, 0, 220);
        if (
            str_contains($leadingBlock, "whereRaw(['_id' => ['\$exists' => false]])")
            || str_contains($leadingBlock, 'whereRaw(["_id" => ["$exists" => false]])')
        ) {
            return false;
        }

        $normalizedMethod = strtolower($method);
        $hasTerminal = str_contains($block, 'paginate(')
            || str_contains($block, '->get(')
            || str_contains($block, '->pluck(')
            || str_contains($block, 'aggregate(')
            || str_contains($block, 'buildPaginator(');
        if (! $hasTerminal) {
            return $this->isCapabilityBoundBuilderMethod($normalizedMethod, $block);
        }

        $hasOperationalName = preg_match(
            '/paginate|candidate|public|assertmemberprofilesexist/',
            $normalizedMethod
        ) === 1;
        $hasCapabilityShape = $this->hasProfileTypeShape($block);

        if ($relativePath === 'app/Application/AccountProfiles/AccountProfileRegistryManagementService.php') {
            return $hasCapabilityShape;
        }

        return $hasOperationalName;
    }

    private function isCapabilityBoundBuilderMethod(string $normalizedMethod, string $block): bool
    {
        $hasCapabilityShape = $this->hasProfileTypeShape($block)
            || str_contains($block, 'queryable_only');

        if (! $hasCapabilityShape) {
            return false;
        }

        return preg_match('/candidate|publicnear|publicpaginate|paginate/', $normalizedMethod) === 1;
    }

    private function hasProfileTypeShape(string $block): bool
    {
        return str_contains($block, "'profile_type'")
            || str_contains($block, '"profile_type"');
    }

    private function collectStaleAllowlistEntries(): void
    {
        foreach ($this->allowlistByKey as $key => $entry) {
            if (isset($this->observedAllowlistKeys[$key])) {
                continue;
            }

            $this->configViolations[] = new AccountProfileQueryabilityGuardFinding(
                key: $key,
                path: $entry['path'],
                line: 0,
                method: $entry['method'],
                sourceKind: $entry['source_kind'],
                message: 'Allowlist baseline entry is stale or mismatched. Remove it or update the reviewed finding key after the canonical query owner changes.',
                category: $entry['category'],
                owner: $entry['owner'],
                rationale: $entry['rationale'],
            );
        }
    }

    private function emitFindings(): void
    {
        if ($this->allowlistedFindings !== []) {
            fwrite(STDOUT, "[QRY-GUARD] Allowlisted findings requiring audit:\n");
            foreach ($this->allowlistedFindings as $finding) {
                fwrite(
                    STDOUT,
                    sprintf(
                        " - [%s] %s:%d %s (%s/%s)\n   Owner: %s\n   Rationale: %s\n   Audit checklist:\n     1. Confirm this is not a user-facing selector/list/candidate path outside the canonical gateway.\n     2. Confirm it cannot leak non-queryable profiles into admin or public choices.\n     3. Confirm the query remains bounded and indexable.\n     4. Confirm a feature or guard test still proves the downstream behavior.\n     5. Confirm this code was not moved here merely to bypass the rule.\n",
                        $finding->category ?? 'allowlist',
                        $finding->path,
                        $finding->line,
                        $finding->message,
                        $finding->method,
                        $finding->sourceKind,
                        $finding->owner ?? 'missing-owner',
                        $finding->rationale ?? 'missing-rationale',
                    )
                );
            }
        }

        if ($this->blockedFindings === [] && $this->configViolations === []) {
            fwrite(STDOUT, "[QRY-GUARD] PASS - no blocked AccountProfile operational query findings detected.\n");

            return;
        }

        fwrite(STDERR, "[QRY-GUARD] FAIL - AccountProfile queryability guard violations detected:\n");
        foreach ([...$this->blockedFindings, ...$this->configViolations] as $finding) {
            fwrite(
                STDERR,
                sprintf(
                    " - %s%s%s\n   Finding: %s\n   Resolution: move the operational query into the canonical gateway, or add a reviewed allowlist baseline entry with path + method + source_kind + category + owner + rationale only when the exception truly belongs to the approved whitelist.\n",
                    $finding->path,
                    $finding->line > 0 ? ':'.$finding->line : '',
                    $finding->method !== '' ? " [{$finding->method}/{$finding->sourceKind}]" : '',
                    $finding->message,
                )
            );
        }
    }

    private function findingKey(string $path, string $method, string $sourceKind): string
    {
        return "{$path}::{$method}::{$sourceKind}";
    }
}

/**
 * @return array<int, array{key:string,path:string,method:string,source_kind:string,category:string,owner:string,rationale:string}>
 */
function defaultAccountProfileQueryabilityAllowlist(): array
{
    $owner = 'TODO-v0.2.0+8-account-profile-queryability-navigation-contract';

    return [
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::paginate::query',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'paginate',
            'source_kind' => 'query',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical tenant-admin AccountProfile index gateway, including queryable_only selector filtering.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::publicPageEnvelope::query',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'publicPageEnvelope',
            'source_kind' => 'query',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical public discovery envelope owner that hydrates the ordered page result from the aggregated candidate universe.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::runPublicDiscoveryAggregate::raw',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'runPublicDiscoveryAggregate',
            'source_kind' => 'raw',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical aggregate pipeline owner for public discovery runtime facets and universe-wide filtered paging.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::publicNear::raw',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'publicNear',
            'source_kind' => 'raw',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical geo query owner for public near discovery with type-set filtering pushed into the aggregate pipeline.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::publicNear::query',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'publicNear',
            'source_kind' => 'query',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical follow-up ordered-id hydration for public near discovery results.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileNestedGroupService.php::publicProfilesById::query',
            'path' => 'app/Application/AccountProfiles/AccountProfileNestedGroupService.php',
            'method' => 'publicProfilesById',
            'source_kind' => 'query',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical nested-group public projection suppressing non-queryable and non-public members.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileRegistryManagementService.php::update::query',
            'path' => 'app/Application/AccountProfiles/AccountProfileRegistryManagementService.php',
            'method' => 'update',
            'source_kind' => 'query',
            'category' => 'admin_readback',
            'owner' => $owner,
            'rationale' => 'Tenant-admin profile-type mutation needs impacted account-profile ids to refresh or delete map projections after capability changes.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileRegistryManagementService.php::previewDisableProjectionCount::query',
            'path' => 'app/Application/AccountProfiles/AccountProfileRegistryManagementService.php',
            'method' => 'previewDisableProjectionCount',
            'source_kind' => 'query',
            'category' => 'admin_readback',
            'owner' => $owner,
            'rationale' => 'Tenant-admin projection impact preview counts affected account profiles before disabling a profile type visual/POI path.',
        ],
        [
            'key' => 'app/Integration/Events/AccountProfileResolverAdapter.php::queryPhysicalHostCandidates::query',
            'path' => 'app/Integration/Events/AccountProfileResolverAdapter.php',
            'method' => 'queryPhysicalHostCandidates',
            'source_kind' => 'query',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical physical-host candidate selector using the POI-enabled queryable type set.',
        ],
        [
            'key' => 'app/Integration/Events/AccountProfileResolverAdapter.php::queryRelatedAccountProfileCandidates::query',
            'path' => 'app/Integration/Events/AccountProfileResolverAdapter.php',
            'method' => 'queryRelatedAccountProfileCandidates',
            'source_kind' => 'query',
            'category' => 'canonical_gateway',
            'owner' => $owner,
            'rationale' => 'Canonical related-profile candidate selector excluding non-queryable and venue-only candidates.',
        ],
    ];
}

/**
 * @return array{root:string, allowlist_path:?string, scan_dirs:array<int, string>}
 */
function resolveAccountProfileQueryabilityGuardOptions(array $argv): array
{
    $root = dirname(__DIR__);
    $allowlistPath = null;
    $scanDirs = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
            continue;
        }

        if (str_starts_with($argument, '--allowlist=')) {
            $allowlistPath = substr($argument, strlen('--allowlist='));
            continue;
        }

        if (str_starts_with($argument, '--scan-dir=')) {
            $scanDir = trim((string) substr($argument, strlen('--scan-dir=')));
            if ($scanDir !== '') {
                $scanDirs[] = $scanDir;
            }
        }
    }

    return [
        'root' => rtrim($root, DIRECTORY_SEPARATOR),
        'allowlist_path' => $allowlistPath !== null && $allowlistPath !== ''
            ? $allowlistPath
            : null,
        'scan_dirs' => $scanDirs !== [] ? $scanDirs : AccountProfileQueryabilityGuard::DEFAULT_SCAN_DIRS,
    ];
}

/**
 * @return array<int, array{key?:string,path?:string,method?:string,source_kind?:string,category?:string,owner?:string,rationale?:string}>
 */
function loadAccountProfileQueryabilityAllowlist(?string $allowlistPath): array
{
    if ($allowlistPath === null) {
        return defaultAccountProfileQueryabilityAllowlist();
    }

    $loaded = require $allowlistPath;

    return is_array($loaded) ? $loaded : [];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = resolveAccountProfileQueryabilityGuardOptions($_SERVER['argv'] ?? []);
    $allowlist = loadAccountProfileQueryabilityAllowlist($options['allowlist_path']);
    $guard = new AccountProfileQueryabilityGuard(
        $options['root'],
        $allowlist,
        $options['scan_dirs'],
    );
    exit($guard->run());
}
