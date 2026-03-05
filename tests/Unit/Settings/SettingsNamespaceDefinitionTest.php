<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Belluga\Settings\Support\SettingsNamespaceDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsNamespaceDefinitionTest extends TestCase
{
    #[Test]
    public function it_builds_nodes_with_groups_and_i18n_metadata(): void
    {
        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Mode',
                    'label_i18n_key' => 'settings.events.mode.label',
                ],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Stock Enabled',
                    'label_i18n_key' => 'settings.events.stock_enabled.label',
                    'group' => 'advanced',
                    'group_label' => 'Advanced',
                    'group_label_i18n_key' => 'settings.events.group.advanced.label',
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $schema = $definition->toSchemaArray();
        $this->assertArrayHasKey('nodes', $schema);
        $this->assertArrayHasKey('fields', $schema);

        $stockField = collect($schema['fields'])->firstWhere('path', 'stock_enabled');
        $this->assertIsArray($stockField);
        $this->assertSame('settings.events.stock_enabled.label', $stockField['label_i18n_key']);
        $this->assertIsArray($stockField['visible_if']);

        $nodeIds = [];
        $walker = function (array $nodes) use (&$walker, &$nodeIds): void {
            foreach ($nodes as $node) {
                $nodeIds[] = $node['id'] ?? null;
                if (($node['type'] ?? null) === 'group') {
                    $walker($node['children'] ?? []);
                }
            }
        };
        $walker($schema['nodes']);

        $this->assertContains('events.group.advanced', $nodeIds);
    }

    #[Test]
    public function it_rejects_duplicate_field_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'field_a' => ['id' => 'events.shared', 'type' => 'string', 'nullable' => false],
                'field_b' => ['id' => 'events.shared', 'type' => 'string', 'nullable' => false],
            ],
        );
    }

    #[Test]
    public function it_rejects_unknown_condition_references(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    ['field_id' => 'events.unknown', 'operator' => 'equals', 'value' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function it_rejects_invalid_condition_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    ['field_id' => 'events.mode', 'operator' => 'invalid_op', 'value' => 'advanced'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function it_rejects_comparable_operator_on_non_comparable_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    ['field_id' => 'events.mode', 'operator' => 'gt', 'value' => 'advanced'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function it_rejects_condition_expression_above_group_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $groups = [];
        for ($i = 0; $i < 11; $i++) {
            $groups[] = [
                'rules' => [
                    ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'],
                ],
            ];
        }

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => $groups,
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function it_rejects_condition_expression_above_rules_per_group_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $rules = [];
        for ($i = 0; $i < 11; $i++) {
            $rules[] = ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'];
        }

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => [
                            ['rules' => $rules],
                        ],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function it_rejects_condition_expression_above_total_rule_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $groups = [];
        for ($g = 0; $g < 6; $g++) {
            $rules = [];
            for ($r = 0; $r < 9; $r++) {
                $rules[] = ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'];
            }
            $groups[] = ['rules' => $rules];
        }

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => $groups,
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function it_rejects_condition_expression_above_payload_size_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    [
                                        'field_id' => 'events.mode',
                                        'operator' => 'equals',
                                        'value' => str_repeat('x', 17000),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }
}
