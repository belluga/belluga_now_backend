<?php

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string'],
            'user_id' => ['nullable', 'string'],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'numeric'],
            'description' => ['required'],
        ];
    }
}
