<?php

declare(strict_types=1);

namespace Belluga\Settings\Validation;

use Belluga\Settings\Contracts\SettingsSchemaValidatorContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Validation\ValidationException;

class SettingsSchemaValidator implements SettingsSchemaValidatorContract
{
    public function validatePatch(SettingsNamespaceDefinition $definition, array $payload): array
    {
        $errors = [];
        $normalized = [];

        if (array_is_list($payload)) {
            throw ValidationException::withMessages([
                'payload' => ['Settings patch payload must be an object/map.'],
            ]);
        }

        if (array_key_exists($definition->namespace, $payload)) {
            $errors[$definition->namespace][] = 'Envelope payload is not supported. Send a direct object payload.';
        }

        foreach ($payload as $rawPath => $value) {
            if (! is_string($rawPath) || trim($rawPath) === '') {
                $errors['payload'][] = 'Patch keys must be non-empty strings.';
                continue;
            }

            $path = $this->normalizePath($definition->namespace, $rawPath);
            $field = $definition->field($path);

            if ($field === null) {
                $errors[$rawPath][] = "Unknown field path [{$rawPath}] for namespace [{$definition->namespace}].";
                continue;
            }

            $nullable = (bool) ($field['nullable'] ?? false);
            $type = (string) ($field['type'] ?? 'mixed');

            if ($value === null) {
                if (! $nullable) {
                    $errors[$rawPath][] = 'This field cannot be null.';
                } else {
                    $normalized[$path] = null;
                }

                continue;
            }

            if (! $this->isTypeCompatible($type, $value)) {
                $errors[$rawPath][] = "Invalid type. Expected {$type}.";
                continue;
            }

            $normalized[$path] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function normalizePath(string $namespace, string $rawPath): string
    {
        $trimmed = trim($rawPath);
        $prefix = $namespace . '.';

        if (str_starts_with($trimmed, $prefix)) {
            return substr($trimmed, strlen($prefix));
        }

        return $trimmed;
    }

    private function isTypeCompatible(string $type, mixed $value): bool
    {
        return match ($type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_array($value),
            default => true,
        };
    }
}
