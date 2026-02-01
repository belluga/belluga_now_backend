<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Models\Landlord\Landlord;
use App\Support\Helpers\ArrayReplaceEmptyAware;

class LandlordBrandingManagementService
{
    private const LOGO_KEYS = [
        'light_logo_uri',
        'dark_logo_uri',
        'light_icon_uri',
        'dark_icon_uri',
        'favicon_uri',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $uploadedLogos
     * @param array<string, string> $pwaVariants
     * @return array<string, mixed>
     */
    public function update(
        Landlord $landlord,
        array $payload,
        array $uploadedLogos = [],
        array $pwaVariants = []
    ): array {
        $brandingPayload = $this->buildBrandingPayload($payload, $uploadedLogos, $pwaVariants);

        $landlord->branding_data = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            $landlord->branding_data ?? [],
            $brandingPayload
        );
        $landlord->save();

        return $landlord->branding_data ?? $brandingPayload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $uploadedLogos
     * @param array<string, string> $pwaVariants
     * @return array<string, mixed>
     */
    private function buildBrandingPayload(
        array $payload,
        array $uploadedLogos,
        array $pwaVariants
    ): array {
        $logoSettings = [];

        foreach (self::LOGO_KEYS as $key) {
            $logoSettings[$key] = (string) ($uploadedLogos[$key]
                ?? $this->stringValue($payload, "logo_settings.{$key}")
                ?? '');
        }

        $pwaIcon = array_merge([
            'source_uri' => '',
            'icon192_uri' => '',
            'icon512_uri' => '',
            'icon_maskable512_uri' => '',
        ], $pwaVariants);

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
        ];
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
