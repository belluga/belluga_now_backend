<?php

declare(strict_types=1);

final class ArchitectureViolation
{
    public function __construct(
        public string $ruleId,
        public string $file,
        public int $line,
        public string $message
    ) {}
}

final class ArchitectureGuardrailRunner
{
    /** @var list<ArchitectureViolation> */
    private array $violations = [];

    public function __construct(private readonly string $repoRoot) {}

    public function run(): int
    {
        $abilityCatalog = $this->loadAbilityCatalog();

        if ($abilityCatalog !== null) {
            $this->checkAbilityCatalogSync($abilityCatalog);
        }

        $this->checkTenantAuthAbilityGuardrails();
        $this->checkMongoModelCastBan();
        $this->checkPackageSourceCoupling();
        $this->checkTenantMigrationPathRegistration();
        $this->checkCiLocalTestRuntimeGuardrails();
        $this->checkApiSecurityHardeningBaseline();

        if ($this->violations === []) {
            fwrite(STDOUT, "[ARCH-GUARDRAILS] PASS - no architecture violations found.\n");

            return 0;
        }

        fwrite(STDERR, "[ARCH-GUARDRAILS] FAIL - architecture violations detected:\n");

        foreach ($this->violations as $violation) {
            fwrite(
                STDERR,
                sprintf(
                    " - [%s] %s:%d %s\n",
                    $violation->ruleId,
                    $violation->file,
                    $violation->line,
                    $violation->message
                )
            );
        }

        return 1;
    }

    /**
     * @return array<string, true>|null
     */
    private function loadAbilityCatalog(): ?array
    {
        $path = $this->repoRoot.'/config/abilities.php';
        if (! is_file($path)) {
            $this->addViolation(
                'LAR-ABILITY-CATALOG',
                'config/abilities.php',
                1,
                'Missing ability catalog file.'
            );

            return null;
        }

        $raw = require $path;
        if (! is_array($raw) || ! isset($raw['all']) || ! is_array($raw['all'])) {
            $this->addViolation(
                'LAR-ABILITY-CATALOG',
                'config/abilities.php',
                1,
                'Ability catalog must define an array in key `all`.'
            );

            return null;
        }

        $catalog = [];
        foreach ($raw['all'] as $ability) {
            if (is_string($ability) && $ability !== '') {
                $catalog[$ability] = true;
            }
        }

        return $catalog;
    }

