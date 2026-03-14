<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class TicketingEventsDecouplingTest extends TestCase
{
    public function test_ticketing_package_does_not_import_events_package_symbols(): void
    {
        $ticketingSourceRoot = base_path('packages/belluga/belluga_ticketing/src');
        $directory = new \RecursiveDirectoryIterator($ticketingSourceRoot);
        $iterator = new \RecursiveIteratorIterator($directory);

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents, "Expected readable PHP file [{$file->getPathname()}].");
            $this->assertStringNotContainsString(
                'Belluga\\Events\\',
                $contents,
                "Ticketing package must not import events symbols [{$file->getPathname()}]."
            );
        }
    }
}
