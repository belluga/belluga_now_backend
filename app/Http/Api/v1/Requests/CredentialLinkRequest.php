
<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CredentialLinkRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:50'],
            'subject' => ['required', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'min:8'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(): void
    {
        ->sometimes('subject', 'email', function () {
            return ->provider === 'password';
        });
    }
}
