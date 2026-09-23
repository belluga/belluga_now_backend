<?php

declare(strict_types=1);

final class MongoDbTransactionGuard
{
    /** @var list<string> */
    public const DEFAULT_SCAN_DIRS = ['app', 'packages'];

    /** @var list<string> */
    private const ALLOWED_SHAPES = ['callback_transaction', 'retry_compensation'];

    /** @var array<string, array{path:string,shape:string,expected_count:int,owner:string,rationale:string,follow_up:string}> */
    private array $baselineByKey = [];

    /** @var array<string, true> */
    private array $observedBaselineKeys = [];

    /** @var list<string> */
    private array $blocked = [];

    /** @var list<string> */
    private array $reviewed = [];

    /** @var list<string> */
    private array $configurationErrors = [];

    /**
     * @param  list<array{path?:string,shape?:string,expected_count?:int,owner?:string,rationale?:string,follow_up?:string}>  $baseline
     * @param  list<string>  $scanDirs
     */
    public function __construct(
        private readonly string $root,
        private readonly array $baseline,
        private readonly array $scanDirs = self::DEFAULT_SCAN_DIRS,
    ) {}

    public function run(): int
    {
        $this->indexBaseline();

        foreach ($this->phpFiles() as $path) {
            $contents = file_get_contents($this->root.'/'.$path);
            if (! is_string($contents)) {
                continue;
            }

            $callbackCount = preg_match_all('/->\s*transaction\s*\(/', $contents);
            $this->inspect($path, 'callback_transaction', $callbackCount);

            $retryCount = 0;
            if (stripos($contents, 'transaction') !== false) {
                $retryCount = preg_match_all(
                    '/\b(?:MAX_BODY_ATTEMPTS|MAX_COMMIT_ATTEMPTS|shouldRetryBody|shouldRetryCommit|bodyRetryDelayMicroseconds)\b/',
                    $contents,
                );
            }
            if ($callbackCount > 0 && str_contains($contents, 'PHP_INT_MAX')) {
                $retryCount += substr_count($contents, 'PHP_INT_MAX');
            }
            $this->inspect($path, 'retry_compensation', $retryCount);
        }

        foreach ($this->baselineByKey as $key => $entry) {
            if (isset($this->observedBaselineKeys[$key])) {
                continue;
            }

            $this->configurationErrors[] = sprintf(
                '%s [%s] expected %d finding(s), observed 0: stale or mismatched baseline.',
                $entry['path'],
                $entry['shape'],
                $entry['expected_count'],
            );
        }

        $this->emit();

        return ($this->blocked === [] && $this->configurationErrors === []) ? 0 : 1;
    }

    private function indexBaseline(): void
    {
        foreach ($this->baseline as $index => $entry) {
            $path = trim((string) ($entry['path'] ?? ''));
            $shape = trim((string) ($entry['shape'] ?? ''));
            $expectedCount = (int) ($entry['expected_count'] ?? 0);
            $owner = trim((string) ($entry['owner'] ?? ''));
            $rationale = trim((string) ($entry['rationale'] ?? ''));
            $followUp = trim((string) ($entry['follow_up'] ?? ''));
            $key = $this->key($path, $shape);

            if ($path === ''
                || ! in_array($shape, self::ALLOWED_SHAPES, true)
                || $expectedCount < 1
                || $owner === ''
                || $rationale === ''
                || $followUp === '') {
                $this->configurationErrors[] = sprintf(
                    'Baseline entry %d must declare path, allowed shape, positive expected_count, owner, rationale, and follow_up.',
                    $index + 1,
                );

                continue;
            }

            if (isset($this->baselineByKey[$key])) {
                $this->configurationErrors[] = 'Duplicate transaction baseline entry: '.$key.'.';

                continue;
            }

            $this->baselineByKey[$key] = [
                'path' => $path,
                'shape' => $shape,
                'expected_count' => $expectedCount,
                'owner' => $owner,
                'rationale' => $rationale,
                'follow_up' => $followUp,
            ];
        }
    }

    private function inspect(string $path, string $shape, int|false $count): void
    {
        $observedCount = $count === false ? 0 : $count;
        if ($observedCount === 0) {
            return;
        }

        $key = $this->key($path, $shape);
        $entry = $this->baselineByKey[$key] ?? null;
        if ($entry === null) {
            $this->blocked[] = sprintf(
                '%s [%s] observed %d finding(s) and lacks a reviewed baseline entry.',
                $path,
                $shape,
                $observedCount,
            );

            return;
        }

        $this->observedBaselineKeys[$key] = true;
        if ($entry['expected_count'] !== $observedCount) {
            $this->configurationErrors[] = sprintf(
                '%s [%s] expected %d finding(s), observed %d: stale or mismatched baseline.',
                $path,
                $shape,
                $entry['expected_count'],
                $observedCount,
            );

            return;
        }

        $this->reviewed[] = sprintf(
            '%s [%s] count=%d owner=%s follow_up=%s rationale=%s',
            $path,
            $shape,
            $observedCount,
            $entry['owner'],
            $entry['follow_up'],
            $entry['rationale'],
        );
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];

