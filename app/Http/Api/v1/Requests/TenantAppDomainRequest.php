<?php

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
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
        if ($this->isMethod('DELETE')) {
            return [
                'platform' => [
                    'required_without:app_domain',
                    'string',
                    'in:android,ios',
                ],
                'app_domain' => [
                    'sometimes',
                    'string',
                    'max:'.InputConstraints::NAME_MAX,
                ],
            ];
        }

        return [
            'platform' => [
                'required_without:app_domain',
                'string',
                'in:android,ios',
            ],
            'identifier' => [
                'required_without:app_domain',
                'string',
                'max:'.InputConstraints::NAME_MAX,
            ],
            // Legacy alias kept during transition; interpreted as Android identifier.
            'app_domain' => [
                'sometimes',
                'string',
                'max:'.InputConstraints::NAME_MAX,
            ],
        ];
    }
}
