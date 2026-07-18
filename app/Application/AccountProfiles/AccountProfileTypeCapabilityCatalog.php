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

    public const HAS_GALLERY = 'has_gallery';

    public const HAS_NESTED_PROFILE_GROUPS = 'has_nested_profile_groups';

    public const HAS_CONTACT_CHANNELS = 'has_contact_channels';

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
            $this->definition(self::HAS_GALLERY),
            $this->definition(self::HAS_NESTED_PROFILE_GROUPS),
            $this->definition(self::HAS_CONTACT_CHANNELS),
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     * @return array<string, bool>
     */
    public function completeForPersistence(
        string $type,
        array $capabilities = [],
        array $currentCapabilities = [],
    ): array {
        $normalized = [];
        $typeDefaults = $this->typeDefaults($type);

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            $normalized[$key] = array_key_exists($key, $capabilities)
                ? (bool) $capabilities[$key]
                : (is_bool($currentCapabilities[$key] ?? null)
                    ? $currentCapabilities[$key]
                    : ($typeDefaults[$key] ?? $definition['default']));
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array<string, bool>
     */
    public function runtimeCapabilities(array $capabilities): array
    {
        $resolved = [];

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            $resolved[$key] = is_bool($capabilities[$key] ?? null)
                ? $capabilities[$key]
                : false;
        }

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            foreach ($definition['requires'] as $requiredKey) {
                if (($resolved[$key] ?? false) && ! ($resolved[$requiredKey] ?? false)) {
                    $resolved[$key] = false;
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     */
    public function isExplicitlyEnabled(string $key, array $capabilities): bool
    {
        $resolved = $this->runtimeCapabilities($capabilities);

        return $resolved[$key] ?? false;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public function firstDisabledRequirement(string $key, array $capabilities): ?string
    {
        $definition = $this->definitionFor($key);
        if ($definition === null) {
            return null;
        }

        $resolved = $this->runtimeCapabilities($capabilities);
        foreach ($definition['requires'] as $requiredKey) {
            if (! ($resolved[$requiredKey] ?? false)) {
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
     * @return array<string, bool>
     */
    private function typeDefaults(string $type): array
    {
        return match (trim($type)) {
            'personal' => [
                self::IS_QUERYABLE => false,
                self::IS_PUBLICLY_NAVIGABLE => false,
                self::IS_FAVORITABLE => true,
                self::IS_INVITEABLE => true,
                self::IS_PUBLICLY_DISCOVERABLE => false,
                self::IS_POI_ENABLED => false,
                self::HAS_CONTENT => false,
                self::HAS_GALLERY => false,
            ],
            'artist' => [
                self::IS_FAVORITABLE => true,
                self::HAS_GALLERY => true,
            ],
            'venue' => [
                self::IS_FAVORITABLE => true,
                self::IS_POI_ENABLED => true,
                self::HAS_GALLERY => true,
            ],
            default => [],
        };
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
