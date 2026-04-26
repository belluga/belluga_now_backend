<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters\Data;

final readonly class DiscoveryFilterDefinition
{
    /**
     * @param  array<int, string>  $entities
     * @param  array<string, array<int, string>>  $typesByEntity
     * @param  array<string, array<int, string>>  $taxonomyValuesByGroup
     */
    public function __construct(
        public string $key,
        public string $surface,
        public string $target,
        public string $label,
        public string $primarySelectionMode,
        public ?string $icon = null,
        public ?string $color = null,
        public ?string $imageUri = null,
        public bool $overrideMarker = false,
        public ?array $markerOverride = null,
        public array $entities = [],
        public array $typesByEntity = [],
        public array $taxonomyValuesByGroup = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $query = self::normalizeArray($payload['query'] ?? []);

        return new self(
            key: self::normalizeString($payload['key'] ?? ''),
            surface: self::normalizeString($payload['surface'] ?? ''),
            target: self::normalizeString($payload['target'] ?? ''),
            label: trim((string) ($payload['label'] ?? '')),
            primarySelectionMode: self::normalizeSelectionMode($payload['primary_selection_mode'] ?? 'single'),
            icon: self::normalizeNullableString($payload['icon'] ?? ($payload['icon_key'] ?? null)),
            color: self::normalizeNullableString($payload['color'] ?? ($payload['color_hex'] ?? null)),
            imageUri: self::normalizeNullableString($payload['image_uri'] ?? null),
            overrideMarker: (bool) ($payload['override_marker'] ?? false),
            markerOverride: self::normalizeNullableMap($payload['marker_override'] ?? null),
            entities: self::normalizeStringList($query['entities'] ?? ($query['entity'] ?? [])),
            typesByEntity: self::normalizeTypesByEntity($query['types_by_entity'] ?? []),
            taxonomyValuesByGroup: self::normalizeStringMap($query['taxonomy'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'surface' => $this->surface,
            'target' => $this->target,
            'label' => $this->label,
            'primary_selection_mode' => $this->primarySelectionMode,
            ...($this->icon !== null ? ['icon' => $this->icon] : []),
            ...($this->color !== null ? ['color' => $this->color] : []),
            ...($this->imageUri !== null ? ['image_uri' => $this->imageUri] : []),
            'override_marker' => $this->overrideMarker,
            ...($this->markerOverride !== null ? ['marker_override' => $this->markerOverride] : []),
            'query' => [
                'entities' => $this->entities,
                'types_by_entity' => $this->typesByEntity,
                'taxonomy' => $this->taxonomyValuesByGroup,
            ],
        ];
    }

    private static function normalizeSelectionMode(mixed $value): string
    {
        $normalized = self::normalizeString($value);

        return in_array($normalized, ['multi', 'multiple'], true) ? 'multi' : 'single';
    }

    private static function normalizeString(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeNullableMap(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        $raw = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($raw as $item) {
            $candidate = self::normalizeString($item);
            if ($candidate !== '' && ! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function normalizeTypesByEntity(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entity => $types) {
            $entityKey = self::normalizeString($entity);
            if ($entityKey === '') {
                continue;
            }
            $normalized[$entityKey] = self::normalizeStringList($types);
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function normalizeStringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $group => $items) {
            $groupKey = self::normalizeString($group);
            if ($groupKey === '') {
                continue;
            }
            $normalized[$groupKey] = self::normalizeStringList($items);
        }

        return $normalized;
    }
}
