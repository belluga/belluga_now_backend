<?php

declare(strict_types=1);

namespace Belluga\Settings\Support;

use Belluga\Settings\Support\Conditions\ConditionExpression;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class SettingsNamespaceDefinition
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_SCOPES = ['tenant', 'landlord'];

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_TYPES = ['boolean', 'integer', 'number', 'string', 'array', 'object', 'date', 'datetime', 'mixed'];

    /**
     * @var array<string, array{id:string,type:string,nullable:bool}>
     */
    private array $fieldReferences = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $fieldsById = [];

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function __construct(
        public readonly string $namespace,
        public readonly string $scope,
        public readonly string $label,
        public readonly ?string $groupLabel,
        public readonly ?string $ability,
        public array $fields,
        public readonly int $order = 0,
        public readonly ?string $subgroupLabel = null,
        public readonly ?string $labelI18nKey = null,
        public readonly ?string $description = null,
        public readonly ?string $descriptionI18nKey = null,
        public readonly ?string $icon = null,
    ) {
        if (! in_array($this->scope, self::SUPPORTED_SCOPES, true)) {
            throw new InvalidArgumentException("Invalid settings scope [{$this->scope}].");
        }

        if (! preg_match('/^[a-z0-9_]+$/', $this->namespace)) {
            throw new InvalidArgumentException("Invalid namespace [{$this->namespace}]. Use snake_case.");
        }

        $normalized = $this->normalizeFields($this->fields);
        $this->fields = $this->normalizeFieldConditions($normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $path): ?array
    {
        return $this->fields[$path] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fieldById(string $id): ?array
    {
        return $this->fieldsById[$id] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function fieldIds(): array
    {
        return array_keys($this->fieldsById);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function applyDefaults(array $values): array
    {
        foreach ($this->fields as $path => $field) {
            if (! array_key_exists('default', $field)) {
                continue;
            }

            if (Arr::has($values, $path)) {
                continue;
            }

            Arr::set($values, $path, $field['default']);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchemaArray(): array
    {
        $fields = array_values($this->fields);
        usort($fields, static function (array $a, array $b): int {
            $order = (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0);
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
        });

        return [
            'namespace' => $this->namespace,
            'scope' => $this->scope,
            'label' => $this->label,
            'label_i18n_key' => $this->labelI18nKey,
            'description' => $this->description,
            'description_i18n_key' => $this->descriptionI18nKey,
            'icon' => $this->icon,
            'group_label' => $this->groupLabel,
            'subgroup_label' => $this->subgroupLabel,
            'ability' => $this->ability,
            'order' => $this->order,
            'fields' => array_values($fields),
            'nodes' => $this->buildSchemaTree($fields),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rawFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeFields(array $rawFields): array
    {
        if ($rawFields === []) {
            throw new InvalidArgumentException("Namespace [{$this->namespace}] requires at least one field.");
        }

        $normalized = [];
        foreach ($rawFields as $path => $meta) {
            if (! is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException("Namespace [{$this->namespace}] has an invalid field path.");
            }

            if (! is_array($meta)) {
                throw new InvalidArgumentException("Field [{$path}] in namespace [{$this->namespace}] must be an object.");
            }

            $id = $meta['id'] ?? ($this->namespace.'.'.$path);
            if (! is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException("Field [{$path}] in namespace [{$this->namespace}] has an invalid id.");
            }

            if (isset($this->fieldsById[$id])) {
                throw new InvalidArgumentException("Duplicate field id [{$id}] in namespace [{$this->namespace}].");
            }

            $type = (string) ($meta['type'] ?? 'mixed');
            if (! in_array($type, self::SUPPORTED_TYPES, true)) {
                throw new InvalidArgumentException("Field [{$path}] in namespace [{$this->namespace}] has unsupported type [{$type}].");
            }

            $nullable = (bool) ($meta['nullable'] ?? false);
            if (array_key_exists('default', $meta) && ! $this->isTypeCompatible($type, $nullable, $meta['default'])) {
                throw new InvalidArgumentException("Field [{$path}] in namespace [{$this->namespace}] has invalid default value type.");
            }

            $field = [
                'id' => $id,
                'path' => $path,
                'label' => (string) ($meta['label'] ?? $path),
                'label_i18n_key' => $this->nullableString($meta['label_i18n_key'] ?? null),
                'description' => $this->nullableString($meta['description'] ?? null),
                'description_i18n_key' => $this->nullableString($meta['description_i18n_key'] ?? null),
                'icon' => $this->nullableString($meta['icon'] ?? null),
                'type' => $type,
                'nullable' => $nullable,
                'readonly' => (bool) ($meta['readonly'] ?? false),
                'deprecated' => (bool) ($meta['deprecated'] ?? false),
                'order' => (int) ($meta['order'] ?? 0),
                'group' => $this->nullableString($meta['group'] ?? null),
                'subgroup' => $this->nullableString($meta['subgroup'] ?? null),
                'group_label' => $this->nullableString($meta['group_label'] ?? null),
                'subgroup_label' => $this->nullableString($meta['subgroup_label'] ?? null),
                'group_label_i18n_key' => $this->nullableString($meta['group_label_i18n_key'] ?? null),
                'subgroup_label_i18n_key' => $this->nullableString($meta['subgroup_label_i18n_key'] ?? null),
                'group_icon' => $this->nullableString($meta['group_icon'] ?? null),
                'subgroup_icon' => $this->nullableString($meta['subgroup_icon'] ?? null),
                'options' => $this->normalizeOptions($meta['options'] ?? []),
                'visible_if_raw' => $meta['visible_if'] ?? null,
                'enabled_if_raw' => $meta['enabled_if'] ?? null,
            ];

            if (array_key_exists('default', $meta)) {
                $field['default'] = $meta['default'];
            }

            $normalized[$path] = $field;
            $this->fieldsById[$id] = $field;
            $this->fieldReferences[$id] = ['id' => $id, 'type' => $type, 'nullable' => $nullable];
            $this->fieldReferences[$path] = ['id' => $id, 'type' => $type, 'nullable' => $nullable];
            $this->fieldReferences[$this->namespace.'.'.$path] = ['id' => $id, 'type' => $type, 'nullable' => $nullable];
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $normalizedFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeFieldConditions(array $normalizedFields): array
    {
        foreach ($normalizedFields as $path => $field) {
            foreach (['visible_if_raw' => 'visible_if', 'enabled_if_raw' => 'enabled_if'] as $rawKey => $targetKey) {
                if (! array_key_exists($rawKey, $field) || $field[$rawKey] === null) {
                    continue;
                }

                if (! is_array($field[$rawKey])) {
                    throw new InvalidArgumentException("Field [{$path}] in namespace [{$this->namespace}] has invalid {$targetKey} payload.");
                }

                $expression = ConditionExpression::fromArray($field[$rawKey], $this->fieldReferences);
                $field[$targetKey] = $expression->toArray();
            }

            unset($field['visible_if_raw'], $field['enabled_if_raw']);
            $normalizedFields[$path] = $field;
        }

        return $normalizedFields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function buildSchemaTree(array $fields): array
    {
        $root = [];
        $index = [];

        foreach ($fields as $field) {
            $segments = $this->resolveSegments($field);
            if ($segments === []) {
                $root[] = $this->fieldNode($field);

                continue;
            }

            $children = &$root;
            $prefix = [];

            foreach ($segments as $segment) {
                $prefix[] = $segment['key'];
                $groupKey = implode('.', $prefix);
                $groupId = $this->namespace.'.group.'.$groupKey;

                if (! isset($index[$groupId])) {
                    $index[$groupId] = [
                        'type' => 'group',
                        'id' => $groupId,
                        'label' => $segment['label'],
                        'label_i18n_key' => $segment['label_i18n_key'],
                        'icon' => $segment['icon'],
                        'order' => (int) ($field['order'] ?? 0),
                        'children' => [],
                    ];
                    $children[] = &$index[$groupId];
                }

                $index[$groupId]['order'] = min((int) ($index[$groupId]['order'] ?? 0), (int) ($field['order'] ?? 0));
                $children = &$index[$groupId]['children'];
            }

            $children[] = $this->fieldNode($field);
        }

        return $this->sortNodes($root);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, array{key:string,label:string,label_i18n_key:?string,icon:?string}>
     */
    private function resolveSegments(array $field): array
    {
        $segments = [];

        $group = $field['group'] ?? null;
        $subgroup = $field['subgroup'] ?? null;
        if (is_string($group) && $group !== '') {
            $segments[] = [
                'key' => $this->normalizeSegmentKey($group),
                'label' => $field['group_label'] ?? $this->humanizeSegment($group),
                'label_i18n_key' => $field['group_label_i18n_key'] ?? null,
                'icon' => $field['group_icon'] ?? null,
            ];

            if (is_string($subgroup) && $subgroup !== '') {
                $segments[] = [
                    'key' => $this->normalizeSegmentKey($subgroup),
                    'label' => $field['subgroup_label'] ?? $this->humanizeSegment($subgroup),
                    'label_i18n_key' => $field['subgroup_label_i18n_key'] ?? null,
                    'icon' => $field['subgroup_icon'] ?? null,
                ];
            }

            return $segments;
        }

        $path = (string) ($field['path'] ?? '');
        $parts = explode('.', $path);
        array_pop($parts);

        foreach ($parts as $part) {
            $segments[] = [
                'key' => $this->normalizeSegmentKey($part),
                'label' => $this->humanizeSegment($part),
                'label_i18n_key' => null,
                'icon' => null,
            ];
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function fieldNode(array $field): array
    {
        return [
            'type' => 'field',
            'id' => $field['id'],
            'path' => $field['path'],
            'label' => $field['label'],
            'label_i18n_key' => $field['label_i18n_key'],
            'description' => $field['description'],
            'description_i18n_key' => $field['description_i18n_key'],
            'icon' => $field['icon'],
            'field_type' => $field['type'],
            'nullable' => $field['nullable'],
            'readonly' => $field['readonly'],
            'deprecated' => $field['deprecated'],
            'order' => $field['order'],
            'default' => $field['default'] ?? null,
            'options' => $field['options'],
            'visible_if' => $field['visible_if'] ?? null,
            'enabled_if' => $field['enabled_if'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function sortNodes(array $nodes): array
    {
        foreach ($nodes as &$node) {
            if (($node['type'] ?? null) === 'group') {
                $node['children'] = $this->sortNodes((array) ($node['children'] ?? []));
            }
        }
        unset($node);

        usort($nodes, static function (array $left, array $right): int {
            $order = ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        return array_values($nodes);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $index => $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? null;
                $label = $option['label'] ?? $value;
                if (! is_scalar($value) && $value !== null) {
                    throw new InvalidArgumentException("Option [{$index}] in namespace [{$this->namespace}] has invalid value.");
                }

                $normalized[] = [
                    'value' => $value,
                    'label' => is_string($label) ? $label : (is_scalar($label) ? (string) $label : ''),
                    'label_i18n_key' => $this->nullableString($option['label_i18n_key'] ?? null),
                ];

                continue;
            }

            if (is_scalar($option) || $option === null) {
                $normalized[] = [
                    'value' => $option,
                    'label' => (string) $option,
                    'label_i18n_key' => null,
                ];

                continue;
            }

            throw new InvalidArgumentException("Option [{$index}] in namespace [{$this->namespace}] has invalid format.");
        }

        return $normalized;
    }

    private function normalizeSegmentKey(string $segment): string
    {
        $segment = strtolower(trim($segment));
        $segment = str_replace([' ', '-'], '_', $segment);
        $segment = preg_replace('/[^a-z0-9_]/', '_', $segment) ?? $segment;
        $segment = preg_replace('/_+/', '_', $segment) ?? $segment;

        return trim($segment, '_');
    }

    private function humanizeSegment(string $segment): string
    {
        $segment = str_replace(['_', '-'], ' ', trim($segment));

        return ucwords($segment);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isTypeCompatible(string $type, bool $nullable, mixed $value): bool
    {
        if ($value === null) {
            return $nullable;
        }

        return match ($type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string', 'date', 'datetime' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'mixed' => true,
            default => false,
        };
    }
}
