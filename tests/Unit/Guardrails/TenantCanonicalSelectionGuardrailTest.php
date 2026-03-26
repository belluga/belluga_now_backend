<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TenantCanonicalSelectionGuardrailTest extends TestCase
{
    private string $repositoryRoot;

    /** @var array<int, string> */
    private array $scanRoots = [
        'tests/Api/v1',
        'tests/Traits',
        'tests/TestCase.php',
        'tests/TestCaseAuthenticated.php',
        'tests/TestCaseTenant.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_tenant_resolution_does_not_use_first_tenant_fallback_in_api_and_test_harness(): void
    {
        $pattern = '/Tenant::query\(\)->first(?:OrFail)?\s*\(/';
        $violations = [];

        foreach ($this->scanRoots as $relativePath) {
            $absolutePath = $this->repositoryRoot.DIRECTORY_SEPARATOR.$relativePath;

            if (is_file($absolutePath)) {
                $this->collectViolationsForFile($absolutePath, $pattern, $violations);

                continue;
            }

            if (! is_dir($absolutePath)) {
                continue;
            }

            foreach ($this->phpFilesInDirectory($absolutePath) as $filePath) {
                $this->collectViolationsForFile($filePath, $pattern, $violations);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Tenant fallback via first()/firstOrFail() is forbidden in API/tenant test harness.\n"
            ."Use resolveCanonicalTenant() / makeCanonicalTenantCurrent() instead.\n"
            .implode("\n", $violations)
        );
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function collectViolationsForFile(string $absoluteFilePath, string $pattern, array &$violations): void
    {
        $contents = file_get_contents($absoluteFilePath);
        if (! is_string($contents) || $contents === '') {
            return;
        }

        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
        if (($matches[0] ?? []) === []) {
            return;
        }

        foreach ($matches[0] as $match) {
            $offset = (int) ($match[1] ?? 0);
            $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
            $relativePath = str_replace(
                $this->repositoryRoot.DIRECTORY_SEPARATOR,
                '',
                $absoluteFilePath
            );
            $violations[] = sprintf(
                '%s:%d',
                $relativePath,
                $line
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesInDirectory(string $absoluteDirectoryPath): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectoryPath)
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if (! $entry->isFile()) {
                continue;
            }

            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            $paths[] = $entry->getPathname();
        }

        return $paths;
    }
}