        foreach ($this->scanDirs as $directory) {
            $absoluteDirectory = $this->root.'/'.$directory;
            if (! is_dir($absoluteDirectory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $files[] = ltrim(str_replace($this->root, '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function key(string $path, string $shape): string
    {
        return $path.'::'.$shape;
    }

    private function emit(): void
    {
        if ($this->reviewed !== []) {
            fwrite(STDOUT, "[MDB-TXN-GUARD] Reviewed existing findings:\n");
            foreach ($this->reviewed as $finding) {
                fwrite(STDOUT, ' - '.$finding."\n");
            }
        }

        foreach ($this->blocked as $finding) {
            fwrite(STDOUT, '[MDB-TXN-GUARD] BLOCKED - '.$finding."\n");
        }

        foreach ($this->configurationErrors as $finding) {
            fwrite(STDOUT, '[MDB-TXN-GUARD] CONFIG - '.$finding."\n");
        }

        if ($this->blocked === [] && $this->configurationErrors === []) {
            fwrite(STDOUT, "[MDB-TXN-GUARD] PASS - no blocked MongoDB transaction architecture findings detected.\n");
        } else {
            fwrite(STDOUT, "[MDB-TXN-GUARD] FAIL - review new findings and baseline drift.\n");
        }
    }
}

/**
 * @return list<array{path:string,shape:string,expected_count:int,owner:string,rationale:string,follow_up:string}>
 */
function defaultMongoDbTransactionGuardBaseline(): array
{
    $followUp = 'TODO-v0.6.2-laravel-mongodb-transaction-owner-audit-and-simplification';
    $callbackRationale = 'Pre-existing callback transaction owner retained unchanged until its domain concurrency contract is reviewed.';

    return [
        ['path' => 'app/Application/AccountProfiles/AccountProfileTransactionRetryPolicy.php', 'shape' => 'retry_compensation', 'expected_count' => 7, 'owner' => 'AccountProfileTransactionRetryPolicy', 'rationale' => 'Pre-existing Account Profile body/commit retry policy requires an owner-specific reconciliation and replay-safety decision.', 'follow_up' => $followUp],
        ['path' => 'app/Application/AccountProfiles/AccountProfileTransactionRunner.php', 'shape' => 'retry_compensation', 'expected_count' => 4, 'owner' => 'AccountProfileTransactionRunner', 'rationale' => 'Pre-existing Account Profile transaction runner coordinates retry and indeterminate-result reconciliation; behavior remains unchanged in v0.6.1.', 'follow_up' => $followUp],
        ['path' => 'app/Application/Accounts/AccountManagementService.php', 'shape' => 'callback_transaction', 'expected_count' => 6, 'owner' => 'AccountManagementService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Accounts/AccountRoleTemplateService.php', 'shape' => 'callback_transaction', 'expected_count' => 2, 'owner' => 'AccountRoleTemplateService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Accounts/AccountUserService.php', 'shape' => 'callback_transaction', 'expected_count' => 2, 'owner' => 'AccountUserService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Auth/LandlordAuthenticationService.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'LandlordAuthenticationService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Initialization/SystemInitializationService.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'SystemInitializationService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/LandlordRoles/LandlordRoleService.php', 'shape' => 'callback_transaction', 'expected_count' => 4, 'owner' => 'LandlordRoleService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/LandlordTenants/TenantLifecycleService.php', 'shape' => 'callback_transaction', 'expected_count' => 2, 'owner' => 'TenantLifecycleService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/LandlordUsers/LandlordUserCreator.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'LandlordUserCreator', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/LandlordUsers/LandlordUserManagementService.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'LandlordUserManagementService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Organizations/OrganizationManagementService.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'OrganizationManagementService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Profiles/CurrentTenantAccountDeletionAccountGuard.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'CurrentTenantAccountDeletionAccountGuard', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Tenants/TenantAppDomainManagementService.php', 'shape' => 'callback_transaction', 'expected_count' => 2, 'owner' => 'TenantAppDomainManagementService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Tenants/TenantDomainManagementService.php', 'shape' => 'callback_transaction', 'expected_count' => 4, 'owner' => 'TenantDomainManagementService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Application/Tenants/TenantRoleManagementService.php', 'shape' => 'callback_transaction', 'expected_count' => 2, 'owner' => 'TenantRoleManagementService', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'app/Domain/Identity/AnonymousIdentityMerger.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'AnonymousIdentityMerger', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
        ['path' => 'packages/belluga/belluga_invites/src/Application/Transactions/InviteTransactionRunner.php', 'shape' => 'callback_transaction', 'expected_count' => 1, 'owner' => 'InviteTransactionRunner', 'rationale' => $callbackRationale, 'follow_up' => $followUp],
    ];
}

/** @return array{root:string,baseline_path:?string,scan_dirs:list<string>} */
function resolveMongoDbTransactionGuardOptions(array $argv): array
{
    $root = dirname(__DIR__);
    $baselinePath = null;
    $scanDirs = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
        } elseif (str_starts_with($argument, '--allowlist=')) {
            $baselinePath = substr($argument, strlen('--allowlist='));
        } elseif (str_starts_with($argument, '--scan-dir=')) {
            $scanDir = trim(substr($argument, strlen('--scan-dir=')));
            if ($scanDir !== '') {
                $scanDirs[] = $scanDir;
            }
        }
    }

    return [
        'root' => rtrim($root, '/'),
        'baseline_path' => $baselinePath,
        'scan_dirs' => $scanDirs !== [] ? $scanDirs : MongoDbTransactionGuard::DEFAULT_SCAN_DIRS,
    ];
}

/** @return list<array{path?:string,shape?:string,expected_count?:int,owner?:string,rationale?:string,follow_up?:string}> */
function loadMongoDbTransactionGuardBaseline(?string $baselinePath): array
{
    if ($baselinePath === null || $baselinePath === '') {
        return defaultMongoDbTransactionGuardBaseline();
    }

    $loaded = require $baselinePath;

    return is_array($loaded) ? $loaded : [];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = resolveMongoDbTransactionGuardOptions($_SERVER['argv'] ?? []);
    $guard = new MongoDbTransactionGuard(
        $options['root'],
        loadMongoDbTransactionGuardBaseline($options['baseline_path']),
        $options['scan_dirs'],
    );
    exit($guard->run());
}
