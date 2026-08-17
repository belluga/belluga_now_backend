<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Http\Api\v1\Requests\Concerns\ValidatesAccountProfileContactChannels;
use App\Http\Api\v1\Requests\Concerns\ValidatesAccountProfileRichText;
use App\Support\Validation\InputConstraints;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AccountOnboardingStoreRequest extends FormRequest
{
    use ValidatesAccountProfileContactChannels;
    use ValidatesAccountProfileRichText;

    private const MIN_VISIBLE_PUBLIC_NAME_LENGTH = 3;

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
            'name' => $this->publicVisibleNameRules(required: true),
            'ownership_state' => 'required|string|in:tenant_owned,unmanaged',
            'profile_type' => 'required|string|max:'.InputConstraints::NAME_MAX,
            'location' => 'sometimes|array',
            'location.lat' => 'required_with:location.lng|numeric',
            'location.lng' => 'required_with:location.lat|numeric',
            'taxonomy_terms' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'nested_profile_groups' => 'prohibited',
            'nested_profile_groups.*' => 'prohibited',
            'bio' => $this->optionalAccountProfileRichTextRule(),
            'content' => $this->optionalAccountProfileRichTextRule(),
            'avatar' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB,
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB,
            ...$this->accountProfileContactChannelRules(),
        ];
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string|Closure>
     */
    private function publicVisibleNameRules(bool $required): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'string',
            'max:'.InputConstraints::NAME_MAX,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $trimmed = trim($value);
                if ($trimmed === '' || mb_strlen($trimmed) < self::MIN_VISIBLE_PUBLIC_NAME_LENGTH) {
                    $fail("The {$attribute} must be at least ".self::MIN_VISIBLE_PUBLIC_NAME_LENGTH.' visible characters.');
                }
            },
        ];
    }
}
