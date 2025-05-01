<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueSubdomainRule implements ValidationRule
{
    protected ?string $tenantId;

    public function __construct(?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = DB::connection('landlord')
            ->table('tenants')
            ->where('subdomain', strtolower($value));

        // Se tiver um tenant_id, ignora este na validação
        if ($this->tenantId) {
            $query->where('_id', '!=', $this->tenantId);
        }

        $exists = $query->exists();

        if ($exists) {
            $fail('Este subdomínio já está em uso.');
        }
    }
}
