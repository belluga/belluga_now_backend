<?php

declare(strict_types=1);

namespace Belluga\Email\Http\Requests;

use Belluga\Email\Support\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantEmailSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? trim((string) $this->input('email')) : $this->input('email'),
            'whatsapp' => is_string($this->input('whatsapp'))
                ? preg_replace('/\D+/', '', (string) $this->input('whatsapp'))
                : $this->input('whatsapp'),
            'os' => is_string($this->input('os')) ? trim((string) $this->input('os')) : $this->input('os'),
            'app_name' => is_string($this->input('app_name')) ? trim((string) $this->input('app_name')) : $this->input('app_name'),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:'.InputConstraints::EMAIL_MAX],
            'whatsapp' => ['required', 'string', 'regex:/^\d{10,15}$/'],
            'os' => ['required', 'string', Rule::in(['Android', 'iOS'])],
            'app_name' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
        ];
    }
}
