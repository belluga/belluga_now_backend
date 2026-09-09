<?php

declare(strict_types=1);

final class NestedMemberPaginationGuard
{
    /** @var array<int, string> */
    public const DEFAULT_SCAN_PATHS = ['app', 'packages', 'database/migrations', 'routes', 'scripts'];

    /** @var array<string, string> */
    private const FORBIDDEN_TOKENS = [
        'AccountProfileNestedPublicMembersProjectionService' => 'Retired Account public-member projection authority.',
        'account_profile_nested_public_member_projection' => 'Retired Account public-member projection collection.',
        'EventProfileGroupMemberStore' => 'Retired Event relationship authority.',
        'event_profile_group_members' => 'Retired Event relationship collection.',
        'LegacyEventPartiesCanonicalizationService' => 'Retired Event repair/canonicalization authority.',
        'legacyGroupsForOwner' => 'Retired unbounded Event compatibility reader.',
        'profileIdsFromStoredProfileGroups' => 'Retired embedded Event member-array reader.',
        'collectRelatedProfileIdsForReadPayload' => 'Retired Event read hydration through embedded member arrays.',
        'nested_profile_groups.account_profile_ids' => 'Retired embedded Account member relationship authority.',
        'fetchAllNestedGroupMembers' => 'Full nested-member fetch helper.',
        'fetchAllOccurrenceProfileGroupMembers' => 'Full Event-member fetch helper.',
        'groupMemberIdsWithinContext' => 'Full Account group-member ID materialization helper.',
        'occurrenceGroupMemberIds' => 'Full Event group-member ID materialization helper.',
        'memberRowsForTab' => 'Full Event tab-member materialization helper.',
        'memberRowsForBackings' => 'Full Event backing-member materialization helper.',
        'replaceOccurrenceGroupMembers' => 'Full Event group delete/reinsert writer.',
        'syncOccurrenceGroups' => 'Full Event owner delete/reinsert writer.',
        'rowsForOccurrence' => 'Full Event owner member-row materialization helper.',
        'mergeGroups(array $eventGroups' => 'Embedded Event group/member-array merge helper.',
        'replaceAllGroupsWithinContext' => 'Ambiguous Account aggregate replacement entrypoint.',
        'replaceGroupMembersWithinContext' => 'Full Account group delete/reinsert writer.',
        'events:legacy-event-parties:repair' => 'Retired Event repair command entrypoint.',
        'EVENT_OCCURRENCE_PARTIES_MAX' => 'Retired Event related-profile member quota.',
        'EVENT_OCCURRENCE_PARTIES_TOTAL_MAX' => 'Retired Event related-profile aggregate quota.',
        'EVENT_PROFILE_GROUP_MEMBERS_MAX' => 'Retired Event profile-group member quota.',
        'nested_profile.profile_type' => 'Member-card snapshot field used as relationship authority.',
        'nested_profile.display_name' => 'Member-card snapshot field used as relationship authority.',
        'nested_profile.taxonomy_terms' => 'Member-card snapshot field used as relationship authority.',
        'public_eligible' => 'Persisted member eligibility snapshot.',
        '$unionWith' => 'Duplicated search branch instead of one scoped indexed predicate.',
    ];

    /** @param array<int, string> $scanPaths */
    public function __construct(
        private readonly string $root,
        private readonly array $scanPaths = self::DEFAULT_SCAN_PATHS,
    ) {}

    public function run(): int
    {
        $violations = [];
        foreach ($this->sourceFiles() as $relativePath) {
            $lines = @file($this->root.'/'.$relativePath);
            if (! is_array($lines)) {
                continue;
            }
            foreach ($lines as $index => $line) {
                foreach (self::FORBIDDEN_TOKENS as $token => $message) {
                    if (str_contains($line, $token)) {
                        $violations[] = sprintf('%s:%d %s Token: `%s`', $relativePath, $index + 1, $message, $token);
                    }
                }
            }
        }
        array_push($violations, ...$this->candidateEndpointCutoverViolations());
        array_push($violations, ...$this->embeddedAccountMemberArrayViolations());

        if ($violations === []) {
            fwrite(STDOUT, "[NESTED-MEMBER-GUARD] PASS - canonical member pagination authority is bounded.\n");

            return 0;
        }

        fwrite(STDERR, "[NESTED-MEMBER-GUARD] FAIL - forbidden member-list architecture detected:\n");
        foreach ($violations as $violation) {
            fwrite(STDERR, " - {$violation}\n");
        }

        return 1;
    }

