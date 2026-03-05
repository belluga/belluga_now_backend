<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hold_token' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'checkout_mode' => ['nullable', 'string', 'in:free,paid'],
            'account_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
