<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class EventOccurrenceGroupOrderPatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['direction' => ['bail', 'required', 'string', Rule::in(['up', 'down'])]];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['direction']) as $key) {
                $validator->errors()->add((string) $key, 'The field is not allowed.');
            }
        });
    }

    public function direction(): string
    {
        return (string) $this->validated('direction');
    }
}
