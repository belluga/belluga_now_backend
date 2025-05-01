<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueSubdomainRule implements ValidationRule
{
    protected ?string $tenant_slug;

    public function __construct(?string $tenant_slug = null)
    {
        $this->tenant_slug = $tenant_slug;
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
            $fail('Este subdomínio já está em uso.');
        }
    }
}
