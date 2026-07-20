<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AccountProfileAggregateWriterGuardrailTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_guard_accepts_the_reviewed_aggregate_writer_owners(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->guardScriptPath(),
            '--root='.$this->repositoryRoot,
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString(
            '[PROFILE-WRITER-GUARD] PASS - no blocked AccountProfile aggregate writer findings detected.',
            $output,
        );
    }

    public function test_guard_rejects_a_direct_profile_create_outside_the_aggregate_gateway(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Example/BrokenProfileWriter.php' => <<<'PHP'
<?php

final class BrokenProfileWriter
{
    public function create(): void
    {
        \App\Models\Tenants\AccountProfile::create(['display_name' => 'bypass']);
    }

    public function save(\App\Models\Tenants\AccountProfile $profile): void
    {
        $profile->save();
    }

    public function raw(object $context): void
    {
        $context->collection('account_profiles')->deleteOne([]);
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
        $this->assertStringContainsString('BrokenProfileWriter.php', $output);
        $this->assertStringContainsString('route the write through an approved aggregate, lifecycle, relation-admission, or deletion-attempt gateway', $output);
    }

    public function test_architecture_guardrails_runs_the_profile_writer_guard_for_the_real_repository(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->architectureGuardrailsScriptPath(),
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString(
            '[PROFILE-WRITER-GUARD] PASS - no blocked AccountProfile aggregate writer findings detected.',
            $output,
        );
    }

    /** @param array<int, string> $command */
    private function guardProcess(array $command): Process
    {
        return new Process($command, $this->repositoryRoot, null, null, 30);
    }

    /** @param array<string, string> $files */
    private function makeFixtureRepo(array $files): string
    {
        $root = sys_get_temp_dir().'/account-profile-aggregate-writer-guard-'.bin2hex(random_bytes(6));

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
        return $this->repositoryRoot.'/scripts/account_profile_aggregate_writer_guardrails.php';
    }

    private function architectureGuardrailsScriptPath(): string
    {
        return $this->repositoryRoot.'/scripts/architecture_guardrails.php';
    }
}
