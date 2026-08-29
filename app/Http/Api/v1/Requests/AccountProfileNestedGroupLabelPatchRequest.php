<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

final class AccountProfileNestedGroupLabelPatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        $label = $this->input('label');
        if (is_string($label)) {
            $this->merge(['label' => trim($label)]);
        }
    }

    public function rules(): array
    {
        return ['label' => ['bail', 'required', 'string', 'max:'.InputConstraints::NAME_MAX]];
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }
}
