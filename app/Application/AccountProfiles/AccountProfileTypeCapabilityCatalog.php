<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfileTypeCapabilityCatalog
{
    public const IS_QUERYABLE = 'is_queryable';
    public const IS_PUBLICLY_NAVIGABLE = 'is_publicly_navigable';
    public const IS_PUBLICLY_DISCOVERABLE = 'is_publicly_discoverable';
    public const IS_FAVORITABLE = 'is_favoritable';
    public const IS_INVITEABLE = 'is_inviteable';
    public const IS_POI_ENABLED = 'is_poi_enabled';
    public const IS_REFERENCE_LOCATION_ENABLED = 'is_reference_location_enabled';
    public const HAS_BIO = 'has_bio';
    public const HAS_CONTENT = 'has_content';
    public const HAS_TAXONOMIES = 'has_taxonomies';
    public const HAS_AVATAR = 'has_avatar';
    public const HAS_COVER = 'has_cover';
    public const HAS_EVENTS = 'has_events';
    public const HAS_NESTED_PROFILE_GROUPS = 'has_nested_profile_groups';

    /**
     * @return array<int, array{key:string, default:bool, requires:array<int, string>}>
     */
    public function definitions(): array
    {
        return [
            $this->definition(self::IS_QUERYABLE, default: true),
            $this->definition(self::IS_PUBLICLY_NAVIGABLE, default: true),
            $this->definition(self::IS_PUBLICLY_DISCOVERABLE, default: true),
            $this->definition(self::IS_FAVORITABLE),
            $this->definition(self::IS_INVITEABLE),
            $this->definition(self::IS_POI_ENABLED),
            $this->definition(self::IS_REFERENCE_LOCATION_ENABLED, requires: [self::IS_POI_ENABLED]),
            $this->definition(self::HAS_BIO),
            $this->definition(self::HAS_CONTENT),
            $this->definition(self::HAS_TAXONOMIES),
            $this->definition(self::HAS_AVATAR),
            $this->definition(self::HAS_COVER),
            $this->definition(self::HAS_EVENTS),
            $this->definition(self::HAS_NESTED_PROFILE_GROUPS),
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     * @return array<string, bool>
     */
    public function normalize(array $capabilities, array $currentCapabilities = []): array
    {
        $normalized = [];

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            $normalized[$key] = array_key_exists($key, $capabilities)
                ? (bool) $capabilities[$key]
                : (array_key_exists($key, $currentCapabilities)
                    ? (bool) $currentCapabilities[$key]
                    : $definition['default']);
        }

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            foreach ($definition['requires'] as $requiredKey) {
                if (($normalized[$key] ?? false) && ! ($normalized[$requiredKey] ?? false)) {
                    $normalized[$key] = false;
                    break;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     */
    public function isEnabled(string $key, array $capabilities, array $currentCapabilities = []): bool
    {
        $normalized = $this->normalize($capabilities, $currentCapabilities);

        return (bool) ($normalized[$key] ?? false);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     */
    public function firstDisabledRequirement(string $key, array $capabilities, array $currentCapabilities = []): ?string
    {
        $definition = $this->definitionFor($key);
        if ($definition === null) {
            return null;
        }

        $normalized = $this->normalize($capabilities, $currentCapabilities);
        foreach ($definition['requires'] as $requiredKey) {
            if (! ($normalized[$requiredKey] ?? false)) {
                return $requiredKey;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        $rules = [
            'capabilities' => ['sometimes', 'array'],
        ];

        foreach ($this->definitions() as $definition) {
            $rules["capabilities.{$definition['key']}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @param  array<int, string>  $requires
     * @return array{key:string, default:bool, requires:array<int, string>}
     */
    private function definition(string $key, bool $default = false, array $requires = []): array
    {
        return [
            'key' => $key,
            'default' => $default,
            'requires' => $requires,
        ];
    }

    /**
     * @return array{key:string, default:bool, requires:array<int, string>}|null
     */
    private function definitionFor(string $key): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }
}
