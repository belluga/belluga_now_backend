<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Application\Media\CanonicalImageDefinition;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use RuntimeException;

final class BrandingAssetDefinitionFactory
{
    public const LOGO_KEY_LIGHT = 'light_logo_uri';

    public const LOGO_KEY_DARK = 'dark_logo_uri';

    public const ICON_KEY_LIGHT = 'light_icon_uri';

    public const ICON_KEY_DARK = 'dark_icon_uri';

    public const FAVICON_KEY = 'favicon_uri';

    public const PWA_SOURCE_KEY = 'source_uri';

    public const PWA_ICON_192_KEY = 'icon192_uri';

    public const PWA_ICON_512_KEY = 'icon512_uri';

    public const PWA_ICON_MASKABLE_512_KEY = 'icon_maskable512_uri';

    public function definition(Tenant|Landlord|null $brandable, string $key): CanonicalImageDefinition
    {
        return new CanonicalImageDefinition(
            canonicalPublicPath: $this->canonicalPath($key),
            storageCandidates: $this->storageCandidates($brandable, $key),
        );
    }

    /**
     * @return array<string, CanonicalImageDefinition>
     */
    public function definitions(Tenant|Landlord|null $brandable): array
    {
        $definitions = [];

        foreach ($this->supportedKeys() as $key) {
            $definitions[$key] = $this->definition($brandable, $key);
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public function supportedKeys(): array
    {
        return [
            self::LOGO_KEY_LIGHT,
            self::LOGO_KEY_DARK,
            self::ICON_KEY_LIGHT,
            self::ICON_KEY_DARK,
            self::FAVICON_KEY,
            self::PWA_SOURCE_KEY,
            self::PWA_ICON_192_KEY,
            self::PWA_ICON_512_KEY,
            self::PWA_ICON_MASKABLE_512_KEY,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function storageCandidates(Tenant|Landlord|null $brandable, string $key): array
    {
        $scopePath = $this->scopePath($brandable);

        return match ($key) {
            self::LOGO_KEY_LIGHT,
            self::LOGO_KEY_DARK,
            self::ICON_KEY_LIGHT,
            self::ICON_KEY_DARK => $this->buildCandidates(
                "{$scopePath}/logos",
                $this->logoBasename($key),
                ['png'],
            ),
            self::FAVICON_KEY => $this->buildCandidates(
                "{$scopePath}/logos",
                'favicon',
                ['ico'],
            ),
            self::PWA_SOURCE_KEY => array_merge(
                $this->buildCandidates(
                    "{$scopePath}/pwa",
                    'icon-source',
                    ['png', 'jpg', 'jpeg', 'webp'],
                ),
                $this->buildCandidates(
                    "{$scopePath}/logos",
                    'pwa_icon_source',
                    ['png', 'jpg', 'jpeg', 'webp'],
                ),
            ),
            self::PWA_ICON_192_KEY => $this->buildCandidates("{$scopePath}/pwa", 'icon-192x192', ['png']),
            self::PWA_ICON_512_KEY => $this->buildCandidates("{$scopePath}/pwa", 'icon-512x512', ['png']),
            self::PWA_ICON_MASKABLE_512_KEY => $this->buildCandidates("{$scopePath}/pwa", 'icon-maskable-512x512', ['png']),
            default => throw new RuntimeException("Unsupported branding asset key [{$key}]."),
        };
    }

    /**
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    private function buildCandidates(string $directory, string $baseName, array $extensions): array
    {
        $candidates = [];

        foreach ($extensions as $extension) {
            $candidates[] = "{$directory}/{$baseName}.{$extension}";
        }

        return $candidates;
    }

    private function scopePath(Tenant|Landlord|null $brandable): string
    {
        if ($brandable === null || $brandable instanceof Landlord) {
            return 'landlord';
        }

        $slug = trim((string) $brandable->slug);
        if ($slug === '') {
            throw new RuntimeException('Tenant slug is required for branding asset storage.');
        }

        return "tenants/{$slug}";
    }

    private function canonicalPath(string $key): string
    {
        return match ($key) {
            self::LOGO_KEY_LIGHT => '/logo-light.png',
            self::LOGO_KEY_DARK => '/logo-dark.png',
            self::ICON_KEY_LIGHT => '/icon-light.png',
            self::ICON_KEY_DARK => '/icon-dark.png',
            self::FAVICON_KEY => '/favicon.ico',
            self::PWA_SOURCE_KEY => '/icon/icon-source.png',
            self::PWA_ICON_192_KEY => '/icon/icon-192x192.png',
            self::PWA_ICON_512_KEY => '/icon/icon-512x512.png',
            self::PWA_ICON_MASKABLE_512_KEY => '/icon/icon-maskable-512x512.png',
            default => throw new RuntimeException("Unsupported branding asset key [{$key}]."),
        };
    }

    private function logoBasename(string $key): string
    {
        return match ($key) {
            self::LOGO_KEY_LIGHT => 'light_logo',
            self::LOGO_KEY_DARK => 'dark_logo',
            self::ICON_KEY_LIGHT => 'light_icon',
            self::ICON_KEY_DARK => 'dark_icon',
            default => throw new RuntimeException("Unsupported logo asset key [{$key}]."),
        };
    }
}
