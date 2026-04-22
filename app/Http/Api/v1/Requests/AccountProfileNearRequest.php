<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileNearRequest extends FormRequest
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
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'max_distance_meters' => 'sometimes|numeric|min:0',
            'search' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'profile_type' => ['sometimes', $this->stringOrStringListRule()],
            'filter' => 'sometimes|array',
            'filter.profile_type' => ['sometimes', $this->stringOrStringListRule()],
            'taxonomy' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy.*.type' => 'required_with:taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy.*.value' => 'required_with:taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'filter.taxonomy' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'filter.taxonomy.*.type' => 'required_with:filter.taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'filter.taxonomy.*.value' => 'required_with:filter.taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'page' => 'sometimes|integer|min:1',
            'page_size' => 'sometimes|integer|min:1|max:50',
        ];
    }

    private function stringOrStringListRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $values = is_array($value) ? $value : [$value];

            foreach ($values as $item) {
                if (! is_string($item) || trim($item) === '' || mb_strlen($item) > InputConstraints::NAME_MAX) {
                    $fail("The {$attribute} field must be a string or list of strings.");

                    return;
                }
            }
        };
    }
}
