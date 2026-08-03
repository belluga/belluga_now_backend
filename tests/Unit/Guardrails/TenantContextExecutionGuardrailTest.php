<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;

final class TenantContextExecutionGuardrailTest extends TestCase
{
    private string $repositoryRoot;

    /**
     * @var array<int, string>
     */
    private array $tenantContextOwnerPaths = [
        'app/Integration/Push/PushTenantContextAdapter.php',
        'app/Integration/Settings/TenantScopeContextAdapter.php',
        'app/Integration/Events/TenantExecutionContextAdapter.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_canonical_tenant_context_owners_do_not_delegate_to_vendor_execute_helpers(): void
    {
        foreach ($this->tenantContextOwnerPaths as $relativePath) {
            $source = $this->readSource($relativePath);

            $this->assertStringContainsString(
                'makeCurrent(',
                $source,
                "{$relativePath} must explicitly activate tenant context."
            );
            $this->assertStringContainsString(
                'finally',
                $source,
                "{$relativePath} must restore tenant context with explicit finally semantics."
            );
            $this->assertStringContainsString(
                'DB::setDefaultConnection',
                $source,
                "{$relativePath} must explicitly restore the selected default connection."
            );
            $this->assertStringContainsString(
                'previousTenant',
                $source,
                "{$relativePath} must keep an explicit previous-tenant restoration path."
            );
            $this->assertStringNotContainsString(
                '->execute(',
                $source,
                "{$relativePath} must not rely on Tenant::execute(); its exception path is not the canonical restoration contract."
            );
            $this->assertStringNotContainsString(
                '->callback(',
                $source,
                "{$relativePath} must not rely on Tenant::callback(); use explicit try/finally restoration."
            );
        }
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = $this->repositoryRoot.DIRECTORY_SEPARATOR.$relativePath;
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }
}
