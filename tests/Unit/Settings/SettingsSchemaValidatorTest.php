<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Belluga\Settings\Validation\SettingsSchemaValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsSchemaValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_namespaced_and_non_namespaced_patch_keys(): void
    {
        $validator = new SettingsSchemaValidator;
        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'default_duration_hours' => ['type' => 'integer', 'nullable' => false],
                'notes' => ['type' => 'string', 'nullable' => true],
            ],
        );

        $result = $validator->validatePatch($definition, [
            'events.default_duration_hours' => 5,
            'notes' => null,
        ]);

        $this->assertSame(5, $result['default_duration_hours']);
        $this->assertNull($result['notes']);
    }

    #[Test]
    public function it_rejects_null_for_non_nullable_field(): void
    {
        $validator = new SettingsSchemaValidator;
        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'default_duration_hours' => ['type' => 'integer', 'nullable' => false],
            ],
        );

        $this->expectException(ValidationException::class);

        $validator->validatePatch($definition, [
            'default_duration_hours' => null,
        ]);
    }

    #[Test]
    public function it_rejects_wrong_value_type(): void
    {
        $validator = new SettingsSchemaValidator;
        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'default_duration_hours' => ['type' => 'integer', 'nullable' => false],
            ],
        );

        $this->expectException(ValidationException::class);

        $validator->validatePatch($definition, [
            'default_duration_hours' => '5',
        ]);
    }

    #[Test]
    public function it_rejects_envelope_payload_form(): void
    {
        $validator = new SettingsSchemaValidator;
        $definition = new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: null,
            fields: [
                'default_duration_hours' => ['type' => 'integer', 'nullable' => false],
            ],
        );

        $this->expectException(ValidationException::class);

        $validator->validatePatch($definition, [
            'events' => ['default_duration_hours' => 4],
        ]);
    }
}
