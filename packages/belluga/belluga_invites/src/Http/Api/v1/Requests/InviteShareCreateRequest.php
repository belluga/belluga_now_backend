<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteShareCreateRequest extends FormRequest
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
            'target_ref' => ['required', 'array'],
            'target_ref.event_id' => ['required', 'string', 'max:255'],
            'target_ref.occurrence_id' => ['required', 'string', 'max:255'],
            'account_profile_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
