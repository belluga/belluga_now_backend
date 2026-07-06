<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Application\Tenants\TenantAppDomainResolverService;
use App\Models\Landlord\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EnvironmentRequest extends FormRequest
{
    private ?Tenant $resolvedAppDomainTenant = null;

    public function validationData(): array
    {
        $headerAppDomain = $this->header('X-App-Domain');

        if (is_string($headerAppDomain) && $headerAppDomain !== '') {
            return ['app_domain' => $headerAppDomain];
        }

        return [];
    }

    public function rules(): array
    {
        return [
            'app_domain' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function resolvedAppDomainTenant(): ?Tenant
    {
        return $this->resolvedAppDomainTenant;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $appDomain = $this->validated('app_domain');
            if (! is_string($appDomain) || trim($appDomain) === '') {
                return;
            }

            $resolver = app(TenantAppDomainResolverService::class);
            $tenant = $resolver->findTenantByIdentifier($appDomain);
            if ($tenant !== null) {
                $this->resolvedAppDomainTenant = $tenant;
                return;
            }

            $validator->errors()->add('app_domain', 'Unknown app_domain.');
        });
    }
}
