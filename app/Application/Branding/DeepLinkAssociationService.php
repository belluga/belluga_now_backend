<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Illuminate\Support\Arr;

class DeepLinkAssociationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildAssetLinks(): array
    {
        $settings = $this->resolveAppLinksSettings();

        $packageName = trim((string) data_get($settings, 'android.package_name', ''));
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
        $settings = $this->resolveAppLinksSettings();

        $teamId = trim((string) data_get($settings, 'ios.team_id', ''));
        $bundleId = trim((string) data_get($settings, 'ios.bundle_id', ''));
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
     * @return array<string, mixed>
     */
    private function resolveAppLinksSettings(): array
    {
        $tenantSettings = TenantSettings::current();
        if ($tenantSettings !== null) {
            return $this->normalizeArray($tenantSettings->getAttribute('app_links'));
        }

        $landlordSettings = LandlordSettings::current();
        if ($landlordSettings !== null) {
            return $this->normalizeArray($landlordSettings->getAttribute('app_links'));
        }

        return [];
    }

    /**
     * @param  mixed  $value
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
     * @param  mixed  $value
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return Arr::undot($value);
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            /** @var array<string, mixed> $copy */
            $copy = $value->getArrayCopy();

            return Arr::undot($copy);
        }
        if ($value instanceof \Traversable) {
            /** @var array<string, mixed> $copy */
            $copy = iterator_to_array($value);

            return Arr::undot($copy);
        }
        if (is_object($value)) {
            /** @var array<string, mixed> $copy */
            $copy = (array) $value;

            return Arr::undot($copy);
        }

        return [];
    }
}

