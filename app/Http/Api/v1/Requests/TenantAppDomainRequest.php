<?php

namespace App\Http\Api\v1\Requests;

use App\Rules\UniqueArrayItemRule;
use Illuminate\Foundation\Http\FormRequest;

class TenantAppDomainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_domain' => [
                'required',
                'string',
                'regex:/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/',
                new UniqueArrayItemRule(
                    connection: 'tenant',table: 'tenants', key: 'app_domains',
                ),
            ],
        ];
    }
}
