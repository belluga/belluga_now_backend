<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'subject' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
            'secret' => [
                'nullable',
                'string',
                'min:' . InputConstraints::PASSWORD_MIN,
                'max:' . InputConstraints::PASSWORD_MAX,
            ],
            'metadata' => ['nullable', 'array', 'max:' . InputConstraints::METADATA_MAX_ITEMS],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes(
            'subject',
            'email|max:' . InputConstraints::EMAIL_MAX,
            static function ($input): bool {
                return ($input->provider ?? null) === 'password';
            }
        );
    }
}

