<?php

declare(strict_types=1);

namespace Belluga\Settings\Support;

use InvalidArgumentException;

final class SettingsNamespaceDefinition
{
    /**
     * @param array<string, array<string, mixed>> $fields
     */
    public function __construct(
        public readonly string $namespace,
        public readonly string $scope,
        public readonly string $label,
        public readonly ?string $groupLabel,
        public readonly ?string $ability,
        public readonly array $fields,
        public readonly int $order = 0,
    ) {
        if (! in_array($this->scope, ['tenant', 'landlord'], true)) {
            throw new InvalidArgumentException("Invalid settings scope [{$this->scope}].");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $path): ?array
    {
        return $this->fields[$path] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchemaArray(): array
    {
        $fields = [];

        foreach ($this->fields as $path => $meta) {
            $fields[] = [
                'id' => $this->namespace . '.' . $path,
                'path' => $path,
                'label' => (string) ($meta['label'] ?? $path),
                'type' => (string) ($meta['type'] ?? 'mixed'),
                'nullable' => (bool) ($meta['nullable'] ?? false),
                'readonly' => (bool) ($meta['readonly'] ?? false),
                'order' => (int) ($meta['order'] ?? 0),
            ];
        }

        usort($fields, static function (array $a, array $b): int {
            $order = $a['order'] <=> $b['order'];
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) $a['path'], (string) $b['path']);
        });

        return [
            'namespace' => $this->namespace,
            'scope' => $this->scope,
            'label' => $this->label,
            'group_label' => $this->groupLabel,
            'ability' => $this->ability,
            'order' => $this->order,
            'fields' => $fields,
        ];
    }
}