    /**
     * @param  array<string, true>  $catalog
     */
    private function checkAbilityCatalogSync(array $catalog): void
    {
        $targets = $this->collectPhpFiles(['routes', 'app', 'packages']);

        foreach ($targets as $relativePath) {
            $absolutePath = $this->repoRoot.'/'.$relativePath;
            $lines = @file($absolutePath);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                if (preg_match_all("/['\"]abilities:([^'\"]+)['\"]/", $line, $matches) === 1 || (isset($matches[0]) && $matches[0] !== [])) {
                    foreach ($matches[1] as $rawList) {
                        $this->assertAbilityListInCatalog((string) $rawList, $catalog, $relativePath, $lineNumber);
                    }
                }

                if (preg_match_all("/['\"]ability['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $line, $abilityMatches) === 1 || (isset($abilityMatches[0]) && $abilityMatches[0] !== [])) {
                    foreach ($abilityMatches[1] as $ability) {
                        $ability = trim((string) $ability);
                        if ($ability === '' || $ability === '*') {
                            continue;
                        }
                        if (! isset($catalog[$ability])) {
                            $this->addViolation(
                                'LAR-ABILITY-CATALOG',
                                $relativePath,
                                $lineNumber,
                                "Ability `{$ability}` is referenced but not declared in config/abilities.php."
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $catalog
     */
    private function assertAbilityListInCatalog(string $rawList, array $catalog, string $file, int $line): void
    {
        $abilities = array_filter(array_map('trim', explode(',', $rawList)));

        foreach ($abilities as $ability) {
            if ($ability === '*' || $ability === '') {
                continue;
            }
            if (! isset($catalog[$ability])) {
                $this->addViolation(
                    'LAR-ABILITY-CATALOG',
                    $file,
                    $line,
                    "Ability `{$ability}` is referenced but not declared in config/abilities.php."
                );
            }
        }
    }

    private function checkTenantAuthAbilityGuardrails(): void
    {
        $tenantRouteFiles = [
            'routes/api/tenant_api_v1.php',
            'routes/api/project_tenant_admin_api_v1.php',
            'routes/api/project_tenant_public_api_v1.php',
            'routes/api/public_tenant_maybe_api_v1.php',
        ];

        foreach ($tenantRouteFiles as $relativePath) {
            $absolutePath = $this->repoRoot.'/'.$relativePath;
            $lines = @file($absolutePath);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                if (! str_contains($line, '->middleware(')) {
                    continue;
                }
                if (! str_contains($line, 'auth:sanctum')) {
                    continue;
                }
                if (! str_contains($line, 'abilities:')) {
                    continue;
                }
                if (str_contains($line, 'CheckTenantAccess::class')) {
                    continue;
                }

                $this->addViolation(
                    'LAR-TENANT-ACCESS-GUARD',
                    $relativePath,
                    $index + 1,
                    'Tenant route statement uses auth:sanctum + abilities without CheckTenantAccess::class.'
                );
            }
        }
    }

    private function checkMongoModelCastBan(): void
    {
        $modelFiles = $this->collectPhpFiles(['app/Models', 'packages']);

        foreach ($modelFiles as $relativePath) {
            if (! str_contains($relativePath, '/Models/') && ! str_starts_with($relativePath, 'app/Models/')) {
                continue;
            }

            $absolutePath = $this->repoRoot.'/'.$relativePath;
            $lines = @file($absolutePath);
            if (! is_array($lines)) {
                continue;
            }

            $content = implode('', $lines);
            $isMongoBacked = str_contains($content, 'extends DocumentModel')
                || str_contains($content, 'MongoDB\\Laravel\\Eloquent\\Model');

            if (! $isMongoBacked) {
                continue;
            }

            $insideCasts = false;
            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;
                $trimmed = trim($line);

                if (! $insideCasts && preg_match('/protected\\s+\\$casts\\s*=\\s*\\[/', $line) === 1) {
                    $insideCasts = true;

                    continue;
                }

                if ($insideCasts && str_contains($line, '];')) {
                    $insideCasts = false;

                    continue;
                }

                if (! $insideCasts) {
                    continue;
                }

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                if (preg_match('/=>\\s*[\'"](array|json|object)[\'"]/i', $line, $matches) === 1) {
                    $type = strtolower((string) $matches[1]);
                    $this->addViolation(
                        'LAR-MONGO-CAST-BAN',
                        $relativePath,
                        $lineNumber,
                        "Mongo-backed model uses forbidden cast type `{$type}` in \$casts."
                    );
                }
            }
        }
    }

    private function checkPackageSourceCoupling(): void
    {
        $packageFiles = $this->collectPhpFiles(['packages']);

        foreach ($packageFiles as $relativePath) {
            if (! preg_match('#^packages/[^/]+/[^/]+/src/.+\\.php$#', $relativePath)) {
                continue;
            }

            $absolutePath = $this->repoRoot.'/'.$relativePath;
            $lines = @file($absolutePath);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                if (preg_match('/\\bApp\\\\\\\\/', $line) !== 1) {
                    continue;
                }

                $this->addViolation(
                    'LAR-PACKAGE-BOUNDARY',
                    $relativePath,
                    $index + 1,
                    'Package src references `App\\` namespace; use contracts/adapters boundary.'
                );
            }
        }
    }

    private function checkTenantMigrationPathRegistration(): void
    {
        $multitenancyPath = $this->repoRoot.'/config/multitenancy.php';
        $content = @file_get_contents($multitenancyPath);
        if (! is_string($content)) {
            $this->addViolation(
                'LAR-TENANT-MIGRATION-PATHS',
                'config/multitenancy.php',
                1,
                'Cannot read multitenancy configuration file.'
            );

            return;
        }

        if (preg_match("/['\"]tenant_migration_paths['\"]\\s*=>\\s*\\[(.*?)\\]/s", $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->addViolation(
                'LAR-TENANT-MIGRATION-PATHS',
                'config/multitenancy.php',
                1,
                'Missing tenant_migration_paths definition.'
            );

            return;
        }

        $block = (string) $matches[1][0];
        $blockOffset = (int) $matches[1][1];
        $registered = [];

        if (preg_match_all("/['\"]([^'\"]+)['\"]/", $block, $pathMatches, PREG_OFFSET_CAPTURE) === 1 || (isset($pathMatches[0]) && $pathMatches[0] !== [])) {
            foreach ($pathMatches[1] as $pathMatch) {
                $registered[trim((string) $pathMatch[0])] = true;
            }
        }

        $packageMigrationDirs = glob($this->repoRoot.'/packages/*/*/database/migrations', GLOB_ONLYDIR) ?: [];
        sort($packageMigrationDirs);

        foreach ($packageMigrationDirs as $absoluteDir) {
            $relativeDir = $this->relativePath($absoluteDir);
            if (! isset($registered[$relativeDir])) {
                $line = substr_count(substr($content, 0, $blockOffset), "\n") + 1;
                $this->addViolation(
                    'LAR-TENANT-MIGRATION-PATHS',
                    'config/multitenancy.php',
                    $line,
                    "Tenant migration path `{$relativeDir}` is missing from tenant_migration_paths."
                );
            }
        }
    }

    private function checkCiLocalTestRuntimeGuardrails(): void
    {
        $relativePath = '.github/workflows/ci.yml';
        $absolutePath = $this->repoRoot.'/'.$relativePath;
        $content = @file_get_contents($absolutePath);

        if (! is_string($content)) {
            $this->addViolation(
                'LAR-CI-LOCAL-TEST-RUNTIME',
                $relativePath,
                1,
                'Cannot read Laravel CI workflow file.'
            );

            return;
        }

        $requiredPairs = [
            'APP_URL' => 'http://nginx',
            'APP_HOST' => 'nginx',
        ];

        foreach ($requiredPairs as $key => $expected) {
            if (preg_match("/^\\s*{$key}:\\s*(\\S+)\\s*$/m", $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                $this->addViolation(
                    'LAR-CI-LOCAL-TEST-RUNTIME',
                    $relativePath,
                    1,
                    "Missing {$key} definition in CI workflow test env."
                );

                continue;
            }

            $actual = trim((string) $matches[1][0], "\"'");
            $line = substr_count(substr($content, 0, (int) $matches[0][1]), "\n") + 1;
            if ($actual !== $expected) {
                $this->addViolation(
                    'LAR-CI-LOCAL-TEST-RUNTIME',
                    $relativePath,
                    $line,
                    "{$key} must be `{$expected}` in CI test env (found `{$actual}`)."
                );
            }
        }

        if (preg_match_all('/^\\s*(DB_URI(?:_LANDLORD|_TENANTS)?):\\s*(\\S+)\\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE) < 1) {
            $this->addViolation(
                'LAR-CI-LOCAL-TEST-RUNTIME',
                $relativePath,
                1,
                'CI workflow must define DB_URI, DB_URI_LANDLORD, and DB_URI_TENANTS.'
            );

            return;
        }

        $allowedMongoHosts = [
            'localhost' => true,
            '127.0.0.1' => true,
            '::1' => true,
            'mongo' => true,
        ];

        foreach ($matches[1] as $index => $keyMatch) {
            $key = (string) $keyMatch[0];
            $dsn = trim((string) $matches[2][$index][0], "\"'");
            $line = substr_count(substr($content, 0, (int) $keyMatch[1]), "\n") + 1;
            $issues = $this->validateMongoDsnHosts($dsn, $allowedMongoHosts);
            foreach ($issues as $issue) {
                $this->addViolation(
                    'LAR-CI-LOCAL-TEST-RUNTIME',
                    $relativePath,
                    $line,
                    "{$key}: {$issue}"
                );
            }
        }
    }

    private function checkApiSecurityHardeningBaseline(): void
    {
        $configPath = 'config/api_security.php';
        $configAbsolutePath = $this->repoRoot.'/'.$configPath;
        if (! is_file($configAbsolutePath)) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'Missing api security baseline configuration file.'
            );

            return;
        }

        $configContent = @file_get_contents($configAbsolutePath);
        if (! is_string($configContent)) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'Cannot read api_security configuration file.'
            );

            return;
        }

        if (
            ! str_contains($configContent, "'levels' => [")
            || ! preg_match("/['\"]L1['\"]\s*=>\s*\[/", $configContent)
            || ! preg_match("/['\"]L2['\"]\s*=>\s*\[/", $configContent)
            || ! preg_match("/['\"]L3['\"]\s*=>\s*\[/", $configContent)
        ) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security levels must define L1, L2, and L3 arrays.'
            );
        }

        if (preg_match("/['\"]observe_mode['\"]\s*=>/", $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security must define observe_mode rollout control.'
            );
        }

        if (preg_match('/[\'"]risk_matrix[\'"]\s*=>\s*\$riskMatrix/', $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security risk_matrix must define critical endpoint mappings.'
            );
        }

        foreach (['ticketing_checkout', 'ticketing_admission', 'settings_namespace_patch', 'events_admin_mutation'] as $requiredDomain) {
            if (! str_contains($configContent, "'domain' => '{$requiredDomain}'")) {
                $this->addViolation(
                    'LAR-API-SECURITY-BASELINE',
                    $configPath,
                    1,
                    "api_security risk_matrix is missing required domain `{$requiredDomain}`."
                );
            }
        }

        if (preg_match('/[\'"]route_overrides[\'"]\s*=>\s*\$routeOverrides/', $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security route_overrides must define at least one endpoint mapping.'
            );
        }

        if (preg_match("/['\"]methods['\"]\s*=>\s*\[[^\]]+\]/", $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security risk_matrix entries must declare methods[] to keep endpoint mapping deterministic.'
            );
        }

        if (
            preg_match("~'domain'\\s*=>\\s*'ticketing_checkout'.*?'level'\\s*=>\\s*'L3'.*?'require_idempotency'\\s*=>\\s*true~s", $configContent) !== 1
            || preg_match("~'domain'\\s*=>\\s*'ticketing_admission'.*?'level'\\s*=>\\s*'L3'.*?'require_idempotency'\\s*=>\\s*true~s", $configContent) !== 1
        ) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'L3 critical domains in api_security risk_matrix must enforce require_idempotency=true.'
            );
        }

