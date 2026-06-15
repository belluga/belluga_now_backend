<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AccountProfileQueryabilityGuardrailsTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_guard_reports_allowlisted_findings_with_audit_instructions_for_real_repository(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->guardScriptPath(),
            '--root='.$this->repositoryRoot,
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[QRY-GUARD] Allowlisted findings requiring audit:', $output);
        $this->assertStringContainsString('Confirm this is not a user-facing selector/list/candidate path outside the canonical gateway.', $output);
        $this->assertStringContainsString('[QRY-GUARD] PASS - no blocked AccountProfile operational query findings detected.', $output);
    }

    public function test_architecture_guardrails_script_invokes_queryability_guard_for_real_repository(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->architectureGuardrailsScriptPath(),
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[QRY-GUARD] PASS - no blocked AccountProfile operational query findings detected.', $output);
        $this->assertStringContainsString('[ARCH-GUARDRAILS] PASS - no architecture violations found.', $output);
    }

    public function test_guard_fails_for_unallowlisted_operational_query_fixture(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Example/BrokenSelector.php' => <<<'PHP'
<?php

final class BrokenSelector
{
    public function queryCandidates(): array
    {
        return \App\Models\Tenants\AccountProfile::query()
            ->whereIn('profile_type', ['artist'])
            ->get()
            ->all();
    }
}
PHP,
            'allowlist.php' => <<<'PHP'
<?php

return [];
PHP,
        ]);

        $process = $this->guardProcess([
            'php',
            $this->guardScriptPath(),
            '--root='.$fixtureRoot,
            '--scan-dir=app',
            '--allowlist='.$fixtureRoot.'/allowlist.php',
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('BrokenSelector.php', $output);
        $this->assertStringContainsString('move the operational query into the canonical gateway', $output);
    }

    public function test_guard_fails_when_operational_query_lacks_reviewed_baseline_key(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Example/CanonicalGateway.php' => <<<'PHP'
<?php

final class CanonicalGateway
{
    public function publicPaginate(): array
    {
        return \App\Models\Tenants\AccountProfile::query()
            ->whereIn('profile_type', ['artist'])
            ->get()
            ->all();
    }
}
PHP,
            'allowlist.php' => <<<'PHP'
<?php

return [[
    'key' => 'app/Application/Example/CanonicalGateway.php::differentMethod::query',
    'path' => 'app/Application/Example/CanonicalGateway.php',
    'method' => 'differentMethod',
    'source_kind' => 'query',
    'category' => 'canonical_gateway',
    'owner' => 'test-fixture',
    'rationale' => 'Intentional mismatch to prove that new findings need reviewed baseline entries.',
]];
PHP,
        ]);

        $process = $this->guardProcess([
            'php',
            $this->guardScriptPath(),
            '--root='.$fixtureRoot,
            '--scan-dir=app',
            '--allowlist='.$fixtureRoot.'/allowlist.php',
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('lacks a reviewed baseline entry', $output);
        $this->assertStringContainsString('stale or mismatched', $output);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function guardProcess(array $command): Process
    {
        return new Process($command, $this->repositoryRoot, null, null, 30);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function makeFixtureRepo(array $files): string
    {
        $root = sys_get_temp_dir().'/account-profile-queryability-guard-'.bin2hex(random_bytes(6));

        foreach ($files as $relativePath => $contents) {
            $absolutePath = $root.'/'.$relativePath;
            $directory = dirname($absolutePath);
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($absolutePath, $contents);
        }

        return $root;
    }

    private function guardScriptPath(): string
    {
        return $this->repositoryRoot.'/scripts/account_profile_queryability_guardrails.php';
    }

    private function architectureGuardrailsScriptPath(): string
    {
        return $this->repositoryRoot.'/scripts/architecture_guardrails.php';
    }
}
