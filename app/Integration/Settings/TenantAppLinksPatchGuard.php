<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Application\Tenants\TenantAppDomainResolverService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class TenantAppLinksPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly TenantAppDomainResolverService $appDomainResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {
        if ($scope !== 'tenant' || $namespace !== 'app_links') {
            return;
        }

        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }

        $current = $this->normalizeSettingsArray(TenantSettings::current()?->getAttribute('app_links'));
        $patch = $this->normalizePatchPayload($payload, $definition->namespace);
        foreach ($patch as $path => $value) {
            Arr::set($current, $path, $value);
        }

        $errors = [];
        $androidFingerprints = $this->normalizeFingerprints(data_get($current, 'android.sha256_cert_fingerprints', []));
        if ($androidFingerprints !== []
            && ! $this->appDomainResolver->hasIdentifierForPlatform($tenant, Tenant::APP_PLATFORM_ANDROID)) {
            $errors['android.sha256_cert_fingerprints'][] = 'Configure Android app identifier before saving fingerprints.';
        }

        $iosTeamId = trim((string) data_get($current, 'ios.team_id', ''));
        if ($iosTeamId !== ''
            && ! $this->appDomainResolver->hasIdentifierForPlatform($tenant, Tenant::APP_PLATFORM_IOS)) {
            $errors['ios.team_id'][] = 'Configure iOS app identifier before saving team_id.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFingerprints(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $candidate = strtoupper(trim($entry));
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSettingsArray(mixed $raw): array
    {
        if ($raw instanceof \MongoDB\Model\BSONDocument || $raw instanceof \MongoDB\Model\BSONArray) {
            return Arr::undot($raw->getArrayCopy());
        }
        if (is_array($raw)) {
            return Arr::undot($raw);
        }
        if ($raw instanceof \Traversable) {
            return Arr::undot(iterator_to_array($raw));
        }
        if (is_object($raw)) {
            return Arr::undot((array) $raw);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePatchPayload(array $payload, string $namespace): array
    {
        $normalized = [];
        foreach (Arr::dot(Arr::undot($payload)) as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $trimmed = trim($key);
            $prefix = $namespace.'.';
            if (str_starts_with($trimmed, $prefix)) {
                $trimmed = substr($trimmed, strlen($prefix));
            }

            $normalized[$trimmed] = $value;
        }

        return $normalized;
    }
}
