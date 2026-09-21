<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AccountProfileTypeCapabilityCatalogGuardrailTest extends TestCase
{
    public function test_legacy_catalog_and_repairer_are_not_runtime_authorities(): void
    {
        $allowed = [
            'app/Application/AccountProfiles/AccountProfileTypeCapabilityCatalog.php',
            'app/Application/AccountProfiles/AccountProfileTypeCapabilityRepairer.php',
        ];

        foreach ($this->phpSources('app') as $relativePath => $source) {
            if (in_array($relativePath, $allowed, true)) {
                continue;
            }

            self::assertStringNotContainsString(
                'AccountProfileTypeCapabilityCatalog',
                $source,
                "{$relativePath} must use the canonical contract-backed registry/resolver.",
            );
            self::assertStringNotContainsString(
                'AccountProfileTypeCapabilityRepairer',
                $source,
                "{$relativePath} must not restore the legacy flat repair path.",
            );
        }
    }

    public function test_legacy_authorities_are_referenced_only_by_immutable_historical_migrations(): void
    {
        $references = [];
        foreach ($this->phpSources('database/migrations/tenants') as $relativePath => $source) {
            if (str_contains($source, 'AccountProfileTypeCapabilityCatalog')
                || str_contains($source, 'AccountProfileTypeCapabilityRepairer')) {
                $references[] = $relativePath;
            }
        }

        sort($references, SORT_STRING);

        self::assertSame([
            'database/migrations/tenants/2026_07_18_000100_canonicalize_profile_type_capabilities.php',
            'database/migrations/tenants/2026_09_02_000100_add_external_links_profile_type_capability.php',
        ], $references);
    }

    public function test_profile_type_requests_delegate_to_the_canonical_resolver(): void
    {
        foreach ([
            'app/Http/Api/v1/Requests/AccountProfileTypeStoreRequest.php',
            'app/Http/Api/v1/Requests/AccountProfileTypeUpdateRequest.php',
        ] as $relativePath) {
            $source = file_get_contents($this->projectRoot().DIRECTORY_SEPARATOR.$relativePath);
            self::assertIsString($source);
            self::assertStringContainsString('AccountProfileCapabilityResolverContract::class', $source);
            self::assertStringContainsString('->validationRules()', $source);
            self::assertStringNotContainsString('AccountProfileTypeCapabilityCatalog', $source);
        }
    }

    /** @return array<string, string> */
    private function phpSources(string $directory): array
    {
        $projectRoot = $this->projectRoot();
        $root = $projectRoot.DIRECTORY_SEPARATOR.$directory;
        $sources = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($projectRoot.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $sources[$relativePath] = $source;
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
