<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UnitValidateRequest extends FormRequest
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
            'checkpoint_ref' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'ticket_unit_id' => ['nullable', 'string', 'max:255'],
            'admission_code' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unitId = (string) ($this->input('ticket_unit_id') ?? '');
            $admissionCode = (string) ($this->input('admission_code') ?? '');

            if ($unitId === '' && $admissionCode === '') {
                $validator->errors()->add('ticket_unit_id', 'ticket_unit_id or admission_code is required.');
            }
        });
    }
}
