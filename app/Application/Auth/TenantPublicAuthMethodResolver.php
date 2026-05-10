<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;

class TenantPublicAuthMethodResolver
{
    private const FAIL_CLOSED_PRIMARY_METHOD = 'phone_otp';

    /**
     * @var array<int, string>
     */
    private const DEFAULT_AVAILABLE_METHODS = ['password', 'phone_otp'];

    /**
     * @return array{
     *   available_methods: array<int, string>,
     *   allow_tenant_customization: bool,
     *   enabled_methods: array<int, string>,
     *   effective_methods: array<int, string>,
     *   effective_primary_method: ?string
     * }
     */
    public function currentGovernance(): array
    {
        return $this->resolve(
            $this->rawLandlordSettings(),
            $this->rawTenantSettings()
        );
    }

    /**
     * @return array{
     *   available_methods: array<int, string>,
     *   allow_tenant_customization: bool,
     *   enabled_methods: array<int, string>,
     *   effective_methods: array<int, string>,
     *   effective_primary_method: ?string
     * }
     */
    public function currentLandlordGovernance(): array
    {
        return $this->resolveLandlordGovernance($this->rawLandlordSettings());
    }

    /**
     * @param  array<string, mixed>  $landlordRaw
     * @param  array<string, mixed>  $tenantRaw
     * @return array{
     *   available_methods: array<int, string>,
     *   allow_tenant_customization: bool,
     *   enabled_methods: array<int, string>,
     *   effective_methods: array<int, string>,
     *   effective_primary_method: ?string
     * }
     */
    public function resolve(array $landlordRaw, array $tenantRaw): array
    {
        $availableMethods = $this->normalizeMethods($landlordRaw['available_methods'] ?? self::DEFAULT_AVAILABLE_METHODS);
        if ($availableMethods === []) {
            $availableMethods = self::DEFAULT_AVAILABLE_METHODS;
        } else {
            $availableMethods = $this->ensureFailClosedPrimaryMethod($availableMethods);
        }

        $allowTenantCustomization = $this->normalizeBoolean(
            $landlordRaw['allow_tenant_customization'] ?? true,
            true
        );

        $enabledMethods = $this->normalizeMethods($tenantRaw['enabled_methods'] ?? []);
        $effectiveMethods = $this->resolveEffectiveMethods(
            availableMethods: $availableMethods,
            allowTenantCustomization: $allowTenantCustomization,
            enabledMethods: $enabledMethods
        );

        return [
            'available_methods' => $availableMethods,
            'allow_tenant_customization' => $allowTenantCustomization,
            'enabled_methods' => $enabledMethods,
            'effective_methods' => $effectiveMethods,
            'effective_primary_method' => $effectiveMethods[0] ?? null,
        ];
    }

    /**
     * Landlord-facing environment metadata advertises the configured catalog.
     * Tenant fail-closed collapse only applies when resolving tenant-public
     * runtime behavior against a tenant subset.
     *
     * @param  array<string, mixed>  $landlordRaw
     * @return array{
     *   available_methods: array<int, string>,
     *   allow_tenant_customization: bool,
     *   enabled_methods: array<int, string>,
     *   effective_methods: array<int, string>,
     *   effective_primary_method: ?string
     * }
     */
    private function resolveLandlordGovernance(array $landlordRaw): array
    {
        $availableMethods = $this->normalizeMethods($landlordRaw['available_methods'] ?? self::DEFAULT_AVAILABLE_METHODS);
        if ($availableMethods === []) {
            $availableMethods = self::DEFAULT_AVAILABLE_METHODS;
        } else {
            $availableMethods = $this->ensureFailClosedPrimaryMethod($availableMethods);
        }

        $allowTenantCustomization = $this->normalizeBoolean(
            $landlordRaw['allow_tenant_customization'] ?? true,
            true
        );

        return [
            'available_methods' => $availableMethods,
            'allow_tenant_customization' => $allowTenantCustomization,
            'enabled_methods' => $availableMethods,
            'effective_methods' => $availableMethods,
            'effective_primary_method' => $availableMethods[0] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function normalizeMethods(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $candidate = strtolower(trim($entry));
            if ($candidate === '' || ! in_array($candidate, self::DEFAULT_AVAILABLE_METHODS, true)) {
                continue;
            }

            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, string>
     */
    public function allowedMethodsCatalog(): array
    {
        return self::DEFAULT_AVAILABLE_METHODS;
    }

    public function failClosedPrimaryMethod(): string
    {
        return self::FAIL_CLOSED_PRIMARY_METHOD;
    }

    /**
     * @param  array<int, string>  $availableMethods
     * @param  array<int, string>  $enabledMethods
     * @return array<int, string>
     */
    private function resolveEffectiveMethods(
        array $availableMethods,
        bool $allowTenantCustomization,
        array $enabledMethods,
    ): array {
        if (! $allowTenantCustomization) {
            return $this->failClosedDefaultMethods($availableMethods);
        }

        if ($enabledMethods === []) {
            return $this->failClosedDefaultMethods($availableMethods);
        }

        $subset = [];
        foreach ($enabledMethods as $method) {
            if (in_array($method, $availableMethods, true)) {
                $subset[$method] = $method;
            }
        }

        if ($subset === []) {
            return $this->failClosedDefaultMethods($availableMethods);
        }

        return array_values($subset);
    }

    /**
     * @param  array<int, string>  $availableMethods
     * @return array<int, string>
     */
    private function failClosedDefaultMethods(array $availableMethods): array
    {
        return [self::FAIL_CLOSED_PRIMARY_METHOD];
    }

    /**
     * @param  array<int, string>  $methods
     * @return array<int, string>
     */
    private function ensureFailClosedPrimaryMethod(array $methods): array
    {
        if (in_array(self::FAIL_CLOSED_PRIMARY_METHOD, $methods, true)) {
            return $methods;
        }

        $methods[] = self::FAIL_CLOSED_PRIMARY_METHOD;

        return array_values(array_unique($methods));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawLandlordSettings(): array
    {
        $settings = LandlordSettings::current();
        $value = $settings?->getAttribute('tenant_public_auth');

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawTenantSettings(): array
    {
        $settings = TenantSettings::current();
        $value = $settings?->getAttribute('tenant_public_auth');

        return is_array($value) ? $value : [];
    }

    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }

            return filter_var($normalized, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
