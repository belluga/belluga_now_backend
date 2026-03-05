<?php

declare(strict_types=1);

namespace Belluga\Settings\Contracts;

use Belluga\Settings\Support\SettingsNamespaceDefinition;

interface SettingsSchemaValidatorContract
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validatePatch(SettingsNamespaceDefinition $definition, array $payload): array;
}
