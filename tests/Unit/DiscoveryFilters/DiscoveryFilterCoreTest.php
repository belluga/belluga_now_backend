<?php

declare(strict_types=1);

namespace Tests\Unit\DiscoveryFilters;

use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterEntityProviderContract;
use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterSettingsContract;
use Belluga\DiscoveryFilters\Data\DiscoveryFilterDefinition;
use Belluga\DiscoveryFilters\Registry\DiscoveryFilterEntityRegistry;
use Belluga\DiscoveryFilters\Services\DiscoveryFilterCatalogService;
use Belluga\DiscoveryFilters\Services\DiscoveryFilterSelectionRepairService;
use PHPUnit\Framework\TestCase;

final class DiscoveryFilterCoreTest extends TestCase
{
    public function test_registry_resolves_entity_qualified_type_options(): void
    {
        $registry = new DiscoveryFilterEntityRegistry();
        $registry->register(new class implements DiscoveryFilterEntityProviderContract
        {
            public function entity(): string
            {
                return 'event';
            }

            public function types(): array
            {
                return [
                    [
                        'value' => 'show',
                        'label' => 'Show',
                        'allowed_taxonomies' => ['music_genre'],
                    ],
                ];
            }

            public function taxonomiesForTypes(array $typeValues): array
            {
                return [
                    ['slug' => 'music_genre', 'label' => 'Gênero musical'],
                ];
            }
        });

        $this->assertSame(['event'], $registry->entities());
        $this->assertSame(
            [
                'event' => [
                    [
                        'value' => 'show',
                        'label' => 'Show',
                        'allowed_taxonomies' => ['music_genre'],
                    ],
                ],
            ],
            $registry->typesForEntities(['event', 'missing'])
        );
    }

    public function test_definition_normalizes_canonical_entity_type_target_payload(): void
    {
        $definition = DiscoveryFilterDefinition::fromArray([
            'key' => 'Events',
            'surface' => 'Public_Map.Primary',
            'target' => 'Map_Poi',
            'label' => 'Eventos',
            'primary_selection_mode' => 'multi',
            'query' => [
                'entity' => ['Event', 'Event'],
                'types_by_entity' => [
                    'Event' => ['Show', 'Feira'],
                ],
                'taxonomy' => [
                    'Music_Genre' => ['Rock', 'Jazz'],
                ],
            ],
        ]);

        $this->assertSame('events', $definition->key);
        $this->assertSame('public_map.primary', $definition->surface);
        $this->assertSame('map_poi', $definition->target);
        $this->assertSame('multi', $definition->primarySelectionMode);
        $this->assertSame(['event'], $definition->entities);
        $this->assertSame(['event' => ['show', 'feira']], $definition->typesByEntity);
        $this->assertSame(['music_genre' => ['rock', 'jazz']], $definition->taxonomyValuesByGroup);
    }

    public function test_definition_accepts_multiple_alias_and_emits_canonical_multi_selection_mode(): void
    {
        $definition = DiscoveryFilterDefinition::fromArray([
            'key' => 'events',
            'surface' => 'public_map.primary',
            'target' => 'map_poi',
            'label' => 'Eventos',
            'primary_selection_mode' => 'multiple',
        ]);

        $this->assertSame('multi', $definition->primarySelectionMode);
        $this->assertSame('multi', $definition->toArray()['primary_selection_mode']);
    }

    public function test_repair_drops_stale_primary_and_taxonomy_selections(): void
    {
        $catalog = [
            new DiscoveryFilterDefinition(
                key: 'events',
                surface: 'public_map.primary',
                target: 'map_poi',
                label: 'Eventos',
                primarySelectionMode: 'single',
                entities: ['event'],
                typesByEntity: ['event' => ['show']],
                taxonomyValuesByGroup: ['music_genre' => ['rock', 'jazz']],
            ),
        ];

        $repair = (new DiscoveryFilterSelectionRepairService())->repair(
            $catalog,
            [
                'primary' => ['events', 'stale'],
                'taxonomy' => [
                    'music_genre' => ['rock', 'stale-term'],
                    'missing' => ['x'],
                ],
            ],
        );

        $this->assertSame(['events'], $repair['primary']);
        $this->assertSame(['music_genre' => ['rock']], $repair['taxonomy']);
        $this->assertTrue($repair['repaired']);
    }

    public function test_catalog_service_resolves_surface_definitions_from_settings(): void
    {
        $service = new DiscoveryFilterCatalogService(
            new class implements DiscoveryFilterSettingsContract
            {
                public function resolveDiscoveryFiltersSettings(): array
                {
                    return [
                        'surfaces' => [
                            'public_map.primary' => [
                                'filters' => [
                                    [
                                        'key' => 'Events',
                                        'target' => 'Map_Poi',
                                        'label' => 'Eventos',
                                        'query' => [
                                            'entities' => ['Event'],
                                            'types_by_entity' => [
                                                'Event' => ['Show'],
                                            ],
                                        ],
                                    ],
                                    [
                                        'key' => '',
                                        'target' => 'map_poi',
                                        'label' => 'Invalid',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            }
        );

        $definitions = $service->surfaceDefinitions('PUBLIC_MAP.PRIMARY');

        $this->assertCount(1, $definitions);
        $this->assertSame('events', $definitions[0]->key);
        $this->assertSame('public_map.primary', $definitions[0]->surface);
        $this->assertSame('map_poi', $definitions[0]->target);
        $this->assertSame(['event' => ['show']], $definitions[0]->typesByEntity);
    }
}
