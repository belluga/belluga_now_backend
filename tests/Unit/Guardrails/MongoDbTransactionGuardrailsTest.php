<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MongoDbTransactionGuardrailsTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_guard_reports_reviewed_existing_findings_for_real_repository(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->guardScriptPath(),
            '--root='.$this->repositoryRoot,
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[MDB-TXN-GUARD] Reviewed existing findings:', $output);
        $this->assertStringContainsString('TODO-v0.6.2-laravel-mongodb-transaction-owner-audit-and-simplification', $output);
        $this->assertStringContainsString('[MDB-TXN-GUARD] PASS - no blocked MongoDB transaction architecture findings detected.', $output);
    }

    public function test_canonical_architecture_runner_invokes_transaction_guard(): void
    {
        $process = $this->guardProcess([
            'php',
            $this->architectureGuardrailsScriptPath(),
        ]);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('[MDB-TXN-GUARD] PASS - no blocked MongoDB transaction architecture findings detected.', $output);
        $this->assertStringContainsString('[ARCH-GUARDRAILS] PASS - no architecture violations found.', $output);
    }

    public function test_guard_rejects_new_callback_transaction_use(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Example/NewTransactionOwner.php' => <<<'PHP'
<?php

final class NewTransactionOwner
{
    public function run(object $connection): mixed
    {
        return $connection->transaction(fn () => 'value');
    }
}
PHP,
            'allowlist.php' => "<?php\n\nreturn [];\n",
        ]);

        $process = $this->fixtureGuardProcess($fixtureRoot);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('callback_transaction', $output);
        $this->assertStringContainsString('lacks a reviewed baseline entry', $output);
    }

    public function test_guard_rejects_retry_compensation_around_callback_transaction(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'packages/example/src/RetryingTransaction.php' => <<<'PHP'
<?php

final class RetryingTransaction
{
    public function run(object $connection): mixed
    {
        return $connection->transaction(fn () => 'value', PHP_INT_MAX);
    }
}
PHP,
            'allowlist.php' => <<<'PHP'
<?php

return [[
    'path' => 'packages/example/src/RetryingTransaction.php',
    'shape' => 'callback_transaction',
    'expected_count' => 1,
    'owner' => 'fixture-owner',
    'rationale' => 'Admit the callback only so the sentinel remains independently visible.',
    'follow_up' => 'fixture-follow-up',
]];
PHP,
        ]);

        $process = $this->fixtureGuardProcess($fixtureRoot, ['packages']);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('retry_compensation', $output);
        $this->assertStringContainsString('lacks a reviewed baseline entry', $output);
    }

    public function test_guard_rejects_stale_or_mismatched_baseline_count(): void
    {
        $fixtureRoot = $this->makeFixtureRepo([
            'app/Application/Example/ExistingOwner.php' => <<<'PHP'
<?php

final class ExistingOwner
{
    public function run(object $connection): mixed
    {
        return $connection->transaction(fn () => 'value');
    }
}
PHP,
            'allowlist.php' => <<<'PHP'
<?php

return [[
    'path' => 'app/Application/Example/ExistingOwner.php',
    'shape' => 'callback_transaction',
    'expected_count' => 2,
    'owner' => 'fixture-owner',
    'rationale' => 'Intentional mismatch.',
    'follow_up' => 'fixture-follow-up',
]];
PHP,
        ]);

        $process = $this->fixtureGuardProcess($fixtureRoot);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('expected 2 finding(s), observed 1', $output);
        $this->assertStringContainsString('stale or mismatched baseline', $output);
    }

    /** @param list<string> $scanDirs */
    private function fixtureGuardProcess(string $fixtureRoot, array $scanDirs = ['app']): Process
    {
        $command = [
            'php',
            $this->guardScriptPath(),
            '--root='.$fixtureRoot,
            '--allowlist='.$fixtureRoot.'/allowlist.php',
        ];

        foreach ($scanDirs as $scanDir) {
            $command[] = '--scan-dir='.$scanDir;
        }

        return $this->guardProcess($command);
    }

    /** @param list<string> $command */
    private function guardProcess(array $command): Process
    {
        return new Process($command, $this->repositoryRoot, null, null, 30);
    }

    /** @param array<string, string> $files */
    private function makeFixtureRepo(array $files): string
    {
        $root = sys_get_temp_dir().'/mongodb-transaction-guard-'.bin2hex(random_bytes(6));

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
        return $this->repositoryRoot.'/scripts/mongodb_transaction_guardrails.php';
    }

    private function architectureGuardrailsScriptPath(): string
    {
        return $this->repositoryRoot.'/scripts/architecture_guardrails.php';
    }
}
