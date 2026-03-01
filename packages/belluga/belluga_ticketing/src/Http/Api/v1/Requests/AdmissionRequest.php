<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdmissionRequest extends FormRequest
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
            'idempotency_key' => ['required', 'string', 'max:255'],
            'checkout_mode' => ['nullable', 'string', 'in:free,paid'],
            'queue_token' => ['nullable', 'string', 'max:255'],
            'promotion_codes' => ['nullable', 'array'],
            'promotion_codes.*' => ['required_with:promotion_codes', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ticket_product_id' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
