<?php

declare(strict_types=1);

final class PublicTaxonomyCutoverGuardFinding
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

final class PublicTaxonomyCutoverGuard
{
    /** @var array<int, string> */
    public const DEFAULT_SCAN_PATHS = [
        'packages/belluga/belluga_events/src/Application/Events',
        'packages/belluga/belluga_events/src/Http/Api/v1/Requests',
        'packages/belluga/belluga_events/src/Support/Validation',
        'app/Application/AccountProfiles',
        'app/Application/DiscoveryFilters',
        'app/Http/Api/v1/Controllers/DiscoveryFiltersController.php',
    ];

    /** @var array<int, string> */
    private const ALLOWED_CATEGORIES = [
        'canonical_runtime_facet_owner',
        'static_catalog_owner',
        'legacy_rejection',
        'legacy_cleanup',
        'compatibility_decoder',
        'test_fixture',
    ];

    /** @var list<PublicTaxonomyCutoverGuardFinding> */
    private array $blockedFindings = [];

    /** @var list<PublicTaxonomyCutoverGuardFinding> */
    private array $allowlistedFindings = [];

    /** @var list<PublicTaxonomyCutoverGuardFinding> */
    private array $configViolations = [];

    /** @var array<string, array{key:string,path:string,method:string,source_kind:string,category:string,owner:string,rationale:string}> */
    private array $allowlistByKey = [];

    /** @var array<string, true> */
    private array $observedAllowlistKeys = [];

    /**
     * @param  array<int, array{key?:string,path?:string,method?:string,source_kind?:string,category?:string,owner?:string,rationale?:string}>  $allowlist
     * @param  array<int, string>  $scanPaths
     */
    public function __construct(
        private readonly string $root,
        private readonly array $allowlist,
        private readonly array $scanPaths = self::DEFAULT_SCAN_PATHS,
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
                $this->configViolations[] = new PublicTaxonomyCutoverGuardFinding(
                    key: $key !== '' ? $key : '[allowlist]',
                    path: $path !== '' ? $path : '[allowlist]',
                    line: $index + 1,
                    method: $method !== '' ? $method : '[missing-method]',
                    sourceKind: $sourceKind !== '' ? $sourceKind : '[missing-source-kind]',
                    message: 'Allowlist entry must declare path, method, and source_kind so the reviewed baseline stays exact.',
                );

                continue;
            }

