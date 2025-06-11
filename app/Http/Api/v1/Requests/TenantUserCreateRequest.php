<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\UniqueArrayItemRule;
use Illuminate\Foundation\Http\FormRequest;

class TenantUserCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'emails' => [
                'required',
                'array',
                new UniqueArrayItemRule('tenant', 'users', 'emails', )
            ],
            'password' => 'required|string|min:8',
            'role_id' => 'required|string|exists:tenant.roles,_id'
        ];
    }
}
