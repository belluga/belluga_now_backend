<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AnonymousIdentityRequest extends FormRequest
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
            'device_name' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX],
            'fingerprint.hash' => ['required', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'fingerprint.user_agent' => ['nullable', 'string', 'max:1024'],
            'fingerprint.locale' => ['nullable', 'string', 'max:16'],
            'metadata' => ['nullable', 'array', 'max:'.InputConstraints::METADATA_MAX_ITEMS],
        ];
    }
}
