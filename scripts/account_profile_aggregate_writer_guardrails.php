<?php

declare(strict_types=1);

final class AccountProfileAggregateWriterGuardFinding
{
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly int $line,
        public readonly string $method,
        public readonly string $sourceKind,
        public readonly string $message,
        public readonly ?string $owner = null,
        public readonly ?string $rationale = null,
    ) {}
}

final class AccountProfileAggregateWriterGuard
{
    /** @var list<string> */
    public const DEFAULT_SCAN_DIRS = ['app', 'packages'];

    /** @var list<AccountProfileAggregateWriterGuardFinding> */
    private array $blockedFindings = [];

    /** @var list<AccountProfileAggregateWriterGuardFinding> */
    private array $allowlistedFindings = [];

    /** @var list<AccountProfileAggregateWriterGuardFinding> */
    private array $configViolations = [];

    /** @var array<string, array{key:string,path:string,method:string,source_kind:string,owner:string,rationale:string}> */
    private array $allowlistByKey = [];

    /** @var array<string, true> */
    private array $observedAllowlistKeys = [];

    /**
     * @param  array<int, array{key?:string,path?:string,method?:string,source_kind?:string,owner?:string,rationale?:string}>  $allowlist
     * @param  list<string>  $scanDirs
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
            $owner = trim((string) ($entry['owner'] ?? ''));
            $rationale = trim((string) ($entry['rationale'] ?? ''));
            $key = trim((string) ($entry['key'] ?? $this->findingKey($path, $method, $sourceKind)));

            if ($path === '' || $method === '' || $sourceKind === '' || $owner === '' || $rationale === '') {
                $this->configViolations[] = new AccountProfileAggregateWriterGuardFinding(
                    $key !== '' ? $key : '[allowlist]',
                    $path !== '' ? $path : '[allowlist]',
                    $index + 1,
                    $method !== '' ? $method : '[missing-method]',
                    $sourceKind !== '' ? $sourceKind : '[missing-source-kind]',
                    'Allowlist entries require path, method, source_kind, owner, and rationale.',
                );

                continue;
            }

            $this->allowlistByKey[$key] = [
                'key' => $key,
                'path' => $path,
                'method' => $method,
                'source_kind' => $sourceKind,
                'owner' => $owner,
                'rationale' => $rationale,
            ];
        }
    }

    private function scanFile(string $relativePath): void
    {
        $lines = @file($this->root.DIRECTORY_SEPARATOR.$relativePath);
        if (! is_array($lines)) {
            return;
        }

        $source = implode('', $lines);
        if (! str_contains($source, 'AccountProfile') && ! str_contains($source, "collection('account_profiles')")) {
            return;
        }

        foreach ($lines as $index => $line) {
            $sourceKind = $this->detectSourceKind($line, $lines, $index);
            if ($sourceKind === null) {
                continue;
            }
            $method = $this->enclosingMethodName($lines, $index);
            if ($method === null) {
                $this->blockedFindings[] = new AccountProfileAggregateWriterGuardFinding(
                    $this->findingKey($relativePath, '[unknown-method]', $sourceKind),
                    $relativePath,
                    $index + 1,
                    '[unknown-method]',
                    $sourceKind,
                    'AccountProfile writer has no enclosing named method.',
                );

                continue;
            }

            $key = $this->findingKey($relativePath, $method, $sourceKind);
            $entry = $this->allowlistByKey[$key] ?? null;
            if ($entry === null) {
                $this->blockedFindings[] = new AccountProfileAggregateWriterGuardFinding(
                    $key,
                    $relativePath,
                    $index + 1,
                    $method,
                    $sourceKind,
                    'Direct AccountProfile aggregate writer is outside the reviewed gateway baseline.',
                );

                continue;
            }

            $this->observedAllowlistKeys[$key] = true;
            $this->allowlistedFindings[] = new AccountProfileAggregateWriterGuardFinding(
                $key,
                $relativePath,
                $index + 1,
                $method,
                $sourceKind,
                'Reviewed AccountProfile aggregate writer.',
                $entry['owner'],
                $entry['rationale'],
            );
        }
    }

    /** @param array<int, string> $lines */
    private function detectSourceKind(string $line, array $lines, int $index): ?string
    {
        if (preg_match('/AccountProfile::create\s*\(/', $line) === 1) {
            return 'model_create';
        }
        if (preg_match('/\$(?:[A-Za-z_][A-Za-z0-9_]*[Pp]rofile|[Pp]rofile)\s*->(save|delete|forceDelete|restore)\s*\(/', $line, $matches) === 1) {
            return 'model_'.strtolower((string) $matches[1]);
        }
        if (str_contains($line, "collection('account_profiles')") || str_contains($line, 'collection("account_profiles")')) {
            return 'raw_account_profiles';
        }

        if (! preg_match('/AccountProfile::(?:query|withTrashed|onlyTrashed|raw)\s*\(/', $line)) {
            return null;
        }
        $block = implode('', array_slice($lines, $index, 32));
        $chain = strstr($block, ';', true);
        if (! is_string($chain)) {
            $chain = $block;
        }
        if (preg_match('/->\s*(update|delete|forceDelete)\s*\(/', $chain, $matches) !== 1) {
            return null;
        }

        return 'builder_'.strtolower((string) $matches[1]);
    }

