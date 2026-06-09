<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicTaxonomyCutoverGuardrailsTest extends TestCase
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
        $this->assertStringContainsString('[TAX-GUARD] Allowlisted findings requiring audit:', $output);
        $this->assertStringContainsString('Reject convenience bridges, dual-path mirrors, and pseudo-canonical fallback fields.', $output);
        $this->assertStringContainsString('[TAX-GUARD] PASS - no blocked public taxonomy cutover findings detected.', $output);
    }

    public function test_architecture_guardrails_script_invokes_public_taxonomy_guard_for_real_repository(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->architectureGuardrailsScriptPath(),
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[TAX-GUARD] PASS - no blocked public taxonomy cutover findings detected.', $output);
        $this->assertStringContainsString('[ARCH-GUARDRAILS] PASS - no architecture violations found.', $output);
    }

    public function test_guard_fails_for_unallowlisted_pseudo_canonical_bridge_fixture(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Events/BrokenBridge.php' => <<<'PHP'
<?php

final class BrokenBridge
{
    public function buildPayload(): array
    {
        return [
            'taxonomy_terms_effective' => ['music:show'],
        ];
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
            '--scan-path=app',
            '--allowlist='.$fixtureRoot.'/allowlist.php',
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('taxonomy_terms_effective', $output);
        $this->assertStringContainsString('lacks a reviewed baseline entry', $output);
    }

    public function test_guard_fails_for_unallowlisted_runtime_facets_builder_fixture(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Discovery/BrokenDiscoveryPayload.php' => <<<'PHP'
<?php

final class BrokenDiscoveryPayload
{
    public function buildPayload(): array
    {
        return [
            'discovery_filter_facets' => [
                'filter_keys' => ['artist_public'],
                'taxonomy_options' => [],
            ],
        ];
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
            '--scan-path=app',
            '--allowlist='.$fixtureRoot.'/allowlist.php',
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('runtime facet payload/contracts', $output);
        $this->assertStringContainsString('canonical owner', $output);
    }

    private function guardProcess(array $command): Process
    {
        return new Process($command, $this->repositoryRoot, null, null, 30);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function makeFixtureRepo(array $files): string
    {
        $root = sys_get_temp_dir().'/public-taxonomy-cutover-guard-'.bin2hex(random_bytes(6));

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
        return $this->repositoryRoot.'/scripts/public_taxonomy_cutover_guardrails.php';
    }

    private function architectureGuardrailsScriptPath(): string
    {
        return $this->repositoryRoot.'/scripts/architecture_guardrails.php';
    }
}
