<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Application;

use Belluga\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Belluga\DeepLinks\Contracts\AppLinksSettingsSourceContract;

class DeepLinkAssociationService
{
    public function __construct(
        private readonly AppLinksIdentifierGatewayContract $identifierGateway,
        private readonly AppLinksSettingsSourceContract $settingsSource,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildAssetLinks(): array
    {
        $settings = $this->settingsSource->currentAppLinksSettings();

        $packageName = $this->resolveAndroidPackageName($settings);
        $fingerprints = $this->normalizeFingerprints(data_get($settings, 'android.sha256_cert_fingerprints', []));

        if ($packageName === '' || $fingerprints === []) {
            return [];
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $packageName,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAppleAppSiteAssociation(): array
    {
        $settings = $this->settingsSource->currentAppLinksSettings();

        $teamId = trim((string) data_get($settings, 'ios.team_id', ''));
        $bundleId = $this->resolveIosBundleId($settings);
        $paths = $this->normalizePaths(data_get($settings, 'ios.paths', ['/invite*', '/convites*']));

        if ($teamId === '' || $bundleId === '') {
            return [
                'applinks' => [
                    'apps' => [],
                    'details' => [],
                ],
            ];
        }

        return [
            'applinks' => [
                'apps' => [],
                'details' => [[
                    'appID' => "{$teamId}.{$bundleId}",
                    'paths' => $paths,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveAndroidStoreUrl(array $settings): ?string
    {
        if (! $this->isPlatformPublicationEnabled($settings, 'android')) {
            return null;
        }

        $configured = $this->normalizeText(data_get($settings, 'android.store_url'));
        if ($configured !== null) {
            return $configured;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveIosStoreUrl(array $settings): ?string
    {
        if (! $this->isPlatformPublicationEnabled($settings, 'ios')) {
            return null;
        }

        return $this->normalizeText(data_get($settings, 'ios.store_url'));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function isPlatformPublicationEnabled(array $settings, string $platform): bool
    {
        $enabled = data_get($settings, "{$platform}.enabled");
        if (is_bool($enabled)) {
            return $enabled;
        }
        if (is_numeric($enabled)) {
            return (int) $enabled !== 0;
        }
        if (is_string($enabled)) {
            return in_array(strtolower(trim($enabled)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveAndroidPackageName(array $settings): string
    {
        $identifier = $this->identifierGateway->identifierForPlatform('android');
        if (is_string($identifier) && trim($identifier) !== '') {
            return trim($identifier);
        }

        return trim((string) data_get($settings, 'android.package_name', ''));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveIosBundleId(array $settings): string
    {
        $identifier = $this->identifierGateway->identifierForPlatform('ios');
        if (is_string($identifier) && trim($identifier) !== '') {
            return trim($identifier);
        }

        return trim((string) data_get($settings, 'ios.bundle_id', ''));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFingerprints(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $fingerprint) {
            if (! is_string($fingerprint)) {
                continue;
            }

            $candidate = strtoupper(trim($fingerprint));
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    private function normalizePaths(mixed $value): array
    {
        if (! is_array($value)) {
            return ['/invite*', '/convites*'];
        }

        $paths = [];
        foreach ($value as $path) {
            if (! is_string($path)) {
                continue;
            }

            $candidate = trim($path);
            if ($candidate !== '') {
                $paths[] = $candidate;
            }
        }

        if ($paths === []) {
            return ['/invite*', '/convites*'];
        }

        return array_values(array_unique($paths));
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
