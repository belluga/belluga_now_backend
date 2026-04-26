<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Application\Auth\TenantPublicAuthMethodResolver;
use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class TenantPublicAuthMethodPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly TenantPublicAuthMethodResolver $resolver,
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
        if ($namespace !== 'tenant_public_auth' || ! in_array($scope, ['landlord', 'tenant'], true)) {
            return;
        }

        $normalizedPatch = $this->normalizePatchPayload($payload, $definition->namespace);

        if ($scope === 'landlord') {
            $this->guardLandlordPatch($normalizedPatch, $definition);

            return;
        }

        $this->guardTenantPatch($normalizedPatch, $definition);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePatchPayload(array $payload, string $namespace): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guardLandlordPatch(array $payload, SettingsNamespaceDefinition $definition): void
    {
        $current = $this->landlordCurrentConfig();
        foreach ($payload as $path => $value) {
            Arr::set($current, $path, $value);
        }

        $errors = [];
        $availableMethods = $this->resolver->normalizeMethods($current['available_methods'] ?? []);
        if ($availableMethods === []) {
            $errors['available_methods'][] = 'Configure at least one tenant-public auth method.';
        }

        if (isset($current['allow_tenant_customization']) && ! is_bool($current['allow_tenant_customization'])) {
            $errors['allow_tenant_customization'][] = 'The allow_tenant_customization field must be true or false.';
        }

        $this->validateSupportedMethods(
            $current['available_methods'] ?? [],
            'available_methods',
            $errors
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guardTenantPatch(array $payload, SettingsNamespaceDefinition $definition): void
    {
        if ($payload === []) {
            return;
        }

        $landlord = $this->landlordCurrentConfig();
        $allowCustomization = $this->resolver->resolve($landlord, $this->tenantCurrentConfig())['allow_tenant_customization'];

        if (! $allowCustomization) {
            throw ValidationException::withMessages([
                'enabled_methods' => ['Tenant customization is disabled by landlord governance.'],
            ]);
        }

        $current = $this->tenantCurrentConfig();
        foreach ($payload as $path => $value) {
            Arr::set($current, $path, $value);
        }

        $errors = [];
        $enabledMethods = $this->resolver->normalizeMethods($current['enabled_methods'] ?? []);
        $this->validateSupportedMethods(
            $current['enabled_methods'] ?? [],
            'enabled_methods',
            $errors
        );

        $landlordAvailable = $this->resolver->normalizeMethods($landlord['available_methods'] ?? []);
        $disallowed = array_values(array_diff($enabledMethods, $landlordAvailable));
        if ($disallowed !== []) {
            $errors['enabled_methods'][] = sprintf(
                'Tenant enabled methods must be a subset of landlord-available methods. Invalid: %s.',
                implode(', ', $disallowed)
            );
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validateSupportedMethods(mixed $rawMethods, string $field, array &$errors): void
    {
        if (! is_array($rawMethods)) {
            $errors[$field][] = 'The tenant-public auth method list must be an array.';

            return;
        }

        if ($rawMethods === []) {
            return;
        }

        foreach ($rawMethods as $method) {
            if (! is_string($method) || trim($method) === '') {
                $errors[$field][] = 'The tenant-public auth method list may only contain non-empty strings.';
                break;
            }

            $normalized = strtolower(trim($method));
            if (! in_array($normalized, $this->resolver->allowedMethodsCatalog(), true)) {
                $errors[$field][] = sprintf('Unsupported tenant-public auth method [%s].', $method);
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function landlordCurrentConfig(): array
    {
        $settings = LandlordSettings::current();
        $value = $settings?->getAttribute('tenant_public_auth');

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantCurrentConfig(): array
    {
        $settings = TenantSettings::current();
        $value = $settings?->getAttribute('tenant_public_auth');

        return is_array($value) ? $value : [];
    }
}
