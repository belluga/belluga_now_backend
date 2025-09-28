<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\InArrayItemRule;
use Illuminate\Foundation\Http\FormRequest;

class EnvironmentRequest extends FormRequest
{

    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        return [
            "app_domain" => new InArrayItemRule(
                connection: 'landlord',
                table: 'tenants',
                key: 'app_domains',
                shouldExist: false
            ),
            "domain" => new InArrayItemRule(
                connection: 'landlord',
                table: 'tenants',
                key: 'domains',
                shouldExist: false
            ),
            "subdomain" => new InArrayItemRule(
                connection: 'landlord',
                table: 'tenants',
                key: 'subdomain',
                shouldExist: false
            ),
        ];
    }
}