    /** @return array<int, string> */
    private function embeddedAccountMemberArrayViolations(): array
    {
        $violations = [];
        $servicePath = 'app/Application/AccountProfiles/AccountProfileNestedGroupService.php';
        $service = @file_get_contents($this->root.'/'.$servicePath);
        if (is_string($service)) {
            foreach ([
                'public function normalizeForWrite(' => 'normalizeForWrite',
                'public function formatForRead(' => 'formatForRead',
                'public function withSelectedSummaries(' => 'withSelectedSummaries',
                'public function adminMemberPage(' => 'AccountProfileNestedGroupService::adminMemberPage',
                'private function normalizeMemberIds(' => 'normalizeMemberIds',
                "\$rawGroup['account_profile_ids']" => "['account_profile_ids']",
                "\$rawGroup['profile_ids']" => "['profile_ids']",
            ] as $token => $label) {
                if (str_contains($service, $token)) {
                    $violations[] = "{$servicePath} Retired embedded Account member reader remains: `{$label}`.";
                }
            }
        }

        foreach ([
            'app/Application/AccountProfiles/AccountProfileNestedGroupMemberStore.php',
            'app/Application/AccountProfiles/AccountProfileManagementService.php',
            'app/Application/AccountProfiles/AccountProfileRelationAdmissionService.php',
        ] as $path) {
            $source = @file_get_contents($this->root.'/'.$path);
            if (! is_string($source)) {
                continue;
            }
            foreach (["['account_profile_ids']", "['profile_ids']"] as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$path} Retired embedded Account member array access remains: `{$token}`.";
                }
            }
        }

        return $violations;
    }

    /** @return array<int, string> */
    private function candidateEndpointCutoverViolations(): array
    {
        $violations = [];
        $controllerPath = 'app/Http/Api/v1/Controllers/AccountProfilesController.php';
        $controller = @file_get_contents($this->root.'/'.$controllerPath);
        if (! is_string($controller)) {
            return ["{$controllerPath} Missing canonical Account Profile controller."];
        }

        $indexStart = strpos($controller, 'public function index(');
        $candidatesStart = strpos($controller, 'public function candidates(');
        $indexBlock = $indexStart !== false && $candidatesStart !== false
            ? substr($controller, $indexStart, $candidatesStart - $indexStart)
            : '';
        foreach ([
            'contact_mode',
            'contact_channels_enabled_only',
            'queryable_only',
            'exclude_account_profile_id',
        ] as $candidateOnlyParameter) {
            $expected = "'{$candidateOnlyParameter}' => ['prohibited']";
            if (! str_contains($indexBlock, $expected)) {
                $violations[] = "{$controllerPath} Generic index must explicitly prohibit candidate-only parameter `{$candidateOnlyParameter}`.";
            }
        }

        $queryServicePath = 'app/Application/AccountProfiles/AccountProfileQueryService.php';
        $queryService = @file_get_contents($this->root.'/'.$queryServicePath);
        if (! is_string($queryService)) {
            $violations[] = "{$queryServicePath} Missing generic Account Profile query service.";
        } else {
            foreach (['applyAdminCandidateFilters', 'queryable_only', 'contact_channels_enabled_only'] as $legacyToken) {
                if (str_contains($queryService, $legacyToken)) {
                    $violations[] = "{$queryServicePath} Generic query service contains retired candidate token `{$legacyToken}`.";
                }
            }
        }

        return $violations;
    }

    /** @return array<int, string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach ($this->scanPaths as $scanPath) {
            $absolutePath = $this->root.'/'.$scanPath;
            if (is_file($absolutePath)) {
                $files[] = $scanPath;

                continue;
            }
            if (! is_dir($absolutePath)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), ['php', 'sh'], true)) {
                    continue;
                }
                $relativePath = ltrim(str_replace($this->root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                if ($relativePath !== 'scripts/nested_member_pagination_guardrails.php') {
                    $files[] = $relativePath;
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }
}

/** @return array{root:string,scan_paths:array<int,string>} */
function parseNestedMemberPaginationGuardCli(array $argv): array
{
    $root = realpath(__DIR__.'/..') ?: __DIR__.'/..';
    $scanPaths = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root=')) ?: $root;
        } elseif (str_starts_with($argument, '--scan-path=')) {
            $scanPaths[] = substr($argument, strlen('--scan-path='));
        }
    }

    return [
        'root' => $root,
        'scan_paths' => $scanPaths === [] ? NestedMemberPaginationGuard::DEFAULT_SCAN_PATHS : $scanPaths,
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = parseNestedMemberPaginationGuardCli($_SERVER['argv'] ?? []);
    exit((new NestedMemberPaginationGuard($options['root'], $options['scan_paths']))->run());
}
