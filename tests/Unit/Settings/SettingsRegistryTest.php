<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Belluga\Settings\Registry\InMemorySettingsRegistry;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SettingsRegistryTest extends TestCase
{
    #[Test]
    public function it_rejects_duplicate_namespace_registration_within_same_scope(): void
    {
        $registry = new InMemorySettingsRegistry();

        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
            ],
        );

        $registry->register($definition);

        $this->expectException(RuntimeException::class);
        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events Duplicate',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
            ],
        ));
    }

    #[Test]
    public function it_allows_same_namespace_in_different_scopes(): void
    {
        $registry = new InMemorySettingsRegistry();

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events Tenant',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
            ],
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'landlord',
            label: 'Events Landlord',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
            ],
        ));

        $this->assertCount(1, $registry->all('tenant'));
        $this->assertCount(1, $registry->all('landlord'));
    }

    #[Test]
    public function namespace_key_is_immutable_after_registration(): void
    {
        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'mode' => ['type' => 'string', 'nullable' => false],
            ],
        );

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line readonly assignment is tested intentionally */
        $definition->namespace = 'events_new';
    }
}
