<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountUpdateRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'document' => 'sometimes|array',
            'document.type' => 'required_with:document.number|string|in:cpf,cnpj',
            'document.number' => 'required_with:document.type|string|max:255',
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
            'document.type.required' => 'O tipo do documento é obrigatório',
            'document.type.in' => 'O tipo do documento deve ser cpf ou cnpj',
            'document.number.required' => 'O número do documento é obrigatório',
            'document.number.max' => 'O número do documento não pode ter mais que :max caracteres'
        ];
    }
}
