<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;

final class AccountProfilePublicFilterRules
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public static function commonRules(): array
    {
        return [
            'search' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'profile_type' => ['sometimes', self::stringOrStringListRule()],
            'filter' => 'sometimes|array:profile_type,taxonomy',
            'filter.profile_type' => ['sometimes', self::stringOrStringListRule()],
            'taxonomy' => 'sometimes|array|max:'.InputConstraints::DISCOVERY_FILTER_PUBLIC_TAXONOMY_FILTERS_MAX,
            'taxonomy.*.type' => 'required_with:taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy.*.value' => 'required_with:taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'filter.taxonomy' => 'sometimes|array|max:'.InputConstraints::DISCOVERY_FILTER_PUBLIC_TAXONOMY_FILTERS_MAX,
            'filter.taxonomy.*.type' => 'required_with:filter.taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'filter.taxonomy.*.value' => 'required_with:filter.taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'page' => 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_MAX,
            'page_size' => 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_SIZE_MAX,
        ];
    }

    public static function stringOrStringListRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $values = is_array($value) ? $value : [$value];
            if (count($values) > InputConstraints::DISCOVERY_FILTER_TYPE_OPTIONS_MAX) {
                $fail("The {$attribute} field has too many values.");

                return;
            }

            foreach ($values as $item) {
                if (! is_string($item) || trim($item) === '' || mb_strlen($item) > InputConstraints::NAME_MAX) {
                    $fail("The {$attribute} field must be a string or list of strings.");

                    return;
                }
            }
        };
    }
}
