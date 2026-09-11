<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class NestedMemberPaginationGuardrailsTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_guard_passes_for_the_real_repository(): void
    {
        $process = new Process(['php', $this->guardPath()], $this->repositoryRoot);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[NESTED-MEMBER-GUARD] PASS', $output);
    }

    public function test_architecture_runner_invokes_nested_member_guard(): void
    {
        $process = new Process(['php', $this->repositoryRoot.'/scripts/architecture_guardrails.php'], $this->repositoryRoot, timeout: 30);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[NESTED-MEMBER-GUARD] PASS', $output);
        $this->assertStringContainsString('[ARCH-GUARDRAILS] PASS', $output);
    }

    public function test_guard_rejects_a_copy_of_the_historical_nested_delete_index(): void
    {
        $fixtureRoot = sys_get_temp_dir().'/nested-member-copy-'.bin2hex(random_bytes(6));
        mkdir($fixtureRoot.'/database/migrations/tenants', 0777, true);
        file_put_contents(
            $fixtureRoot.'/database/migrations/tenants/2026_09_11_000100_copied_nested_delete_index.php',
            "<?php\n".'$query->where(\'nested_profile_groups.account_profile_ids\');'."\n",
        );

        $process = new Process([
            'php', $this->guardPath(), '--root='.$fixtureRoot, '--scan-path=database/migrations',
        ], $this->repositoryRoot);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('nested_profile_groups.account_profile_ids', $output);
    }

    public function test_guard_rejects_a_controlled_full_list_and_legacy_authority_fixture(): void
    {
        $fixtureRoot = sys_get_temp_dir().'/nested-member-guard-'.bin2hex(random_bytes(6));
        mkdir($fixtureRoot.'/app', 0777, true);
        mkdir($fixtureRoot.'/app/Application/AccountProfiles', 0777, true);
        mkdir($fixtureRoot.'/scripts', 0777, true);
        file_put_contents($fixtureRoot.'/app/BrokenMemberReader.php', <<<'PHP'
<?php

final class BrokenMemberReader
{
    public function fetchAllOccurrenceProfileGroupMembers(): array
    {
        LegacyEventPartiesCanonicalizationService::class;
        $store->legacyGroupsForOwner();
        $service->profileIdsFromStoredProfileGroups($event->profile_groups);
        $service->collectRelatedProfileIdsForReadPayload($event, $ids);
        $store->replaceAllGroupsWithinContext();
        $store->replaceGroupMembersWithinContext();
        $store->syncOccurrenceGroups();
        $store->rowsForOccurrence();
        $query->where('nested_profile_groups.account_profile_ids');
        $memberIds = $rawGroup['account_profile_ids'] ?? $rawGroup['profile_ids'] ?? [];
        $service->formatForRead($groups);
        $service->withSelectedSummaries($groups, $summaries);
        $service->adminMemberPage($profile, 'group', 20, null, null, $discovery);
        EVENT_OCCURRENCE_PARTIES_MAX;
        EVENT_OCCURRENCE_PARTIES_TOTAL_MAX;
        EVENT_PROFILE_GROUP_MEMBERS_MAX;

        return DB::collection('event_profile_group_members')->get()->all();
    }

    private function mergeGroups(array $eventGroups, array $ownGroups): array
    {
        return [];
    }
}
PHP);
        file_put_contents(
            $fixtureRoot.'/app/Application/AccountProfiles/AccountProfileNestedGroupService.php',
            <<<'PHP'
<?php
final class AccountProfileNestedGroupService
{
    public function formatForRead(array $groups): array
    {
        foreach ($groups as $rawGroup) {
            $memberIds = $rawGroup['account_profile_ids'] ?? $rawGroup['profile_ids'] ?? [];
        }
        return $groups;
    }

    public function withSelectedSummaries(array $groups, array $summaries): array
    {
        return $groups;
    }

    public function adminMemberPage(): array
    {
        return [];
    }
}
PHP
        );
        file_put_contents($fixtureRoot.'/scripts/broken-repair.sh', <<<'SH'
#!/usr/bin/env bash
php artisan events:legacy-event-parties:repair
SH);

        $process = new Process([
            'php',
            $this->guardPath(),
            '--root='.$fixtureRoot,
            '--scan-path=app',
            '--scan-path=scripts',
        ], $this->repositoryRoot);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('fetchAllOccurrenceProfileGroupMembers', $output);
        $this->assertStringContainsString('event_profile_group_members', $output);
        $this->assertStringContainsString('LegacyEventPartiesCanonicalizationService', $output);
        $this->assertStringContainsString('legacyGroupsForOwner', $output);
        $this->assertStringContainsString('profileIdsFromStoredProfileGroups', $output);
        $this->assertStringContainsString('collectRelatedProfileIdsForReadPayload', $output);
        $this->assertStringContainsString('replaceAllGroupsWithinContext', $output);
        $this->assertStringContainsString('replaceGroupMembersWithinContext', $output);
        $this->assertStringContainsString('syncOccurrenceGroups', $output);
        $this->assertStringContainsString('rowsForOccurrence', $output);
        $this->assertStringContainsString('mergeGroups(array $eventGroups', $output);
        $this->assertStringContainsString('nested_profile_groups.account_profile_ids', $output);
        $this->assertStringContainsString("['account_profile_ids']", $output);
        $this->assertStringContainsString('formatForRead', $output);
        $this->assertStringContainsString('withSelectedSummaries', $output);
        $this->assertStringContainsString('AccountProfileNestedGroupService::adminMemberPage', $output);
        $this->assertStringContainsString('events:legacy-event-parties:repair', $output);
        $this->assertStringContainsString('EVENT_OCCURRENCE_PARTIES_MAX', $output);
        $this->assertStringContainsString('EVENT_OCCURRENCE_PARTIES_TOTAL_MAX', $output);
        $this->assertStringContainsString('EVENT_PROFILE_GROUP_MEMBERS_MAX', $output);
    }

    public function test_guard_rejects_candidate_modes_on_the_generic_account_profile_index(): void
    {
        $fixtureRoot = sys_get_temp_dir().'/candidate-endpoint-guard-'.bin2hex(random_bytes(6));
        mkdir($fixtureRoot.'/app/Http/Api/v1/Controllers', 0777, true);
        mkdir($fixtureRoot.'/app/Application/AccountProfiles', 0777, true);
        file_put_contents(
            $fixtureRoot.'/app/Http/Api/v1/Controllers/AccountProfilesController.php',
            <<<'PHP'
<?php
final class AccountProfilesController
{
    public function index(): void
    {
        $rules = ['queryable_only' => ['sometimes', 'boolean']];
    }

    public function candidates(): void {}
}
PHP
        );
        file_put_contents(
            $fixtureRoot.'/app/Application/AccountProfiles/AccountProfileQueryService.php',
            <<<'PHP'
<?php
final class AccountProfileQueryService
{
    private function applyAdminCandidateFilters(): void {}
}
PHP
        );

        $process = new Process([
            'php',
            $this->guardPath(),
            '--root='.$fixtureRoot,
            '--scan-path=app',
        ], $this->repositoryRoot);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('must explicitly prohibit candidate-only parameter `queryable_only`', $output);
        $this->assertStringContainsString('retired candidate token `applyAdminCandidateFilters`', $output);
    }

    private function guardPath(): string
    {
        return $this->repositoryRoot.'/scripts/nested_member_pagination_guardrails.php';
    }
}
