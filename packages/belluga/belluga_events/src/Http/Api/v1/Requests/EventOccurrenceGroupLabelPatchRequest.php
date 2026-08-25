<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

final class EventOccurrenceGroupLabelPatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        $this->merge(['label' => trim((string) $this->input('label'))]);
    }

    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX]];
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }
}