        if (preg_match("/['\"]tenant_overrides['\"]\s*=>\s*\[/", $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security must define tenant_overrides for hierarchy resolution.'
            );
        }

        if (preg_match("/['\"]lifecycle['\"]\s*=>\s*\[/", $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security must define lifecycle escalation settings.'
            );
        }

        if (preg_match("/['\"]abuse_signals['\"]\s*=>\s*\[/", $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security must define abuse_signals retention settings.'
            );
        }

        if (preg_match("/['\"]require_trusted_proxy_for_forwarded_headers['\"]\s*=>/", $configContent) !== 1) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $configPath,
                1,
                'api_security.cloudflare must define require_trusted_proxy_for_forwarded_headers.'
            );
        }

        $bootstrapPath = 'bootstrap/app.php';
        $bootstrapContent = @file_get_contents($this->repoRoot.'/'.$bootstrapPath);
        if (! is_string($bootstrapContent)) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $bootstrapPath,
                1,
                'Cannot read bootstrap/app.php for middleware checks.'
            );

            return;
        }

        if (! str_contains($bootstrapContent, 'ApiSecurityHardening::class')) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $bootstrapPath,
                1,
                'ApiSecurityHardening middleware must be registered in bootstrap/app.php.'
            );
        }

        if (! str_contains($bootstrapContent, 'Request::HEADER_X_FORWARDED_FOR')) {
            $this->addViolation(
                'LAR-API-SECURITY-BASELINE',
                $bootstrapPath,
                1,
                'trustProxies must include HEADER_X_FORWARDED_FOR for proxy-aware client identity.'
            );
        }
    }

    /**
     * @param  array<string, true>  $allowedHosts
     * @return list<string>
     */
    private function validateMongoDsnHosts(string $dsn, array $allowedHosts): array
    {
        $issues = [];
        $dsn = trim($dsn);
        $lower = strtolower($dsn);

        if ($dsn === '') {
            return ['Mongo DSN cannot be empty.'];
        }

        if (str_starts_with($lower, 'mongodb+srv://')) {
            return ['mongodb+srv is forbidden for test runtime; use local mongodb:// DSN.'];
        }

        if (! str_starts_with($lower, 'mongodb://')) {
            return ['DSN must start with mongodb://'];
        }

        $withoutScheme = substr($dsn, strlen('mongodb://'));
        $authority = strstr($withoutScheme, '/', true);
        if ($authority === false) {
            $authority = $withoutScheme;
        }

        if (str_contains($authority, '@')) {
            $authority = substr((string) $authority, (int) strrpos((string) $authority, '@') + 1);
        }

        $hosts = array_filter(array_map('trim', explode(',', (string) $authority)));
        if ($hosts === []) {
            return ['DSN must contain at least one host in the authority section.'];
        }

        foreach ($hosts as $hostEntry) {
            $host = $this->normalizeMongoHost((string) $hostEntry);
            if ($host === null) {
                $issues[] = "cannot parse host from `{$hostEntry}`";

                continue;
            }

            if (! isset($allowedHosts[$host])) {
                $issues[] = "host `{$host}` is not local; allowed hosts: ".implode(', ', array_keys($allowedHosts));
            }
        }

        return $issues;
    }

    private function normalizeMongoHost(string $hostEntry): ?string
    {
        $hostEntry = trim($hostEntry);
        if ($hostEntry === '') {
            return null;
        }

        if (str_starts_with($hostEntry, '[')) {
            $end = strpos($hostEntry, ']');
            if ($end === false) {
                return null;
            }

            return strtolower(trim(substr($hostEntry, 0, $end + 1), "[] \t\n\r\0\x0B"));
        }

        $host = explode(':', $hostEntry, 2)[0] ?? '';
        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));

        return $host === '' ? null : $host;
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function collectPhpFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            $absoluteRoot = $this->repoRoot.'/'.$root;
            if (! is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }
                if (! str_ends_with($fileInfo->getFilename(), '.php')) {
                    continue;
                }
                $files[] = $this->relativePath($fileInfo->getPathname());
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<array{line:int,text:string}>
     */
    private function extractStatements(string $content): array
    {
        $statements = [];
        if (preg_match_all('/[^;]*;/s', $content, $matches, PREG_OFFSET_CAPTURE) !== 1 && $matches[0] === []) {
            return $statements;
        }

        foreach ($matches[0] as $match) {
            $text = (string) $match[0];
            $offset = (int) $match[1];
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $statements[] = [
                'line' => $line,
                'text' => $text,
            ];
        }

        return $statements;
    }

    private function relativePath(string $absolutePath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->repoRoot), '/');
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }

    private function addViolation(string $ruleId, string $file, int $line, string $message): void
    {
        $this->violations[] = new ArchitectureViolation($ruleId, $file, $line, $message);
    }
}

$runner = new ArchitectureGuardrailRunner(realpath(__DIR__.'/..') ?: __DIR__.'/..');
exit($runner->run());
