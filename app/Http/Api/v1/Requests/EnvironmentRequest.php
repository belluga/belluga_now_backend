<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Application\Tenants\TenantAppDomainResolverService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EnvironmentRequest extends FormRequest
{
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $appDomain = $this->validated('app_domain');
            if (! is_string($appDomain) || trim($appDomain) === '') {
                return;
            }

            $resolver = app(TenantAppDomainResolverService::class);
            if ($resolver->findTenantByIdentifier($appDomain) !== null) {
                return;
            }

            $validator->errors()->add('app_domain', 'Unknown app_domain.');
        });
    }
}
