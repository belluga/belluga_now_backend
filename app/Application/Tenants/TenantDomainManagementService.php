<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class TenantDomainManagementService
{
    public function list(Tenant $tenant, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $resolvedPerPage = max(1, min($perPage, 100));
        $resolvedPage = max(1, $page);

        return $tenant->domains()
            ->where('type', Tenant::DOMAIN_TYPE_WEB)
            ->orderByDesc('created_at')
            ->orderByDesc('_id')
            ->paginate($resolvedPerPage, ['*'], 'page', $resolvedPage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Tenant $tenant, array $payload): Domains
    {
        $path = $this->normalizePath((string) $payload['path']);
        $type = (string) ($payload['type'] ?? Tenant::DOMAIN_TYPE_WEB);

        if ($this->tenantHasDomain($tenant, $path)) {
            throw ValidationException::withMessages([
                'path' => ['Domain already exists for this tenant.'],
            ]);
        }

        try {
            return DB::connection('landlord')->transaction(function () use ($tenant, $path, $type): Domains {
                $domain = $tenant->domains()->create([
                    'path' => $path,
                    'type' => $type,
                ]);

                return $domain->fresh();
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'path' => ['Another tenant already uses this domain.'],
                ]);
            }

            throw ValidationException::withMessages([
                'path' => ['Unable to create domain right now.'],
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'path' => ['Unable to create domain right now.'],
            ]);
        }
    }

    public function restore(Tenant $tenant, string $domainId): Domains
    {
        $domain = $tenant->domains()
            ->onlyTrashed()
            ->where('_id', new ObjectId($domainId))
            ->firstOrFail();

        try {
            return DB::connection('landlord')->transaction(function () use ($domain): Domains {
                $domain->restore();

                return $domain->fresh();
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'path' => ['Another tenant already uses this domain.'],
                ]);
            }

            throw ValidationException::withMessages([
                'path' => ['Unable to restore domain right now.'],
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'path' => ['Unable to restore domain right now.'],
            ]);
        }
    }

    public function delete(Tenant $tenant, string $domainId): void
    {
        $domain = $this->findActive($tenant, $domainId);

        DB::connection('landlord')->transaction(function () use ($domain): void {
            $domain->delete();
        });
    }

    public function forceDelete(Tenant $tenant, string $domainId): void
    {
        $domain = $tenant->domains()
            ->onlyTrashed()
            ->where('_id', new ObjectId($domainId))
            ->firstOrFail();

        DB::connection('landlord')->transaction(static function () use ($domain): void {
            $domain->forceDelete();
        });
    }

    private function findActive(Tenant $tenant, string $domainId): Domains
    {
        return $tenant->domains()
            ->where('_id', new ObjectId($domainId))
            ->firstOrFail();
    }

    private function tenantHasDomain(Tenant $tenant, string $path): bool
    {
        return $tenant->domains()
            ->withTrashed()
            ->where('path', $path)
            ->exists();
    }

    private function normalizePath(string $path): string
    {
        return strtolower(trim($path));
    }
}
