<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountStoreRequest extends FormRequest
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
            'name' => 'required|string|max:'.InputConstraints::NAME_MAX,
            'document' => 'sometimes|array',
            'document.type' => 'required_with:document|string|in:cpf,cnpj',
            'document.number' => 'required_with:document|string|max:'.InputConstraints::NAME_MAX,
            'ownership_state' => 'required|string|in:tenant_owned,unmanaged',
            'organization_id' => 'sometimes|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome do tenant é obrigatório',
            'document.array' => 'O documento deve ser um objeto',
            'document.type.required_with' => 'O tipo do documento é obrigatório quando documento for enviado',
            'document.type.in' => 'O tipo do documento deve ser cpf ou cnpj',
            'document.number.required_with' => 'O número do documento é obrigatório quando documento for enviado',
            'document.number.max' => 'O número do documento não pode ter mais que :max caracteres',
            'ownership_state.required' => 'O ownership_state é obrigatório',
            'ownership_state.in' => 'O ownership_state deve ser tenant_owned ou unmanaged',
        ];
    }
}
