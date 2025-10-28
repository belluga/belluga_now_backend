<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountUserAttachRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return array_merge($this->all(), [
            'user_id' => $this->route('user_id'),
            'role_id' => $this->route('role_id'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'string',
                'size:' . InputConstraints::OBJECT_ID_LENGTH,
                'regex:/^[a-fA-F0-9]{24}$/',
                'exists:tenant.account_users,_id',
            ],
            'role_id' => [
                'required',
                'string',
                'size:' . InputConstraints::OBJECT_ID_LENGTH,
                'regex:/^[a-fA-F0-9]{24}$/',
                'exists:tenant.account_role_templates,_id',
            ],
        ];
    }
}
