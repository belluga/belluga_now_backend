<?php

declare(strict_types=1);

namespace App\Application\Organizations;

use App\Models\Tenants\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class OrganizationManagementService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Organization
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($payload): Organization {
                return Organization::create($payload)->fresh();
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'organization' => ['Organization already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'organization' => ['Something went wrong when trying to create the organization.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Organization $organization, array $attributes): Organization
    {
        try {
            $organization->fill($attributes);
            $organization->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Organization slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'organization' => ['Something went wrong when trying to update the organization.'],
            ]);
        }

        return $organization->fresh();
    }

    public function delete(Organization $organization): void
    {
        $organization->delete();
    }

    public function restore(Organization $organization): Organization
    {
        $organization->restore();

        return $organization->fresh();
    }

    public function forceDelete(Organization $organization): void
    {
        $organization->forceDelete();
    }
}
