<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Tests\TestCase;

class EventsPackageDecouplingTest extends TestCase
{
    public function test_package_async_operational_services_do_not_reference_host_map_jobs(): void
    {
        $services = [
            base_path('packages/belluga/belluga_events/src/Application/Operations/EventDlqAlertService.php'),
            base_path('packages/belluga/belluga_events/src/Application/Operations/QueueEventAsyncMetricsProvider.php'),
        ];

        foreach ($services as $servicePath) {
            $contents = file_get_contents($servicePath);
            $this->assertIsString($contents, "Expected readable service file [{$servicePath}].");
            $this->assertStringNotContainsString(
                'App\\Jobs\\MapPois\\',
                $contents,
                "Package service must not hardcode host map jobs [{$servicePath}]."
            );
        }
    }

    public function test_events_package_sources_do_not_reference_host_app_namespace(): void
    {
        $root = base_path('packages/belluga/belluga_events/src');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents, "Expected readable package file [{$file->getPathname()}].");
            $this->assertStringNotContainsString(
                'App\\',
                $contents,
                "Events package source cannot reference host namespace [{$file->getPathname()}]."
            );
        }
    }

    public function test_events_package_uses_host_bound_content_sanitizer_contract(): void
    {
        $contents = file_get_contents(base_path('packages/belluga/belluga_events/src/Application/Events/EventManagementService.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('EventContentSanitizerContract', $contents);
        $this->assertStringContainsString('$this->contentSanitizer->sanitize', $contents);
        $this->assertStringNotContainsString('Belluga\\RichText\\', $contents);
    }
}
