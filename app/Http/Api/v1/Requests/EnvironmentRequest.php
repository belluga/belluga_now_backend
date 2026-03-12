<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\InArrayItemRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'app_domain' => new InArrayItemRule(
                connection: 'landlord',
                table: 'tenants',
                key: 'app_domains',
                shouldExist: false
            ),
        ];
    }
}
