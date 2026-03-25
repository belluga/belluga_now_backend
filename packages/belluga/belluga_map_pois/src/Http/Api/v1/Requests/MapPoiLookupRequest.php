<?php

declare(strict_types=1);

namespace Belluga\MapPois\Http\Api\v1\Requests;

use Belluga\MapPois\Support\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapPoiLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ref_type' => [
                'required',
                'string',
                'max:'.InputConstraints::NAME_MAX,
                Rule::in(['event', 'account_profile', 'account', 'static', 'static_asset', 'asset']),
            ],
            'ref_id' => 'required|string|max:'.InputConstraints::NAME_MAX,
        ];
    }
}
