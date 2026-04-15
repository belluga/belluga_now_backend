<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Models\Landlord\Landlord;
use App\Support\Helpers\ArrayReplaceEmptyAware;

class LandlordBrandingManagementService
{
    private const DEFAULT_THEME_DATA_SETTINGS = [
        'brightness_default' => '',
        'primary_seed_color' => '',
        'secondary_seed_color' => '',
    ];

    private const LOGO_KEYS = [
        'light_logo_uri',
        'dark_logo_uri',
        'light_icon_uri',
        'dark_icon_uri',
        'favicon_uri',
    ];

    private const DEFAULT_PWA_ICON = [
        'source_uri' => '',
        'icon192_uri' => '',
        'icon512_uri' => '',
        'icon_maskable512_uri' => '',
    ];

    private const DEFAULT_PUBLIC_WEB_METADATA = [
        'default_title' => '',
        'default_description' => '',
        'default_image' => '',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $uploadedLogos
     * @param  array<string, string>  $pwaVariants
     * @return array<string, mixed>
     */
    public function update(
        Landlord $landlord,
        array $payload,
        array $uploadedLogos = [],
        array $pwaVariants = []
    ): array {
        $brandingPayload = $this->buildBrandingPayload($payload, $uploadedLogos, $pwaVariants);
        $currentBranding = is_array($landlord->branding_data) ? $landlord->branding_data : [];

        $landlord->branding_data = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            $currentBranding,
            $brandingPayload
        );
        $landlord->branding_data = $this->normalizeBrandingData(
            is_array($landlord->branding_data) ? $landlord->branding_data : []
        );
        $landlord->save();

        return is_array($landlord->branding_data)
            ? $landlord->branding_data
            : $this->normalizeBrandingData($brandingPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $uploadedLogos
     * @param  array<string, string>  $pwaVariants
     * @return array<string, mixed>
     */
    private function buildBrandingPayload(
        array $payload,
        array $uploadedLogos,
        array $pwaVariants
    ): array {
        $logoSettings = $this->defaultLogoSettings();

        foreach (self::LOGO_KEYS as $key) {
            $logoSettings[$key] = (string) ($uploadedLogos[$key]
                ?? $this->stringValue($payload, "logo_settings.{$key}")
                ?? '');
        }

        $pwaIcon = array_merge(self::DEFAULT_PWA_ICON, $pwaVariants);

        $pwaPayload = $payload['logo_settings']['pwa_icon'] ?? null;

        if (is_array($pwaPayload)) {
            $pwaIcon = array_merge($pwaIcon, array_map('strval', $pwaPayload));
        } elseif (is_string($pwaPayload) && $pwaPayload !== '') {
            $pwaIcon['source_uri'] = $pwaPayload;
        }

        if (isset($uploadedLogos['pwa_icon']) && $uploadedLogos['pwa_icon'] !== '') {
            $pwaIcon['source_uri'] = $uploadedLogos['pwa_icon'];
        }

        $brightnessDefault = (string) $this->stringValue(
            $payload,
            'theme_data_settings.brightness_default'
        );

        $primarySeedColor = (string) $this->stringValue(
            $payload,
            'theme_data_settings.primary_seed_color'
        );

        $secondarySeedColor = (string) $this->stringValue(
            $payload,
            'theme_data_settings.secondary_seed_color'
        );

        return [
            'logo_settings' => $logoSettings,
            'theme_data_settings' => [
                'brightness_default' => $brightnessDefault,
                'primary_seed_color' => $primarySeedColor,
                'secondary_seed_color' => $secondarySeedColor,
            ],
            'pwa_icon' => $pwaIcon,
            'public_web_metadata' => [
                'default_title' => (string) $this->stringValue(
                    $payload,
                    'public_web_metadata.default_title'
                ),
                'default_description' => (string) $this->stringValue(
                    $payload,
                    'public_web_metadata.default_description'
                ),
                'default_image' => (string) $this->stringValue(
                    $payload,
                    'public_web_metadata.default_image'
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brandingData
     * @return array<string, mixed>
     */
    private function normalizeBrandingData(array $brandingData): array
    {
        $logoSettings = $this->defaultLogoSettings();
        $rawLogoSettings = $brandingData['logo_settings'] ?? [];
        if (is_array($rawLogoSettings)) {
            foreach (self::LOGO_KEYS as $key) {
                $logoSettings[$key] = (string) ($rawLogoSettings[$key] ?? '');
            }
        }

        $themeDataSettings = self::DEFAULT_THEME_DATA_SETTINGS;
        $rawThemeDataSettings = $brandingData['theme_data_settings'] ?? [];
        if (is_array($rawThemeDataSettings)) {
            foreach (array_keys(self::DEFAULT_THEME_DATA_SETTINGS) as $key) {
                $themeDataSettings[$key] = (string) ($rawThemeDataSettings[$key] ?? '');
            }
        }

        $pwaIcon = self::DEFAULT_PWA_ICON;
        $rawPwaIcon = $brandingData['pwa_icon'] ?? [];
        if (is_array($rawPwaIcon)) {
            foreach (array_keys(self::DEFAULT_PWA_ICON) as $key) {
                $pwaIcon[$key] = (string) ($rawPwaIcon[$key] ?? '');
            }
        }

        $publicWebMetadata = self::DEFAULT_PUBLIC_WEB_METADATA;
        $rawPublicWebMetadata = $brandingData['public_web_metadata'] ?? [];
        if (is_array($rawPublicWebMetadata)) {
            foreach (array_keys(self::DEFAULT_PUBLIC_WEB_METADATA) as $key) {
                $publicWebMetadata[$key] = (string) ($rawPublicWebMetadata[$key] ?? '');
            }
        }

        return [
            'logo_settings' => $logoSettings,
            'theme_data_settings' => $themeDataSettings,
            'pwa_icon' => $pwaIcon,
            'public_web_metadata' => $publicWebMetadata,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaultLogoSettings(): array
    {
        $logoSettings = [];

        foreach (self::LOGO_KEYS as $key) {
            $logoSettings[$key] = '';
        }

        return $logoSettings;
    }

    private function stringValue(array $payload, string $path): ?string
    {
        $segments = explode('.', $path);
        $value = $payload;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        if ($value === null || $value === '') {
            return '';
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
