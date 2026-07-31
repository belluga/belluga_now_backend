<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\RuntimeDiscoveryFilterCatalogService;
use ReflectionClass;
use Tests\TestCase;

final class RuntimeDiscoveryFilterCatalogServiceTest extends TestCase
{
    public function test_repair_selection_drops_unsupported_primary_and_taxonomy_entries(): void
    {
        $service = (new ReflectionClass(
            RuntimeDiscoveryFilterCatalogService::class
        ))->newInstanceWithoutConstructor();

        $repaired = $service->repairSelectionAgainstCanonicalCatalog(
            [
                'primary' => ['venues', 'stale'],
                'taxonomy' => [
                    'cuisine' => ['italian', 'martian'],
                    'missing' => ['x'],
                ],
            ],
            [
                'filters' => [
                    ['key' => 'venues'],
                    ['key' => 'artists'],
                ],
                'taxonomy_options' => [
                    'cuisine' => [
                        'key' => 'cuisine',
                        'label' => 'Cuisine',
                        'terms_truncated' => false,
                        'terms_limit' => 2,
                        'terms' => [
                            ['value' => 'italian', 'label' => 'Italian'],
                            ['value' => 'japanese', 'label' => 'Japanese'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame(['venues'], $repaired['primary']);
        $this->assertSame(['cuisine' => ['italian']], $repaired['taxonomy']);
        $this->assertTrue($repaired['changed']);
    }

    public function test_repair_selection_keeps_supported_taxonomy_values_when_terms_are_truncated(): void
    {
        $service = (new ReflectionClass(
            RuntimeDiscoveryFilterCatalogService::class
        ))->newInstanceWithoutConstructor();

        $repaired = $service->repairSelectionAgainstCanonicalCatalog(
            [
                'primary' => ['venues'],
                'taxonomy' => [
                    'cuisine' => ['martian'],
                ],
            ],
            [
                'filters' => [
                    ['key' => 'venues'],
                ],
                'taxonomy_options' => [
                    'cuisine' => [
                        'key' => 'cuisine',
                        'label' => 'Cuisine',
                        'terms_truncated' => true,
                        'terms_limit' => 1,
                        'terms' => [
                            ['value' => 'italian', 'label' => 'Italian'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame(['venues'], $repaired['primary']);
        $this->assertSame(['cuisine' => ['martian']], $repaired['taxonomy']);
        $this->assertFalse($repaired['changed']);
    }
}
