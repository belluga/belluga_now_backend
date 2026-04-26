<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;

class TenantPublicAuthMethodResolver
{
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
        return $this->resolve(
            $this->rawLandlordSettings(),
            []
        );
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
            return $availableMethods;
        }

        if ($enabledMethods === []) {
            return $availableMethods;
        }

        $subset = [];
        foreach ($enabledMethods as $method) {
            if (in_array($method, $availableMethods, true)) {
                $subset[$method] = $method;
            }
        }

        return array_values($subset !== [] ? $subset : $availableMethods);
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
