<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TokenRefreshRequest extends FormRequest
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
            'queue_token' => ['nullable', 'string', 'max:255'],
            'hold_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $queueToken = (string) ($this->input('queue_token') ?? '');
            $holdToken = (string) ($this->input('hold_token') ?? '');

            if ($queueToken === '' && $holdToken === '') {
                $validator->errors()->add('queue_token', 'queue_token or hold_token is required.');
            }
        });
    }
}
