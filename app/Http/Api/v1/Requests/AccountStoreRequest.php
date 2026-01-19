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
            'name' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'document' => 'required|array',
            'document.type' => 'required|string|in:cpf,cnpj',
            'document.number' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'organization_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
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
            'document.required' => 'O documento é obrigatório',
            'document.array' => 'O documento deve ser um objeto',
            'document.type.required' => 'O tipo do documento é obrigatório',
            'document.type.in' => 'O tipo do documento deve ser cpf ou cnpj',
            'document.number.required' => 'O número do documento é obrigatório',
            'document.number.max' => 'O número do documento não pode ter mais que :max caracteres'
        ];
    }
}