    /** @param array<int, string> $lines */
    private function enclosingMethodName(array $lines, int $index): ?string
    {
        for ($cursor = $index; $cursor >= 0; $cursor--) {
            if (preg_match('/function\s+([A-Za-z0-9_]+)\s*\(/', $lines[$cursor] ?? '', $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        foreach ($this->scanDirs as $directory) {
            $absoluteDirectory = $this->root.DIRECTORY_SEPARATOR.$directory;
            if (! is_dir($absoluteDirectory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $absoluteDirectory,
                FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $files[] = ltrim(str_replace($this->root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }

    private function collectStaleAllowlistEntries(): void
    {
        foreach ($this->allowlistByKey as $key => $entry) {
            if (isset($this->observedAllowlistKeys[$key])) {
                continue;
            }
            $this->configViolations[] = new AccountProfileAggregateWriterGuardFinding(
                $key,
                $entry['path'],
                0,
                $entry['method'],
                $entry['source_kind'],
                'Allowlist baseline entry is stale or mismatched.',
                $entry['owner'],
                $entry['rationale'],
            );
        }
    }

    private function emitFindings(): void
    {
        foreach ($this->allowlistedFindings as $finding) {
            fwrite(STDOUT, sprintf(
                "[PROFILE-WRITER-GUARD] ALLOW %s:%d [%s/%s] %s\n",
                $finding->path,
                $finding->line,
                $finding->method,
                $finding->sourceKind,
                $finding->rationale,
            ));
        }
        if ($this->blockedFindings === [] && $this->configViolations === []) {
            fwrite(STDOUT, "[PROFILE-WRITER-GUARD] PASS - no blocked AccountProfile aggregate writer findings detected.\n");

            return;
        }

        fwrite(STDERR, "[PROFILE-WRITER-GUARD] FAIL - AccountProfile aggregate writer violations detected:\n");
        foreach ([...$this->blockedFindings, ...$this->configViolations] as $finding) {
            fwrite(STDERR, sprintf(
                " - %s%s [%s/%s] %s\n   Resolution: route the write through an approved aggregate, lifecycle, relation-admission, or deletion-attempt gateway; then add an exact reviewed allowlist entry only for that gateway.\n",
                $finding->path,
                $finding->line > 0 ? ':'.$finding->line : '',
                $finding->method,
                $finding->sourceKind,
                $finding->message,
            ));
        }
    }

    private function findingKey(string $path, string $method, string $sourceKind): string
    {
        return "{$path}::{$method}::{$sourceKind}";
    }
}

/** @return array<int, array{key:string,path:string,method:string,source_kind:string,owner:string,rationale:string}> */
function defaultAccountProfileAggregateWriterAllowlist(): array
{
    $owner = 'TODO-v0.4.0-account-profile-lifecycle-fence-and-projection-outbox';

    return [
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileManagementService.php::createWithinCurrentTransaction::model_create',
            'path' => 'app/Application/AccountProfiles/AccountProfileManagementService.php',
            'method' => 'createWithinCurrentTransaction',
            'source_kind' => 'model_create',
            'owner' => $owner,
            'rationale' => 'Canonical aggregate-create gateway; receipt/outbox is recorded by its transaction-context caller.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileManagementService.php::persistWithAggregateRevisionCas::raw_account_profiles',
            'path' => 'app/Application/AccountProfiles/AccountProfileManagementService.php',
            'method' => 'persistWithAggregateRevisionCas',
            'source_kind' => 'raw_account_profiles',
            'owner' => $owner,
            'rationale' => 'Canonical semantic aggregate update with tenant session and aggregate-revision CAS.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php::restore::model_restore',
            'path' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php',
            'method' => 'restore',
            'source_kind' => 'model_restore',
            'owner' => $owner,
            'rationale' => 'Lifecycle gateway restores only after the Account deletion gate check.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php::restore::model_save',
            'path' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php',
            'method' => 'restore',
            'source_kind' => 'model_save',
            'owner' => $owner,
            'rationale' => 'Lifecycle restore persists its aggregate revision before emitting the upsert event.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php::deleteWithinTransaction::model_save',
            'path' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php',
            'method' => 'deleteWithinTransaction',
            'source_kind' => 'model_save',
            'owner' => $owner,
            'rationale' => 'Lifecycle soft delete persists the tombstone revision after gate/fence validation.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php::deleteWithinTransaction::model_delete',
            'path' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php',
            'method' => 'deleteWithinTransaction',
            'source_kind' => 'model_delete',
            'owner' => $owner,
            'rationale' => 'Lifecycle soft delete is the only ordinary Profile delete gateway.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php::forceDeleteWithinTransaction::model_forcedelete',
            'path' => 'app/Application/AccountProfiles/AccountProfileLifecycleService.php',
            'method' => 'forceDeleteWithinTransaction',
            'source_kind' => 'model_forcedelete',
            'owner' => $owner,
            'rationale' => 'Lifecycle force delete records/reuses the tombstone before physical removal.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileReferenceCleanupService.php::cleanProfileWithinTransaction::model_save',
            'path' => 'app/Application/AccountProfiles/AccountProfileReferenceCleanupService.php',
            'method' => 'cleanProfileWithinTransaction',
            'source_kind' => 'model_save',
            'owner' => $owner,
            'rationale' => 'Server-only deletion reference-cleanup gateway records receipt/outbox for each surviving Profile.',
        ],
        [
            'key' => 'app/Application/AccountProfiles/AccountProfileRelationAdmissionService.php::touchTarget::raw_account_profiles',
            'path' => 'app/Application/AccountProfiles/AccountProfileRelationAdmissionService.php',
            'method' => 'touchTarget',
            'source_kind' => 'raw_account_profiles',
            'owner' => $owner,
            'rationale' => 'Relation-admission gateway increments only the server lifecycle fence under the active tenant session.',
        ],
        [
            'key' => 'app/Application/Profiles/CurrentTenantAccountDeletionAttemptService.php::captureClaimedAttempt::model_save',
            'path' => 'app/Application/Profiles/CurrentTenantAccountDeletionAttemptService.php',
            'method' => 'captureClaimedAttempt',
            'source_kind' => 'model_save',
            'owner' => $owner,
            'rationale' => 'Deletion-attempt capture gateway installs the server-only Profile attempt/fence state in its transaction after the short claim reservation.',
        ],
    ];
}

/** @return array{root:string,allowlist_path:?string,scan_dirs:list<string>} */
function resolveAccountProfileAggregateWriterGuardOptions(array $argv): array
{
    $root = dirname(__DIR__);
    $allowlistPath = null;
    $scanDirs = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
        } elseif (str_starts_with($argument, '--allowlist=')) {
            $allowlistPath = substr($argument, strlen('--allowlist='));
        } elseif (str_starts_with($argument, '--scan-dir=')) {
            $scanDir = trim((string) substr($argument, strlen('--scan-dir=')));
            if ($scanDir !== '') {
                $scanDirs[] = $scanDir;
            }
        }
    }

    return [
        'root' => rtrim($root, DIRECTORY_SEPARATOR),
        'allowlist_path' => $allowlistPath !== null && $allowlistPath !== '' ? $allowlistPath : null,
        'scan_dirs' => $scanDirs !== [] ? $scanDirs : AccountProfileAggregateWriterGuard::DEFAULT_SCAN_DIRS,
    ];
}

/** @return array<int, array{key?:string,path?:string,method?:string,source_kind?:string,owner?:string,rationale?:string}> */
function loadAccountProfileAggregateWriterAllowlist(?string $allowlistPath): array
{
    if ($allowlistPath === null) {
        return defaultAccountProfileAggregateWriterAllowlist();
    }
    $loaded = require $allowlistPath;

    return is_array($loaded) ? $loaded : [];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = resolveAccountProfileAggregateWriterGuardOptions($_SERVER['argv'] ?? []);
    exit((new AccountProfileAggregateWriterGuard(
        $options['root'],
        loadAccountProfileAggregateWriterAllowlist($options['allowlist_path']),
        $options['scan_dirs'],
    ))->run());
}
