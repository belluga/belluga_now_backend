<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

final class AccountProfileExternalLinkUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:'.InputConstraints::ACCOUNT_PROFILE_EXTERNAL_LINK_URL_MAX],
            'label' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::NAME_MAX],
            'id' => ['prohibited'],
            'type' => ['prohibited'],
            'external_links' => ['prohibited'],
            'external_links_limit' => ['prohibited'],
        ];
    }
}
