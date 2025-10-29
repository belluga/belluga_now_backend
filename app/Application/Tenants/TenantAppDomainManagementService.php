<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Tenant;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class TenantAppDomainManagementService
{
    /**
     * @return array<int, string>
     */
    public function list(Tenant $tenant): array
    {
        return array_values($tenant->app_domains ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function add(Tenant $tenant, string $domain): array
    {
        $domains = $tenant->app_domains ?? [];

        if (in_array($domain, $domains, true)) {
            throw ValidationException::withMessages([
                'app_domain' => ['App domain already exists for this tenant.'],
            ]);
        }

        $tenant->app_domains = array_values([...$domains, $domain]);

        try {
            $tenant->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'app_domain' => ['Another tenant already uses this app domain.'],
                ]);
            }

            throw ValidationException::withMessages([
                'app_domain' => ['Unable to add app domain right now.'],
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'app_domain' => ['Unable to add app domain right now.'],
            ]);
        }

        return array_values($tenant->app_domains ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function remove(Tenant $tenant, string $domain): array
    {
        $domains = $tenant->app_domains ?? [];

        if (! in_array($domain, $domains, true)) {
            throw ValidationException::withMessages([
                'app_domain' => ['App domain not found for this tenant.'],
            ]);
        }

        $tenant->app_domains = array_values(array_filter(
            $domains,
            static fn (string $existing): bool => $existing !== $domain
        ));

        try {
            $tenant->save();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'app_domain' => ['Unable to remove app domain right now.'],
            ]);
        }

        return array_values($tenant->app_domains ?? []);
    }
}

