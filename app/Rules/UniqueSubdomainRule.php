<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueSubdomainRule implements ValidationRule
{
    protected ?string $tenant_slug;

    public function __construct(?string $exclude_slug = null)
    {
        $this->tenant_slug = $exclude_slug;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = DB::connection('landlord')
            ->table('tenants')
            ->where('subdomain', strtolower($value));

        if ($this->tenant_slug) {
            $query->where('slug', '!=', $this->tenant_slug);
        }

        $exists = $query->exists();

        if ($exists) {
            $fail('The subdomain has already been taken');
        }
    }
}
