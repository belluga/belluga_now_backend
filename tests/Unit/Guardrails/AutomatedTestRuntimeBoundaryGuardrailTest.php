<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AutomatedTestRuntimeBoundaryGuardrailTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_architecture_guard_rejects_a_test_that_bootstraps_an_interactive_artisan_shell(): void
    {
        $fixturePath = $this->repositoryRoot.'/tests/Unit/Guardrails/__RuntimeBoundaryFixture.php';
        $token = 'tin'.'ker';
        file_put_contents($fixturePath, "<?php\nnew Symfony\\Component\\Process\\Process(['php', 'artisan', '{$token}']);\n");

        try {
            $process = new Process(['php', $this->repositoryRoot.'/scripts/architecture_guardrails.php'], $this->repositoryRoot, null, null, 30);
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('LAR-TEST-NO-TINKER', $output);
            $this->assertStringContainsString('tests/Unit/Guardrails/__RuntimeBoundaryFixture.php', $output);
        } finally {
            if (is_file($fixturePath)) {
                unlink($fixturePath);
            }
        }
    }
}