            if (! in_array($category, self::ALLOWED_CATEGORIES, true)) {
                $this->configViolations[] = new PublicTaxonomyCutoverGuardFinding(
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
                $this->configViolations[] = new PublicTaxonomyCutoverGuardFinding(
                    key: $key,
                    path: $path,
                    line: $index + 1,
                    method: $method,
                    sourceKind: $sourceKind,
                    message: 'Allowlist entry must declare owner and rationale so reviewers can distinguish canonical owners from convenience shims.',
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

        foreach ($this->scanPaths as $scanPath) {
            $absolutePath = $this->root.DIRECTORY_SEPARATOR.$scanPath;

            if (is_file($absolutePath)) {
                if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'php') {
                    $files[] = $scanPath;
                }

                continue;
            }

            if (! is_dir($absolutePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $absolutePath,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $absoluteFile = $file->getPathname();
                $files[] = ltrim(str_replace($this->root, '', $absoluteFile), DIRECTORY_SEPARATOR);
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
            $trimmed = ltrim($line);
            if (
                str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, '*')
            ) {
                continue;
            }

            $sourceKinds = $this->detectSourceKinds($line);
            if ($sourceKinds === []) {
                continue;
            }

            $method = $this->enclosingMethodName($lines, $index);
            if ($method === null) {
                continue;
            }

            foreach ($sourceKinds as $sourceKind) {
                $key = $this->findingKey($relativePath, $method, $sourceKind);
                $entry = $this->allowlistByKey[$key] ?? null;

                $message = match ($sourceKind) {
                    'raw_tags_reference' => sprintf(
                        'Method [%s] references legacy raw `tags` inside the public taxonomy cutover boundary.',
                        $method
                    ),
                    'runtime_facets_contract' => sprintf(
                        'Method [%s] shapes runtime facet payload/contracts inside the public taxonomy cutover boundary.',
                        $method
                    ),
                    'pseudo_canonical_bridge' => sprintf(
                        'Method [%s] references pseudo-canonical bridge field `taxonomy_terms_effective`.',
                        $method
                    ),
                    default => sprintf(
                        'Method [%s] matched guard source kind [%s].',
                        $method,
                        $sourceKind
                    ),
                };

                if ($entry === null) {
                    $this->blockedFindings[] = new PublicTaxonomyCutoverGuardFinding(
                        key: $key,
                        path: $relativePath,
                        line: $index + 1,
                        method: $method,
                        sourceKind: $sourceKind,
                        message: $message.' This finding lacks a reviewed baseline entry. Move the logic into the canonical owner or add a narrowly justified allowlist entry only if it is a true rejection/cleanup/catalog owner.',
                    );

                    continue;
                }

                $this->observedAllowlistKeys[$key] = true;
                $this->allowlistedFindings[] = new PublicTaxonomyCutoverGuardFinding(
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
     * @return list<string>
     */
    private function detectSourceKinds(string $line): array
    {
        $sourceKinds = [];

        if (str_contains($line, 'taxonomy_terms_effective')) {
            $sourceKinds[] = 'pseudo_canonical_bridge';
        }

        if ($this->containsLegacyTagsReference($line)) {
            $sourceKinds[] = 'raw_tags_reference';
        }

        if ($this->containsRuntimeFacetsContractReference($line)) {
            $sourceKinds[] = 'runtime_facets_contract';
        }

        return $sourceKinds;
    }

    private function containsLegacyTagsReference(string $line): bool
    {
        if (str_contains($line, "'tags'") || str_contains($line, '"tags"')) {
            return true;
        }

        return preg_match('/[\'"][^\'"]*\.tags(?:\.[^\'"]+)?[\'"]/', $line) === 1;
    }

    private function containsRuntimeFacetsContractReference(string $line): bool
    {
        return str_contains($line, 'discovery_filter_facets')
            || str_contains($line, 'taxonomy_options')
            || preg_match('/[\'"]filter_keys[\'"]/', $line) === 1;
    }

    private function collectStaleAllowlistEntries(): void
    {
        foreach ($this->allowlistByKey as $key => $entry) {
            if (isset($this->observedAllowlistKeys[$key])) {
                continue;
            }

            $this->configViolations[] = new PublicTaxonomyCutoverGuardFinding(
                key: $key,
                path: $entry['path'],
                line: 1,
                method: $entry['method'],
                sourceKind: $entry['source_kind'],
                message: 'Allowlist entry is stale or mismatched; the reviewed baseline no longer matches any repository finding.',
                category: $entry['category'],
                owner: $entry['owner'],
                rationale: $entry['rationale'],
            );
        }
    }

    private function emitFindings(): void
    {
        if ($this->allowlistedFindings !== []) {
            fwrite(STDOUT, "[TAX-GUARD] Allowlisted findings requiring audit:\n");

            foreach ($this->allowlistedFindings as $finding) {
                fwrite(
                    STDOUT,
                    sprintf(
                        " - [%s] %s:%d %s | category=%s owner=%s rationale=%s\n",
                        $finding->sourceKind,
                        $finding->path,
                        $finding->line,
                        $finding->message,
                        $finding->category ?? 'n/a',
                        $finding->owner ?? 'n/a',
                        $finding->rationale ?? 'n/a'
                    )
                );
            }

            fwrite(
                STDOUT,
                "[TAX-GUARD] Audit instructions: confirm each allowlisted finding is still either the canonical runtime-facet owner, the static catalog owner, or an approved legacy rejection/cleanup boundary. Reject convenience bridges, dual-path mirrors, and pseudo-canonical fallback fields.\n"
            );
        }

        if ($this->blockedFindings !== []) {
            fwrite(STDERR, "[TAX-GUARD] BLOCKED findings:\n");

            foreach ($this->blockedFindings as $finding) {
                fwrite(
                    STDERR,
                    sprintf(
                        " - [%s] %s:%d %s\n",
                        $finding->sourceKind,
                        $finding->path,
                        $finding->line,
                        $finding->message
                    )
                );
            }
        }

        if ($this->configViolations !== []) {
            fwrite(STDERR, "[TAX-GUARD] Allowlist/config violations:\n");

            foreach ($this->configViolations as $finding) {
                fwrite(
                    STDERR,
                    sprintf(
                        " - [%s] %s:%d %s\n",
                        $finding->sourceKind,
                        $finding->path,
                        $finding->line,
                        $finding->message
                    )
                );
            }
        }

        if ($this->blockedFindings === [] && $this->configViolations === []) {
            fwrite(STDOUT, "[TAX-GUARD] PASS - no blocked public taxonomy cutover findings detected.\n");
        }
    }

    private function findingKey(string $path, string $method, string $sourceKind): string
    {
        return $path.'::'.$method.'::'.$sourceKind;
    }
}

/**
 * @return array<int, array{key:string,path:string,method:string,source_kind:string,category:string,owner:string,rationale:string}>
 */
function loadPublicTaxonomyCutoverAllowlist(?string $customPath = null): array
{
    if ($customPath !== null) {
        /** @var array<int, array{key:string,path:string,method:string,source_kind:string,category:string,owner:string,rationale:string}> $loaded */
        $loaded = require $customPath;

        return $loaded;
    }

    return [
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventManagementService.php::normalizePayloadAndSchedule::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventManagementService.php',
            'method' => 'normalizePayloadAndSchedule',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_rejection',
            'owner' => 'events-write-contract',
            'rationale' => 'Canonical event write boundary must reject legacy tags input at the service layer.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventManagementService.php::normalizeOccurrences::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventManagementService.php',
            'method' => 'normalizeOccurrences',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_rejection',
            'owner' => 'events-write-contract',
            'rationale' => 'Occurrence writes must reject legacy tags input before canonical taxonomy normalization.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventOccurrenceSyncService.php::syncFromEvent::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventOccurrenceSyncService.php',
            'method' => 'syncFromEvent',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_cleanup',
            'owner' => 'events-occurrence-sync',
            'rationale' => 'Occurrence sync must actively unset stale legacy tags during canonical projection refresh.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventAggregateWriteService.php::update::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventAggregateWriteService.php',
            'method' => 'update',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_cleanup',
            'owner' => 'events-aggregate-write',
            'rationale' => 'Event aggregate updates must actively remove stale raw tags from the root event document.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Http/Api/v1/Requests/EventWriteRules.php::build::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Http/Api/v1/Requests/EventWriteRules.php',
            'method' => 'build',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_rejection',
            'owner' => 'events-request-rules',
            'rationale' => 'Validation rules must explicitly prohibit raw tags on create and update payloads.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Http/Api/v1/Requests/AgendaIndexRequest.php::rules::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Http/Api/v1/Requests/AgendaIndexRequest.php',
            'method' => 'rules',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_rejection',
            'owner' => 'agenda-public-request-rules',
            'rationale' => 'Public agenda requests must prohibit legacy tags query parameters in favor of taxonomy filters.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Support/Validation/EventPayloadFanoutGuard.php::validate::raw_tags_reference',
            'path' => 'packages/belluga/belluga_events/src/Support/Validation/EventPayloadFanoutGuard.php',
            'method' => 'validate',
            'source_kind' => 'raw_tags_reference',
            'category' => 'legacy_rejection',
            'owner' => 'event-payload-fanout-guard',
            'rationale' => 'Fanout validation must reject legacy tags before boundedness checks proceed.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php::fetchAgenda::runtime_facets_contract',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
            'method' => 'fetchAgenda',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-agenda-query-service',
            'rationale' => 'Agenda envelope is the canonical runtime-facet owner for public event filtering.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php::runAgendaQuery::runtime_facets_contract',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
            'method' => 'runAgendaQuery',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-agenda-query-service',
            'rationale' => 'Agenda query service executes the single aggregate query and injects runtime facet payloads into the public envelope.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php::buildAgendaFacetBranches::runtime_facets_contract',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
            'method' => 'buildAgendaFacetBranches',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-agenda-query-service',
            'rationale' => 'Agenda query service owns the runtime facet aggregate payload over the filtered universe.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php::formatAgendaDiscoveryFilterFacets::runtime_facets_contract',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
            'method' => 'formatAgendaDiscoveryFilterFacets',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-agenda-query-service',
            'rationale' => 'Agenda query service formats the runtime facet response contract consumed by Flutter and browser lanes.',
        ],
        [
            'key' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php::emptyAgendaDiscoveryFilterFacetsPayload::runtime_facets_contract',
            'path' => 'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
            'method' => 'emptyAgendaDiscoveryFilterFacetsPayload',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-agenda-query-service',
            'rationale' => 'Agenda query service owns the empty runtime-facet contract for no-result responses.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::publicPageEnvelope::runtime_facets_contract',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'publicPageEnvelope',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-discovery-query-service',
            'rationale' => 'Public discovery envelope is the canonical runtime-facet owner for account profile filtering.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::runPublicDiscoveryAggregate::runtime_facets_contract',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'runPublicDiscoveryAggregate',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-discovery-query-service',
            'rationale' => 'Public discovery aggregate owns the single-facet aggregation path over the filtered universe.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::formatPublicDiscoveryFilterFacets::runtime_facets_contract',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'formatPublicDiscoveryFilterFacets',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-discovery-query-service',
            'rationale' => 'Public discovery query service formats the runtime facet response contract consumed by Flutter and browser lanes.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileQueryService.php::emptyPublicDiscoveryFilterFacetsPayload::runtime_facets_contract',
            'path' => 'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'method' => 'emptyPublicDiscoveryFilterFacetsPayload',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'canonical_runtime_facet_owner',
            'owner' => 'public-discovery-query-service',
            'rationale' => 'Public discovery query service owns the empty runtime-facet contract for no-result responses.',
        ],
        [
            'key' => 'app/Application/DiscoveryFilters/DiscoveryFilterPublicCatalogService.php::catalogForSurface::runtime_facets_contract',
            'path' => 'app/Application/DiscoveryFilters/DiscoveryFilterPublicCatalogService.php',
            'method' => 'catalogForSurface',
            'source_kind' => 'runtime_facets_contract',
            'category' => 'static_catalog_owner',
            'owner' => 'public-discovery-filter-catalog',
            'rationale' => 'Static catalog service is the approved baseline metadata owner for filter labels/icons before runtime facet pruning.',
        ],
    ];
}

/**
 * @return array{root:string, allowlist:?string, scan_paths: array<int, string>}
 */
function parsePublicTaxonomyCutoverGuardCli(array $argv): array
{
    $root = realpath(__DIR__.'/..') ?: __DIR__.'/..';
    $allowlist = null;
    $scanPaths = PublicTaxonomyCutoverGuard::DEFAULT_SCAN_PATHS;

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $candidate = substr($argument, strlen('--root='));
            if ($candidate !== false && $candidate !== '') {
                $root = $candidate;
            }

            continue;
        }

        if (str_starts_with($argument, '--allowlist=')) {
            $candidate = substr($argument, strlen('--allowlist='));
            $allowlist = ($candidate === false || $candidate === '') ? null : $candidate;

            continue;
        }

        if (str_starts_with($argument, '--scan-path=')) {
            $candidate = substr($argument, strlen('--scan-path='));
            if ($candidate !== false && $candidate !== '') {
                $scanPaths[] = $candidate;
            }
        }
    }

    $scanPaths = array_values(array_unique($scanPaths));

    return [
        'root' => $root,
        'allowlist' => $allowlist,
        'scan_paths' => $scanPaths,
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = parsePublicTaxonomyCutoverGuardCli($_SERVER['argv'] ?? []);

    exit((new PublicTaxonomyCutoverGuard(
        $options['root'],
        loadPublicTaxonomyCutoverAllowlist($options['allowlist']),
        $options['scan_paths'],
    ))->run());
}
