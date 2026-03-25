<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use Tests\TestCase;

class MediaPackageDecouplingTest extends TestCase
{
    public function test_media_package_sources_do_not_reference_host_app_namespace(): void
    {
        $files = [
            base_path('packages/belluga/belluga_media/src/Application/ModelMediaService.php'),
            base_path('packages/belluga/belluga_media/src/MediaServiceProvider.php'),
            base_path('packages/belluga/belluga_media/src/Contracts/TenantMediaScopeResolverContract.php'),
            base_path('packages/belluga/belluga_media/src/Support/MediaModelDefinition.php'),
        ];

        foreach ($files as $filePath) {
            $contents = file_get_contents($filePath);
            $this->assertIsString($contents, "Expected readable package file [{$filePath}].");
            $this->assertStringNotContainsString(
                'App\\',
                $contents,
                "Package source cannot reference host namespace [{$filePath}]."
            );
        }
    }
}

