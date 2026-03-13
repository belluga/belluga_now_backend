<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Tests\TestCase;

class EventsTicketingDecouplingTest extends TestCase
{
    public function test_events_package_does_not_import_ticketing_package_symbols(): void
    {
        $eventsSourceRoot = base_path('packages/belluga/belluga_events/src');
        $directory = new \RecursiveDirectoryIterator($eventsSourceRoot);
        $iterator = new \RecursiveIteratorIterator($directory);

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents, "Expected readable PHP file [{$file->getPathname()}].");
            $this->assertStringNotContainsString(
                'Belluga\\Ticketing\\',
                $contents,
                "Events package must not import ticketing symbols [{$file->getPathname()}]."
            );
        }
    }
}
